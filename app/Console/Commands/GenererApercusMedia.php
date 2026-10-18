<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Support\VignetteApercu;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rattrape la variante d'apercu des media deja en base.
 *
 * La generation a l'upload ne vaut que pour les photos a venir. Sans cette
 * commande, le correctif d'og:image ne changerait rien au stock actuel : tout
 * ce qui est deja en ligne garderait son original, que WhatsApp refuse des
 * quelques centaines de ko, et continuerait de partir sans vignette.
 * C'est-a-dire que les pages de partage resteraient sans raison d'etre pour
 * tout le catalogue existant, et ne la retrouveraient qu'au fil des photos
 * reprises une a une.
 *
 * CE STOCK PESE AU PLUS 2 Mo PAR PHOTO, et le commentaire qui tenait cette
 * place annoncait « de 2 a 6 Mo ». C'etait impossible : jusqu'a
 * docker/php/depot-de-photos.ini, l'image de production n'activait aucun
 * php.ini et PHP appliquait son defaut compile, upload_max_filesize=2M. Mesure
 * par HTTP reel sous ce plafond, POST /api/parts/1/media :
 *
 *     2 072 238 o -> 201
 *     2 255 773 o -> 422 « The photos.0 failed to upload. »
 *
 * Aucune photo plus lourde n'a donc jamais pu etre deposee. Cela ne retire rien
 * a l'utilite de ce rattrapage — WhatsApp refuse deja un original de 2 Mo — et
 * cela en ajoute pour la suite : le plafond est maintenant de 8192 ko par photo
 * (MediaController::MAX_SIZE), si bien que les originaux a venir seront
 * reellement de l'ordre de 2 a 6 Mo.
 *
 * ELLE TOURNE AU DEPLOIEMENT, et c'est la correction d'un oubli : le Dockerfile
 * ne lancait que `migrate --force`, si bien que cette commande n'avait jamais
 * ete executee contre la production. Tout le stock existant partait donc encore
 * avec son original — le defaut que cette classe existe pour supprimer,
 * reconduit pour le cas courant.
 *
 * Le plan gratuit de Render n'ouvre pas de Shell : il n'existe aucun moyen de
 * lancer une commande ponctuelle sur l'instance, et « lancez-la a la main »
 * n'est donc pas une solution. C'est la meme contrainte qui a deja force les
 * referentiels dans la ligne de demarrage du Dockerfile. Elle y est desormais
 * aussi, mais APRES le demarrage et bornee par --limit : les mesures de temps
 * et de memoire qui fixent ces deux choix sont commentees dans le Dockerfile,
 * au-dessus du CMD.
 *
 * Elle reste lancable depuis un poste muni de GD et pointe sur la base et le
 * stockage de production, ce qui va plus vite quand on a le moyen de le faire.
 *
 * Rejouable sans dommage : elle ne regarde que les lignes sans variante, a
 * moins qu'on ne demande explicitement de refaire les autres.
 *
 * ── ELLE AVANCE, DESORMAIS ─────────────────────────────────────────────────
 *
 * Le commentaire qui tenait cette place annoncait que « chaque demarrage reprend
 * la ou le precedent s'est arrete ». C'etait faux, et la mesure le disait : la
 * commande repartait du plus petit identifiant sans marquer ses echecs. Sur
 * cinq media dont les deux plus petits identifiants sont irreparables — un
 * original absent du stockage objet, ce que render.yaml documente comme etant
 * arrive —, `--limit=2` lance trois fois de suite donnait :
 *
 *     demarrage 1 -> « 0 variante(s) generee(s) », 0 ligne avec variante sur 5
 *     demarrage 2 -> « 0 variante(s) generee(s) », 0 ligne avec variante sur 5
 *     demarrage 3 -> « 0 variante(s) generee(s) », 0 ligne avec variante sur 5
 *
 * Deux defauts dans un seul. Elle se bloquait derriere une ligne qu'elle ne
 * saurait jamais traiter, et son compte rendu de succes — « 0 variante(s)
 * generee(s) », code de sortie 0 — etait indiscernable de « rien a faire ». En
 * ligne, cela donne : aucune vignette, jamais, et un journal de deploiement qui
 * se lit comme un deploiement sain. C'est exactement la panne muette que les
 * pages de partage existent pour supprimer.
 *
 * Trois changements y repondent, et aucun n'est cosmetique :
 *
 *   — un echec est MARQUE (`preview_attempts`, `preview_failed_at`) ;
 *   — le tri prend les lignes JAMAIS TENTEES d'abord, si bien que le demarrage
 *     suivant atteint les lignes saines au lieu de rejouer les memes echecs ;
 *   — au-dela de ESSAIS_MAX tentatives, la ligne sort du lot et son abandon est
 *     journalise, donc visible dans le visualiseur de Render.
 *
 * Et le compte rendu distingue « rien a faire » (code 0) de « rien n'a pu etre
 * fait » (code 1), pour que le `||` du Dockerfile ait quelque chose a dire.
 */
