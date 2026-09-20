<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\Motorisation;
use App\Models\VehicleModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Importe les cotes de consommation officielles de Ressources naturelles
 * Canada.
 *
 * Pourquoi le Canada plutot que l'Europe : les cotes canadiennes sont deja en
 * l/100 km, remontent a 1995, et decrivent le marche nord-americain — celui
 * d'ou vient une large part du parc burkinabe. Une mesure faite sur le
 * catalogue a montre que la source francaise (ADEME) n'appariait que 8 % des
 * fiches : elle s'arrete en 2015, exclut les utilitaires, et ignore les
 * modeles vendus hors d'Europe.
 *
 * Le Canada ne couvre pas tout non plus — ni Hilux, ni Land Cruiser, ni Prado,
 * ni Hiace, ni D-Max, absents du marche nord-americain. Deux autres sources
 * viendront combler ces trous ; c'est pourquoi chaque ligne porte le nom de la
 * sienne.
 *
 * Donnees publiees sous licence du gouvernement ouvert — Canada.
 */
class ImportConsommations extends Command
{
    protected $signature = 'catalog:import-consommations
                            {--fichier=* : Fichier CSV local, au lieu du telechargement}
                            {--dry-run : Analyser sans rien ecrire}
                            {--relier-seulement : Ne pas importer, seulement rattacher au catalogue}';

    protected $description = 'Importe les cotes de consommation officielles (Ressources naturelles Canada)';

    /** Jeu de donnees « Cotes de consommation de carburant » sur open.canada.ca. */
    private const CATALOGUE_CKAN = 'https://open.canada.ca/data/api/3/action/package_show?id=98f1a129-f628-4ce4-b24d-6f16bf24dd64';

    public function handle(): int
    {
        if ($this->option('relier-seulement')) {
            return $this->relier();
        }

        $sources = $this->option('fichier')
            ? $this->depuisFichiers($this->option('fichier'))
            : $this->depuisCanada();

        if ($sources === []) {
            $this->error('Aucune source de donnees exploitable.');

            return self::FAILURE;
        }

        $total = ['lues' => 0, 'retenues' => 0, 'ignorees' => 0];

        foreach ($sources as $nom => $contenu) {
            $this->info("Lecture de $nom");
            $lignes = $this->analyser($contenu, $total);

            if ($this->option('dry-run')) {
                $this->line(sprintf('  %d lignes exploitables (rien n\'est ecrit)', count($lignes)));

                continue;
            }

            foreach (array_chunk($lignes, 500) as $paquet) {
                Motorisation::upsert(
                    $paquet,
                    ['source', 'model_year', 'make_raw', 'model_raw', 'engine_l', 'cylinders', 'transmission_code', 'fuel_code'],
                    ['vehicle_class', 'consumption_city', 'consumption_highway', 'consumption_combined', 'co2_g_km', 'cycle'],
                );
            }
            $this->line(sprintf('  %d lignes enregistrees', count($lignes)));
        }

        $this->newLine();
        $this->line(sprintf(
            'Lignes lues : %d — retenues : %d — ignorees : %d',
            $total['lues'], $total['retenues'], $total['ignorees']
        ));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        return $this->relier();
    }

    // ── Sources ──────────────────────────────────────────────────────

    /** @param array<int, string> $chemins */
    private function depuisFichiers(array $chemins): array
    {
        $sources = [];

        foreach ($chemins as $chemin) {
            if (! is_readable($chemin)) {
                $this->warn("Fichier illisible, ignore : $chemin");

                continue;
            }
            $sources[basename($chemin)] = file_get_contents($chemin);
        }

        return $sources;
    }

    /**
     * Resout les fichiers via le catalogue ouvert plutot que de figer des URL.
     *
     * Ressources naturelles Canada republie chaque annee, et les adresses
     * changent : une URL en dur se serait tue silencieusement au prochain
     * millesime.
     */
    private function depuisCanada(): array
    {
        $reponse = Http::timeout(60)->get(self::CATALOGUE_CKAN);

        if (! $reponse->successful()) {
            $this->error('Le catalogue open.canada.ca ne repond pas ('.$reponse->status().').');

            return [];
        }

        $adresses = [];
        foreach ($reponse->json('result.resources') ?? [] as $ressource) {
            $url = $ressource['url'] ?? '';

            // Les fichiers francais portent les memes donnees sous des
            // en-tetes traduits ; on s'en tient a la version anglaise.
            // Les deux-cycles sont l'ancienne methode, moins realiste, et
            // les vehicules electriques ont d'autres colonnes (kWh).
            if (! str_ends_with($url, '.csv')) {
                continue;
            }
            if (! str_contains($url, 'fuel-consumption-ratings')) {
                continue;
            }
            if (str_contains($url, 'original-') || str_contains($url, '2-cycle')) {
                continue;
            }
            $adresses[basename($url)] = $url;
        }

        if ($adresses === []) {
            $this->error('Aucun fichier de cotes trouve dans le catalogue.');

            return [];
        }

        $sources = [];
        foreach ($adresses as $nom => $url) {
            $fichier = Http::timeout(180)->get($url);

            if (! $fichier->successful()) {
                $this->warn("Telechargement echoue, ignore : $nom");

                continue;
            }
            $sources[$nom] = $fichier->body();
        }

        return $sources;
    }

