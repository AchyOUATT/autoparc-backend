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
                            {--source=nrcan : nrcan (Canada) ou eea (Europe)}
                            {--fichier=* : Fichier CSV local, au lieu du telechargement}
                            {--modele=* : Limiter la source europeenne a ces modeles}
                            {--dry-run : Analyser sans rien ecrire}
                            {--relier-seulement : Ne pas importer, seulement rattacher au catalogue}';

    protected $description = 'Importe les cotes de consommation officielles (Canada, Europe)';

    /** Jeu de donnees « Cotes de consommation de carburant » sur open.canada.ca. */
    private const CATALOGUE_CKAN = 'https://open.canada.ca/data/api/3/action/package_show?id=98f1a129-f628-4ce4-b24d-6f16bf24dd64';

    /**
     * Noms de marque des sources qui ne sont pas ceux du catalogue.
     *
     * Le registre europeen ecrit tantot « VOLKSWAGEN », tantot « VW » — deux
     * cotes de Polo et trois de T-Roc sont restees orphelines pour cette seule
     * raison, alors que le modele existait au catalogue.
     */
    private const ALIAS_MARQUE = [
        'vw'                          => 'volkswagen',
        'volkswagen-nutzfahrzeuge'    => 'volkswagen',
        'mercedes-amg'                => 'mercedes-benz',
        'mercedes'                    => 'mercedes-benz',
        'bmw-i'                       => 'bmw',
    ];

    /**
     * Les noms sous lesquels cette marque peut figurer au catalogue.
     *
     * Le registre europeen empile parfois les appellations dans un seul champ
     * — « VOLKSWAGEN, VW » — et le catalogue n'en connait qu'une. On rend donc
     * chaque variante, a charge a l'appelant de retenir celle qu'il reconnait.
     *
     * @return array<int, string>
     */
    private static function slugsMarque(string $marque): array
    {
        $slugs = [];

        foreach (preg_split('/[,\/]+/', $marque) as $partie) {
            $slug = Str::slug(trim($partie));
            if ($slug === '') {
                continue;
            }
            $slugs[] = self::ALIAS_MARQUE[$slug] ?? $slug;
        }

        return array_values(array_unique($slugs));
    }

    /**
     * Empreinte d'une ligne de source, insensible aux valeurs nulles.
     *
     * L'unicite ne peut pas reposer sur les colonnes elles-memes : MySQL comme
     * PostgreSQL considerent deux NULL comme distincts, et une cylindree
     * inconnue aurait suffi a faire passer deux fois la meme ligne.
     */
    public static function empreinte(array $ligne): string
    {
        return md5(implode('|', [
            $ligne['source'],
            $ligne['model_year'],
            mb_strtolower(trim((string) $ligne['make_raw'])),
            mb_strtolower(trim((string) $ligne['model_raw'])),
            $ligne['engine_l'] ?? '',
            $ligne['cylinders'] ?? '',
            $ligne['transmission_code'] ?? '',
            $ligne['fuel_code'] ?? '',
        ]));
    }

    public function handle(): int
    {
        if ($this->option('relier-seulement')) {
            return $this->relier();
        }

        if ($this->option('source') === 'eea') {
            return $this->importerEurope();
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

            $this->line(sprintf('  %d lignes enregistrees', $this->enregistrer($lignes)));
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

    /**
     * Ecrit les lignes, en les reconnaissant a leur empreinte.
     *
     * @param  array<int, array<string, mixed>>  $lignes
     */
    private function enregistrer(array $lignes): int
    {
        // Une meme ligne peut figurer deux fois dans un fichier : 40 doublons
        // dans les editions canadiennes. On tranche ici plutot que de laisser
        // la base arbitrer, pour que le nombre annonce soit celui ecrit.
        $uniques = [];
        foreach ($lignes as $ligne) {
            $uniques[$ligne['cle_source']] = $ligne;
        }
        $lignes = array_values($uniques);

        foreach (array_chunk($lignes, 500) as $paquet) {
            Motorisation::upsert(
                $paquet,
                ['cle_source'],
                [
                    'vehicle_class', 'consumption_city', 'consumption_highway',
                    'consumption_combined', 'co2_g_km', 'cycle',
                ],
            );
        }

        return count($lignes);
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

    // ── Source europeenne ────────────────────────────────────────────

    /**
     * Service SQL de l'Agence europeenne pour l'environnement.
     *
     * Le registre CO2 europeen couvre ce que le Canada ignore : les
     * utilitaires legers de categorie N1, c'est-a-dire les pick-up. Hilux,
     * Ranger, D-Max, Navara, L200, Land Cruiser — l'ossature du parc
     * burkinabe, absente du marche nord-americain.
     *
     * La piste australienne, qui vend exactement ces vehicules, a ete
     * abandonnee : le Green Vehicle Guide ne publie aucun fichier libre et
     * renvoie vers un service tiers sur demande.
     */
    private const SQL_EEA = 'https://discodata.eea.europa.eu/sql';

    /**
     * Tables annuelles disponibles : co2cars_<annee>Pv<n>, ou n suit l'annee
     * de deux en deux. Seules les annees recentes sont publiees.
     */
    /**
     * Modeles que l'Europe n'immatricule pas sous leur nom de gamme.
     *
     * Le registre ne connait ni « Serie 3 » ni « Classe C » : il enregistre
     * « 320D XDRIVE » et « C 220 D ». Chaque entree donne les debuts de nom
     * qui designent la gamme, et sert dans les deux sens — pour interroger le
     * service, puis pour rattacher la cote au bon modele du catalogue.
     *
     * « C » est suivi d'une espace a dessein : sans elle, la Classe C
     * ramasserait les CLA et les CLS.
     */
    private const ALIAS_EEA = [
        'bmw|serie-1'            => ['1'],
        'bmw|serie-2'            => ['2'],
        'bmw|serie-3'            => ['3'],
        'bmw|serie-4'            => ['4'],
        'bmw|serie-5'            => ['5'],
        'bmw|serie-6'            => ['6'],
        'bmw|serie-7'            => ['7'],
        'mercedes-benz|classe-a' => ['A '],
        'mercedes-benz|classe-b' => ['B '],
        'mercedes-benz|classe-c' => ['C '],
        'mercedes-benz|classe-e' => ['E '],
        'mercedes-benz|classe-s' => ['S '],
        'mercedes-benz|classe-v' => ['V-KLASSE', 'CLASSE V'],
        'mercedes-benz|ml-gle'   => ['GLE', 'ML '],
        'mercedes-benz|gle'      => ['GLE'],
        'mercedes-benz|glc'      => ['GLC'],
        'mercedes-benz|gla'      => ['GLA'],
    ];

    private const ANNEES_EEA = [
        2021 => 'co2cars_2021Pv23',
        2022 => 'co2cars_2022Pv25',
        2023 => 'co2cars_2023Pv27',
        2024 => 'co2cars_2024Pv29',
        2025 => 'co2cars_2025Pv31',
    ];

    private function importerEurope(): int
    {
        $modeles = $this->modelesSansCote();

        // L'option ne pose pas une requete libre : elle filtre la liste des
        // modeles du catalogue, pour que la marque accompagne toujours le nom.
        if ($filtres = $this->option('modele')) {
            $modeles = array_values(array_filter($modeles, function ($m) use ($filtres) {
                foreach ($filtres as $filtre) {
                    if (str_contains(Str::slug($m['modele']), Str::slug($filtre))) {
                        return true;
                    }
                }

                return false;
            }));
        }

        if ($modeles === []) {
            $this->info('Aucun modele a chercher : le catalogue est deja couvert.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d modeles a chercher en Europe.', count($modeles)));

        $trouves = 0;
        $ecrites = 0;

        foreach ($modeles as $modele) {
            $lignes = $this->chercherEnEurope($modele['marque'], $modele['modele']);
            $etiquette = $modele['marque'].' '.$modele['modele'];

            if ($lignes === []) {
                $this->line(sprintf('  %-28s —', $etiquette));

                continue;
            }

            $trouves++;
            $consos = array_column($lignes, 'consumption_combined');
            $this->line(sprintf(
                '  %-28s %d motorisations, %.1f a %.1f l/100',
                $etiquette, count($lignes), min($consos), max($consos)
            ));

            if (! $this->option('dry-run')) {
                $ecrites += $this->enregistrer($lignes);
            }
        }

        $this->newLine();
        $this->line(sprintf(
            '%d modeles sur %d trouves en Europe, %d motorisations enregistrees.',
            $trouves, count($modeles), $ecrites
        ));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        return $this->relier();
    }

    /**
     * Les modeles du catalogue qu'aucune cote ne couvre encore, avec leur
     * marque — sans elle, « Ranger » ramenerait aussi bien un Ford qu'un Range
     * Rover.
     *
     * @return array<int, array{marque: string, modele: string}>
     */
    private function modelesSansCote(): array
    {
        $couverts = VehicleModel::query()
            ->whereIn('id', Motorisation::query()->whereNotNull('vehicle_model_id')->distinct()->pluck('vehicle_model_id'))
            ->pluck('name')
            ->map(fn ($n) => Str::slug($n))
            ->unique();

        return VehicleModel::query()
            ->with('brand')
            ->get(['id', 'brand_id', 'name'])
            ->reject(fn (VehicleModel $m) => $couverts->contains(Str::slug($m->name)))
            ->map(fn (VehicleModel $m) => ['marque' => $m->brand?->name ?? '', 'modele' => $m->name])
            ->unique(fn (array $m) => Str::slug($m['marque'].'-'.$m['modele']))
            ->values()
            ->all();
    }

    /**
     * Interroge le registre europeen pour un modele.
     *
     * Le service limite ce qu'il accepte — pas d'agregat, pas de table
     * systeme — donc on ramene les lignes brutes et on regroupe ici. Une
     * immatriculation par vehicule vendu : des milliers de lignes pour une
     * poignee de motorisations reelles.
     *
     * @return array<int, array<string, mixed>>
     */
    /** Les debuts de nom qui designent cette gamme dans le registre europeen. */
    private static function aliasEea(string $marque, string $modele): array
    {
        return self::ALIAS_EEA[Str::slug($marque).'|'.Str::slug($modele)] ?? [];
    }

    private function chercherEnEurope(string $marque, string $modele): array
    {
        $motifs = self::aliasEea($marque, $modele) ?: $this->motifs($modele);

        foreach ($motifs as $motif) {
            foreach (array_reverse(self::ANNEES_EEA, true) as $annee => $table) {
                $lignes = $this->interrogerEea($table, $marque, $motif, $annee);

                if ($lignes !== []) {
                    return $lignes;
                }
            }
        }

        return [];
    }

    /**
     * Motifs de recherche, du plus precis au plus large.
     *
     * « Land Cruiser Prado » ne se vend pas sous ce nom en Europe, ou il n'est
     * que « Land Cruiser » : on retente donc en retirant le dernier mot.
     *
     * Mais jamais jusqu'au mot unique quand le nom en compte plusieurs.
     * « Classe C » raccourci en « Classe » ramenait les Classe A, B et E, et
     * un essai sur « Toyota Hilux » reduit a « Toyota » a rendu une fourchette
     * de 3,8 a 5,0 l/100 : des hybrides, prises pour un pick-up diesel. Une
     * recherche trop large ne rend pas moins de resultats, elle en rend de
     * faux.
     */
    private function motifs(string $modele): array
    {
        $mots    = preg_split('/\s+/', trim($modele));
        $minimum = count($mots) > 1 ? 2 : 1;
        $motifs  = [];

        for ($n = count($mots); $n >= $minimum; $n--) {
            $candidat = implode(' ', array_slice($mots, 0, $n));
            if (mb_strlen($candidat) >= 3) {
                $motifs[] = $candidat;
            }
        }

        return $motifs;
    }

    /** @return array<int, array<string, mixed>> */
    private function interrogerEea(string $table, string $marque, string $motif, int $annee): array
    {
        $motifSql = str_replace("'", "''", mb_strtoupper($motif));

        // La marque du registre est ecrite de mille facons — « MERCEDES-BENZ »,
        // « MERCEDES-BENZ AG » — d'ou la comparaison sur le premier mot seul.
        $marqueSql = str_replace("'", "''", mb_strtoupper(preg_split('/[\s-]+/', trim($marque))[0] ?? ''));

        $requete = "SELECT TOP 400 Mk, Cn, Ct, Ft, [Ec (cm3)] AS cc, [Ep (KW)] AS kw, Fc "
            ."FROM [CO2Emission].[latest].[$table] "
            ."WHERE Mk LIKE '$marqueSql%' AND Cn LIKE '$motifSql%' AND Fc IS NOT NULL AND Fc > 0";

        // Soixante-trois modeles, soixante-trois requetes : un service
        // injoignable ne doit pas emporter l'import entier. On signale et on
        // passe au suivant.
        try {
            // Un import complet lance plusieurs centaines de requetes : la
            // resolution DNS lache par moments, et un echec isole laissait un
            // modele sans cote alors que la donnee existait. Trois essais
            // espaces, et une pause entre chaque appel pour ne pas marteler
            // un service public.
            $reponse = Http::timeout(120)
                ->retry(3, 800, throw: false)
                ->get(self::SQL_EEA, ['query' => $requete]);

            usleep(150_000);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $this->warn('  service europeen injoignable : '.Str::limit($e->getMessage(), 90));

            return [];
        }

        if (! $reponse->successful() || $reponse->json('errors') !== null) {
            return [];
        }

        $groupes  = [];
        $exemples = [];
        foreach ($reponse->json('results') ?? [] as $r) {
            // Une motorisation, c'est une marque, un nom, un carburant, une
            // cylindree et une puissance. Le reste — pays, version, poids —
            // ne change pas la consommation homologuee.
            $cle = implode('|', [$r['Mk'] ?? '', $r['Cn'] ?? '', $r['Ft'] ?? '', $r['cc'] ?? '', $r['kw'] ?? '']);
            $groupes[$cle][] = (float) $r['Fc'];
            $exemples[$cle]  = $r;
        }

        $maintenant = now();
        $lignes     = [];

        foreach ($groupes as $cle => $consos) {
            $r = $exemples[$cle];

            // Mediane plutot que moyenne : quelques immatriculations portent
            // des valeurs aberrantes, et une seule suffirait a tirer une
            // moyenne.
            sort($consos);
            $mediane = $consos[intdiv(count($consos), 2)];

            $ligne = [
                'source'               => 'eea',
                'cycle'                => 'wltp',
                'model_year'           => $annee,
                'make_raw'             => trim((string) $r['Mk']),
                'model_raw'            => trim((string) $r['Cn']),
                'vehicle_class'        => $r['Ct'] ?? null,       // M1 : voiture, N1 : utilitaire
                'engine_l'             => $r['cc'] ? round(((int) $r['cc']) / 1000, 1) : null,
                'cylinders'            => null,                    // absent du registre europeen
                'transmission_code'    => null,
                'fuel_code'            => $this->carburantEea($r['Ft'] ?? null),
                'consumption_city'     => null,                    // le registre ne donne que le mixte
                'consumption_highway'  => null,
                'consumption_combined' => round($mediane, 1),
                'co2_g_km'             => null,
                'created_at'           => $maintenant,
                'updated_at'           => $maintenant,
            ];
            $ligne['cle_source'] = self::empreinte($ligne);
            $lignes[] = $ligne;
        }

        return $lignes;
    }

    /** Le vocabulaire europeen ramene aux codes deja utilises. */
    private function carburantEea(?string $ft): ?string
    {
        $f = mb_strtolower(trim((string) $ft));

        // Les hybrides rechargeables gardent un code a part : leur cote
        // officielle, ponderee sur un parcours qui commence batterie pleine,
        // tombe sous 1 l/100. Affichee comme une consommation d'essence, elle
        // ferait passer une Classe C pour une voiture qui ne boit rien.
        $hybride = str_contains($f, 'electric') && (str_contains($f, 'petrol') || str_contains($f, 'diesel'));

        return match (true) {
            $f === ''                  => null,
            $hybride                   => 'H',
            str_contains($f, 'diesel') => 'D',
            str_contains($f, 'petrol') => 'X',
            str_contains($f, 'lpg')    => 'L',
            str_contains($f, 'e85')    => 'E',
            str_contains($f, 'ng')     => 'N',
            str_contains($f, 'electric') => 'B',
            default                    => null,
        };
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
            $ligne = [
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
            $ligne['cle_source'] = self::empreinte($ligne);
            $lignes[] = $ligne;
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
                $marque = null;
                foreach (self::slugsMarque($cote->make_raw) as $slug) {
                    if ($marque = $marques->get($slug)) {
                        break;
                    }
                }
                if (! $marque) {
                    continue;
                }
                $marquesVues[$marque->id] = true;

                $modeleId = $this->modeleLePlusProche(
                    $cote->model_raw,
                    $cote->model_year,
                    $modelesParMarque->get($marque->id) ?? collect(),
                    $marque->name,
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
    private function modeleLePlusProche(string $libelle, int $annee, $modeles, string $marque = ''): ?int
    {
        $cible      = Str::slug($libelle);
        $brut       = mb_strtoupper(trim($libelle));
        $candidats  = [];
        $longueur   = 0;

        foreach ($modeles as $modele) {
            $nom = Str::slug($modele->name);
            if ($nom === '') {
                continue;
            }

            // Deux facons de reconnaitre un modele, et elles ne se mesurent
            // pas pareil. Par le nom : « RAV4 AWD » commence par « RAV4 ».
            // Par la table de correspondance : « 320D XDRIVE » ne ressemble
            // en rien a « Serie 3 », seul l'alias fait le lien.
            $score = null;

            if ($cible === $nom || str_starts_with($cible, $nom.'-')) {
                $score = strlen($nom);
            }

            foreach (self::aliasEea($marque, $modele->name) as $prefixe) {
                if (str_starts_with($brut, mb_strtoupper($prefixe))) {
                    // La longueur du prefixe reconnu, pas celle du nom du
                    // modele : sinon « ML / GLE » l'emportait sur « GLE » par
                    // la seule longueur de son nom, et les cotes recentes
                    // allaient a la generation arretee.
                    $score = max($score ?? 0, strlen($prefixe));
                }
            }

            if ($score === null || $score < $longueur) {
                continue;
            }

            // Une reconnaissance plus precise l'emporte : on repart de zero.
            if ($score > $longueur) {
                $candidats = [];
                $longueur  = $score;
            }
            $candidats[] = $modele;
        }

        if ($candidats === []) {
            return null;
        }

        // La generation produite cette annee-la, et elle seule.
        //
        // Ce controle ne s'appliquait qu'a partir de deux candidats : un
        // modele unique etait accepte sans regarder ses annees. Neuf cotes
        // europeennes de 2023 se sont ainsi posees sur un « ML / GLE » arrete
        // en 2018, pendant que le « GLE » de 2018 a aujourd'hui restait vide.
        $sansBornes = null;

        foreach ($candidats as $modele) {
            $debut = $modele->production_start;
            $fin   = $modele->production_end;

            if ($debut === null && $fin === null) {
                $sansBornes ??= $modele;

                continue;
            }
            if (($debut === null || $annee >= $debut) && ($fin === null || $annee <= $fin)) {
                return $modele->id;
            }
        }

        // Un modele dont on ignore les annees reste un repli acceptable : le
        // catalogue n'en renseigne pas partout. Une generation dont les annees
        // sont connues et ne conviennent pas, non.
        return $sansBornes?->id;
    }
}