class GenererApercusMedia extends Command
{
    protected $signature = 'media:apercus
                            {--limit=0 : S\'arreter apres N media (0 = tous)}
                            {--disk= : Ne traiter que les media de ce disque}
                            {--refaire : Regenerer aussi les variantes deja presentes}
                            {--dry-run : Afficher ce qui serait genere, sans rien ecrire}';

    protected $description = 'Genere la variante d\'apercu (1200x630) des media qui n\'en ont pas';

    /**
     * Les media traites par paquets de cent.
     *
     * GD garde deux images decompressees en memoire a chaque passe ; charger le
     * catalogue entier d'un bloc ferait tenir aussi toutes les lignes Eloquent
     * a cote. Cent est assez pour que le voyage vers la base ne domine pas, et
     * assez peu pour que l'empreinte reste plate sur un parc de milliers de
     * photos.
     */
    private const PAQUET = 100;

    /**
     * Combien de fois une ligne est retentee avant d'etre laissee de cote.
     *
     * Trois, et non une : un echec n'est pas forcement definitif. Les deux
     * disques en service passent par le reseau quand MEDIA_DISK vaut `s3`, et
     * VignetteApercu rend `null` aussi bien sur un original disparu que sur un
     * aller-retour R2 qui a echoue. Abandonner au premier refus perdrait des
     * vignettes fabriquables ; les retenter sans fin bloque tout le reste.
     *
     * La ligne n'est jamais perdue pour autant : elle continue de servir son
     * original, et `--refaire` la reprend quand la cause est corrigee.
     */
    private const ESSAIS_MAX = 3;

    public function handle(): int
    {
        // Mesure du poste de developpement : `php -m` ne liste ni gd ni
        // imagick. Sans GD, chaque appel rendrait `null` et la commande
        // parcourrait tout le catalogue pour ne rien ecrire, en annoncant
        // « 0 generee » — un resultat qu'on lit comme « rien a faire ».
        if (! VignetteApercu::disponible()) {
            $this->error(
                'GD est absent de ce PHP : aucune variante ne peut etre generee. '
                .'Verifiez `php -m`, ou activez extension=gd dans php.ini.'
            );

            return self::FAILURE;
        }

        $requete = Media::query()
            ->when(
                ! $this->option('refaire'),
                // Les lignes deja abandonnees sortent du lot : c'est ce qui
                // empeche une ligne irreparable de consommer indefiniment le
                // budget de --limit. `--refaire` les reprend toutes, compteur
                // compris, et c'est le moyen de rejouer apres correction.
                fn ($q) => $q->whereNull('preview_path')
                    ->where('preview_attempts', '<', self::ESSAIS_MAX),
            )
            ->when($this->option('disk'), fn ($q, $disque) => $q->where('disk', $disque))
            // LES LIGNES JAMAIS TENTEES D'ABORD, et c'est le coeur du correctif.
            // Avec le seul `orderBy('id')`, un echec aux petits identifiants
            // reprenait tout le budget de chaque demarrage et les lignes saines
            // n'etaient jamais atteintes. L'identifiant ne departage plus que
            // les lignes a egalite d'essais, pour que l'ordre reste stable.
            ->orderBy('preview_attempts')
            ->orderBy('id');

        $total = (clone $requete)->count();

        // Comptees avant toute chose : une ligne abandonnee ne se voit plus dans
        // le lot, et « rien a faire » ne doit pas vouloir dire « tout a echoue
        // et je me suis tue ». Voir le compte rendu plus bas.
        $abandonnees = $this->option('refaire') ? 0 : Media::query()
            ->whereNull('preview_path')
            ->where('preview_attempts', '>=', self::ESSAIS_MAX)
            ->when($this->option('disk'), fn ($q, $disque) => $q->where('disk', $disque))
            ->count();

        if ($total === 0) {
            $this->info('Aucun media a traiter : rien a faire.');

            if ($abandonnees > 0) {
                $this->avertirDesAbandons($abandonnees);
            }

            return self::SUCCESS;
        }

        $limite = max(0, (int) $this->option('limit'));
        $aFaire = $limite > 0 ? min($limite, $total) : $total;

        $this->info("{$total} media sans variante, {$aFaire} a traiter.");

        if ($this->option('dry-run')) {
            $requete->limit($aFaire)->get()->each(
                fn (Media $media) => $this->line(
                    "  {$media->id}  {$media->disk}  {$media->path}"
                    .'  ->  '.VignetteApercu::chemin($media->path)
                ),
            );

            $this->comment('Analyse seule : rien n\'a ete ecrit.');

            return self::SUCCESS;
        }

        $barre   = $this->output->createProgressBar($aFaire);
        $generes = 0;
        $echecs  = [];
        $epuises = 0;

        // Les identifiants d'abord, les lignes ensuite. C'est ce qui permet
        // l'ordre « jamais tentees d'abord » : chunkById impose de parcourir par
        // identifiant croissant, et ecrasait de surcroit notre `limit` — avec
        // lui, `--limit=10` parcourait tout le catalogue sans le dire. La liste
        // est bornee par --limit, et l'hydratation reste par paquets de cent :
        // GD garde deux images decompressees a chaque passe, et charger le
        // catalogue entier d'un bloc ferait tenir aussi toutes les lignes
        // Eloquent a cote.
        $identifiants = (clone $requete)->take($aFaire)->pluck('id');

        foreach ($identifiants->chunk(self::PAQUET) as $paquet) {
            foreach (Media::whereIn('id', $paquet)->orderBy('id')->get() as $ligne) {
                $apercu = VignetteApercu::generer($ligne->disk, $ligne->path);

                if ($apercu === null) {
                    // L'ECHEC EST MARQUE. Sans cette ecriture, la ligne revient
                    // en tete du lot au demarrage suivant et la commande
                    // n'atteint jamais les lignes saines. La variante reste
                    // nulle : la page continue de servir l'original, qui a
                    // toujours donne un apercu — mediocre, mais existant.
                    $essais = (int) $ligne->preview_attempts + 1;

                    $ligne->update([
                        'preview_attempts'  => $essais,
                        'preview_failed_at' => now(),
                    ]);

                    $echecs[] = "{$ligne->id} ({$ligne->disk}) {$ligne->path} — essai {$essais}/".self::ESSAIS_MAX;

                    if ($essais >= self::ESSAIS_MAX) {
                        $epuises++;

                        // Journalise et non seulement affiche : la sortie de la
                        // commande part dans un `nohup` du Dockerfile, que
                        // personne ne relit. LOG_CHANNEL vaut `stderr` en ligne,
                        // donc cette ligne-ci arrive dans le visualiseur de
                        // Render, ou un abandon repete se voit.
                        Log::warning('Apercu : media abandonne apres '.self::ESSAIS_MAX.' tentatives, il sert son original', [
                            'media'  => $ligne->id,
                            'disque' => $ligne->disk,
                            'chemin' => $ligne->path,
                        ]);
                    }
                } else {
                    $ligne->update([
                        'preview_path'      => $apercu['chemin'],
                        'preview_width'     => $apercu['largeur'],
                        'preview_height'    => $apercu['hauteur'],
                        // Le compteur retombe a zero : la ligne a une variante,
                        // et un compteur laisse en l'etat ferait mentir le
                        // prochain `--refaire`.
                        'preview_attempts'  => 0,
                        'preview_failed_at' => null,
                    ]);

                    $generes++;
                }

                $barre->advance();
            }
        }

        $barre->finish();
        $this->newLine(2);

        $traites = count($echecs) + $generes;

        // TROIS comptes rendus distincts, parce que les trois situations le
        // sont. « 0 variante(s) generee(s) » couvrait les trois a la fois, et
        // c'est ce qui rendait la panne illisible.
        if ($generes > 0) {
            $this->info("{$generes} variante(s) generee(s) sur {$traites} media traite(s).");
        } else {
            $this->error(
                "Aucune variante n'a pu etre generee sur {$traites} media traite(s) — "
                .'ce n\'est pas « rien a faire ».'
            );
        }

        if ($echecs !== []) {
            // Nommees, et non comptees : un echec se corrige en regardant la
            // ligne, et le journal porte deja la raison exacte posee par
            // VignetteApercu.
            $this->warn(count($echecs).' media sans variante — ils servent leur original :');

            foreach ($echecs as $echec) {
                $this->line("  {$echec}");
            }
        }

        if ($epuises > 0) {
            $this->avertirDesAbandons($epuises);
        }

        // Code 1 quand RIEN n'a pu etre fait alors qu'il y avait a faire. Le
        // Dockerfile accroche son message a ce code ; avec un 0 systematique,
        // il n'avait jamais rien a dire.
        return $generes > 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Le rappel des lignes mises de cote.
     *
     * Affiche a chaque execution, et pas seulement celle qui les abandonne :
     * une ligne irreparable disparait du lot et cesserait sinon d'exister dans
     * le compte rendu — c'est-a-dire qu'un catalogue a moitie sans vignette se
     * lirait comme un catalogue complet.
     */
    private function avertirDesAbandons(int $nombre): void
    {
        $this->warn(
            "{$nombre} media laisse(s) de cote apres ".self::ESSAIS_MAX.' tentatives : '
            .'ils servent leur original. `media:apercus --refaire` les reprend '
            .'quand la cause est corrigee.'
        );
    }
}