    // ── Analyse ──────────────────────────────────────────────────────

    /**
     * @param  array{lues: int, retenues: int, ignorees: int}  $total
     * @return array<int, array<string, mixed>>
     */
    private function analyser(string $contenu, array &$total): array
    {
        $contenu = preg_replace('/^\xEF\xBB\xBF/', '', $contenu);

        // Les fichiers annuels sont publies en ISO-8859-1 — « A5 Coupé » y
        // tient l'accent sur un seul octet, que MySQL en utf8mb4 refuse avec
        // « Incorrect string value ». Le fichier 1995-2014, lui, est deja en
        // UTF-8 : on ne convertit donc que ce qui n'est pas valide, sinon on
        // abimerait les accents deja corrects.
        if (! mb_check_encoding($contenu, 'UTF-8')) {
            $contenu = mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
        }

        $flux = fopen('php://memory', 'r+');
        fwrite($flux, $contenu);
        rewind($flux);

        $entete = fgetcsv($flux);
        if (! $entete) {
            fclose($flux);

            return [];
        }

        $colonne = [];
        foreach ($entete as $position => $nom) {
            $colonne[$this->cleColonne((string) $nom)] = $position;
        }

        $indispensables = ['modelyear', 'make', 'model'];
        foreach ($indispensables as $attendue) {
            if (! isset($colonne[$attendue])) {
                $this->warn('  En-tete inattendu, fichier ignore.');
                fclose($flux);

                return [];
            }
        }

        $lignes = [];
        $maintenant = now();

        while (($l = fgetcsv($flux)) !== false) {
            $total['lues']++;

            $annee = $l[$colonne['modelyear']] ?? '';
            if (! ctype_digit(trim((string) $annee))) {
                $total['ignorees']++;

                continue;   // ligne de notes en fin de fichier
            }

            $mixte = $this->nombre($l, $colonne, 'combinedl100km');
            if ($mixte === null) {
                $total['ignorees']++;

                continue;   // sans cote mixte, la ligne n'apprend rien
            }

            $total['retenues']++;
            $lignes[] = [
                'source'               => 'nrcan',
                'cycle'                => '5-cycle',
                'model_year'           => (int) $annee,
                'make_raw'             => trim((string) ($l[$colonne['make']] ?? '')),
                'model_raw'            => trim((string) ($l[$colonne['model']] ?? '')),
                'vehicle_class'        => $this->texte($l, $colonne, 'vehicleclass'),
                'engine_l'             => $this->nombre($l, $colonne, 'enginesizel'),
                'cylinders'            => $this->entier($l, $colonne, 'cylinders'),
                'transmission_code'    => $this->texte($l, $colonne, 'transmission'),
                'fuel_code'            => $this->texte($l, $colonne, 'fueltype'),
                'consumption_city'     => $this->nombre($l, $colonne, 'cityl100km'),
                'consumption_highway'  => $this->nombre($l, $colonne, 'highwayl100km'),
                'consumption_combined' => $mixte,
                'co2_g_km'             => $this->entier($l, $colonne, 'co2emissionsgkm'),
                'created_at'           => $maintenant,
                'updated_at'           => $maintenant,
            ];
        }

        fclose($flux);

        return $lignes;
    }

    /** « City (L/100 km) » et « City (L/100km) » doivent designer la meme colonne. */
    private function cleColonne(string $nom): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($nom));
    }

    private function texte(array $ligne, array $colonne, string $cle): ?string
    {
        $valeur = isset($colonne[$cle]) ? trim((string) ($ligne[$colonne[$cle]] ?? '')) : '';

        return ($valeur === '' || strcasecmp($valeur, 'n/a') === 0) ? null : $valeur;
    }

    private function nombre(array $ligne, array $colonne, string $cle): ?float
    {
        $valeur = $this->texte($ligne, $colonne, $cle);

        return ($valeur === null || ! is_numeric(str_replace(',', '.', $valeur)))
            ? null
            : (float) str_replace(',', '.', $valeur);
    }

    private function entier(array $ligne, array $colonne, string $cle): ?int
    {
        $valeur = $this->nombre($ligne, $colonne, $cle);

        return $valeur === null ? null : (int) round($valeur);
    }

    // ── Rattachement au catalogue ────────────────────────────────────

    /**
     * Relie les cotes importees aux marques et modeles du catalogue.
     *
     * La source ecrit « Camry Hybrid LE », « RAV4 AWD », « Land Cruiser 150 » :
     * le nom du modele est un prefixe, suivi de la variante. On retient donc le
     * modele connu le plus long qui prefixe le libelle — sans quoi « RAV4 » et
     * « RAV4 Limited AWD » se rattacheraient au petit bonheur.
     */
    private function relier(): int
    {
        $marques = Brand::query()->get(['id', 'name'])
            ->keyBy(fn (Brand $m) => Str::slug($m->name));

        if ($marques->isEmpty()) {
            $this->warn('Aucune marque au catalogue : rien a rattacher.');

            return self::SUCCESS;
        }

        $modelesParMarque = VehicleModel::query()
            ->get(['id', 'brand_id', 'name', 'production_start', 'production_end'])
            ->groupBy('brand_id');

        $rattachees = 0;
        $marquesVues = [];

        Motorisation::query()->orderBy('id')->chunkById(1000, function ($lot) use (
            $marques, $modelesParMarque, &$rattachees, &$marquesVues
        ) {
            foreach ($lot as $cote) {
                $marque = $marques->get(Str::slug($cote->make_raw));
                if (! $marque) {
                    continue;
                }
                $marquesVues[$marque->id] = true;

                $modeleId = $this->modeleLePlusProche(
                    $cote->model_raw,
                    $cote->model_year,
                    $modelesParMarque->get($marque->id) ?? collect()
                );

                $cote->forceFill([
                    'brand_id'         => $marque->id,
                    'vehicle_model_id' => $modeleId,
                ])->saveQuietly();

                if ($modeleId !== null) {
                    $rattachees++;
                }
            }
        });

        $total = Motorisation::query()->count();
        $this->newLine();
        $this->line(sprintf(
            'Rattachement : %d cotes sur %d reliees a un modele du catalogue (%d marques reconnues).',
            $rattachees, $total, count($marquesVues)
        ));

        $idsCouverts = Motorisation::query()->whereNotNull('vehicle_model_id')->distinct()->pluck('vehicle_model_id');

        $this->line(sprintf(
            'Catalogue : %d generations sur %d disposent d\'au moins une cote.',
            VehicleModel::query()->whereIn('id', $idsCouverts)->count(),
            VehicleModel::query()->count(),
        ));

        // Le decompte par nom de modele parle mieux que celui par generation :
        // c'est ce que l'utilisateur cherche dans l'application.
        $nomsCouverts = VehicleModel::query()->whereIn('id', $idsCouverts)
            ->distinct()->pluck('name')->map(fn ($n) => Str::slug($n))->unique();
        $nomsTotal = VehicleModel::query()->distinct()->pluck('name')->map(fn ($n) => Str::slug($n))->unique();

        $this->line(sprintf(
            '           %d noms de modeles sur %d.',
            $nomsCouverts->count(), $nomsTotal->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Le modele du catalogue qui correspond a ce libelle et a ce millesime.
     *
     * Le catalogue tient une ligne par generation : une Corolla y figure
     * autant de fois qu'elle a connu de refontes. Sans l'annee, toutes les
     * cotes de 1995 a 2026 se seraient rattachees a la meme generation, et les
     * autres seraient apparues « sans donnee » alors que la donnee existait.
     *
     * @param  \Illuminate\Support\Collection<int, VehicleModel>  $modeles
     */
    private function modeleLePlusProche(string $libelle, int $annee, $modeles): ?int
    {
        $cible      = Str::slug($libelle);
        $candidats  = [];
        $longueur   = 0;

        foreach ($modeles as $modele) {
            $nom = Str::slug($modele->name);
            if ($nom === '' || strlen($nom) < $longueur) {
                continue;
            }
            if ($cible !== $nom && ! str_starts_with($cible, $nom.'-')) {
                continue;
            }

            // Un nom plus long est plus precis : « RAV4 Prime » l'emporte sur
            // « RAV4 ». On repart alors de zero.
            if (strlen($nom) > $longueur) {
                $candidats = [];
                $longueur  = strlen($nom);
            }
            $candidats[] = $modele;
        }

        if ($candidats === []) {
            return null;
        }
        if (count($candidats) === 1) {
            return $candidats[0]->id;
        }

        // Plusieurs generations portent ce nom : celle qui etait produite
        // cette annee-la. Aucune ne convient ? On prefere ne rien affirmer.
        foreach ($candidats as $modele) {
            $debut = $modele->production_start;
            $fin   = $modele->production_end;

            if ($debut !== null && $annee < $debut) {
                continue;
            }
            if ($fin !== null && $annee > $fin) {
                continue;
            }
            if ($debut !== null || $fin !== null) {
                return $modele->id;
            }
        }

        return null;
    }
}
