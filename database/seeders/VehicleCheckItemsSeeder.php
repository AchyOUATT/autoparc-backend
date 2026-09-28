<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Les points du controle avant voyage.
 *
 * Deux principes gouvernent cette liste, et expliquent pourquoi elle est
 * courte la ou on l'attendrait longue.
 *
 * Un point est un geste, pas une piece. « Faire le tour des pneus » se fait en
 * une fois : pression, usure, flancs. Eclate en trois lignes, le meme geste se
 * coche trois fois et la liste double sans rien verifier de plus. Le detail vit
 * dans l'aide, pas dans le nombre de lignes.
 *
 * Le noyau est pose a tout vehicule ; le reste attend une raison. Un point qui
 * apparait sans raison est un point qu'on coche sans regarder. C'est pourquoi
 * la courroie attend 60 000 km, le filtre a gasoil attend un diesel qui a
 * roule, et le filtre a air attend la saison seche.
 *
 * Le ton des aides : dire ou regarder et ce qu'on risque. « Verifier les
 * pneus » n'apprend rien a personne ; « le temoin d'usure est au fond des
 * rainures » se lit une fois et se retient.
 */
class VehicleCheckItemsSeeder extends Seeder
{
    /**
     * Colonnes remises a leur valeur neutre a chaque passage.
     *
     * Sans ce socle, un rejeu du seeder laisserait en place les conditions d'une
     * version precedente : un point qu'on vient d'ouvrir a tous les vehicules
     * garderait son ancien seuil de kilometrage, et personne ne verrait
     * pourquoi il ne s'affiche pas.
     */
    private const NEUTRE = [
        'help' => null,
        'is_core' => false,
        'min_trip_distance_km' => null,
        'min_mileage_km' => null,
        'min_age_years' => null,
        'engine_codes' => null,
        'excluded_engine_codes' => null,
        'body_types' => null,
        'months' => null,
        'trigger_label' => null,
        'prefill_source' => null,
        'part_category_slug' => null,
        'position' => 0,
        'is_active' => true,
    ];

    public function run(): void
    {
        $lignes = [];

        foreach ($this->points() as $point) {
            $ligne = array_merge(self::NEUTRE, $point);

            foreach (['engine_codes', 'excluded_engine_codes', 'body_types', 'months'] as $json) {
                if ($ligne[$json] !== null) {
                    $ligne[$json] = json_encode($ligne[$json]);
                }
            }

            $lignes[] = $ligne + ['created_at' => now(), 'updated_at' => now()];
        }

        // Les colonnes mises a jour se deduisent de NEUTRE : une condition
        // ajoutee au schema sans etre ajoutee a NEUTRE ne serait ni remise a
        // zero ni mise a jour au rejeu du seeder.
        DB::table('vehicle_check_items')->upsert(
            $lignes,
            ['code'],
            array_merge(array_keys(self::NEUTRE), ['category', 'title', 'severity', 'updated_at']),
        );

        // Un point retire de la liste ci-dessus doit disparaitre des controles a
        // venir sans effacer ceux qui l'ont deja porte : les reponses recopient
        // leur libelle, l'historique ne bouge pas.
        DB::table('vehicle_check_items')
            ->whereNotIn('code', array_column($lignes, 'code'))
            ->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function points(): array
    {
        return [
            // ── Papiers ───────────────────────────────────────────────
            //
            // En tete de liste, et bloquants : sur une nationale, un papier
            // perime ne coute pas un rappel mais une amende et une
            // immobilisation. C'est aussi le seul bloc dont l'application
            // connait deja la reponse.
            [
                'code' => 'papiers-assurance', 'category' => 'papiers', 'position' => 100,
                'title' => "Attestation d'assurance valide",
                'help' => "Sur le pare-brise et dans la boîte à gants. À un contrôle, une attestation périmée ne se discute pas : amende, et le véhicule reste sur place.",
                'severity' => 'blocking', 'is_core' => true, 'prefill_source' => 'insurance',
            ],
            [
                'code' => 'papiers-visite', 'category' => 'papiers', 'position' => 110,
                'title' => 'Visite technique valide',
                'help' => "La vignette sur le pare-brise et le procès-verbal. Sa date ne se devine pas : regarde-la avant de partir, pas au poste de contrôle.",
                'severity' => 'blocking', 'is_core' => true, 'prefill_source' => 'technical_inspection',
            ],
            [
                'code' => 'papiers-bord', 'category' => 'papiers', 'position' => 120,
                'title' => 'Carte grise et permis à bord',
                'help' => "Les originaux. Un permis oublié à la maison arrête le voyage au premier contrôle, quelle que soit la bonne foi.",
                'severity' => 'blocking', 'is_core' => true,
            ],

            // ── Pneus ─────────────────────────────────────────────────
            [
                'code' => 'pneus-etat', 'category' => 'pneus', 'position' => 200,
                'title' => 'Pression et usure des quatre pneus',
                'help' => "À froid, avant de rouler : la pression conseillée est sur l'étiquette du montant de la porte conducteur. Cherche le témoin d'usure au fond des rainures, et regarde les flancs — une boursouflure annonce un éclatement, pas une crevaison.",
                'severity' => 'blocking', 'is_core' => true, 'part_category_slug' => 'pneus',
            ],
            [
                'code' => 'pneus-secours', 'category' => 'pneus', 'position' => 210,
                'title' => 'Roue de secours, cric et clé',
                'help' => "Une roue de secours se dégonfle sans qu'on s'en aperçoive, et un cric absent la rend inutile. Entre deux villes, une crevaison sans secours, c'est la nuit sur place.",
                'severity' => 'blocking', 'is_core' => true, 'part_category_slug' => 'pneus',
            ],

            // ── Niveaux et fuites ────────────────────────────────────
            [
                'code' => 'niveaux-capot', 'category' => 'niveaux', 'position' => 300,
                'title' => 'Niveaux sous le capot',
                'help' => "Moteur froid, terrain plat : l'huile entre les deux repères de la jauge, le liquide de refroidissement au vase d'expansion, le liquide de frein au bocal. Par 40 degrés, un niveau de refroidissement bas devient une surchauffe en une heure de route.",
                'severity' => 'blocking', 'is_core' => true, 'part_category_slug' => 'huile-moteur',
            ],
            [
                'code' => 'niveaux-fuite', 'category' => 'niveaux', 'position' => 310,
                'title' => 'Trace de fuite sous le véhicule',
                'help' => "Regarde le sol à l'endroit où la voiture était garée. Une tache fraîche d'huile, de liquide coloré ou de liquide de frein se traite avant le départ — sur la route, elle se traite à l'arrêt forcé.",
                'severity' => 'blocking', 'is_core' => true,
            ],
            [
                'code' => 'entretien-vidange', 'category' => 'niveaux', 'position' => 320,
                'title' => 'Vidange à faire avant de partir',
                'help' => "Un long trajet consomme d'un coup les kilomètres qui restaient. La faire avant, c'est une visite au garage ; la faire après, c'est souvent trop tard.",
                'severity' => 'watch', 'is_core' => false, 'prefill_source' => 'service',
                'part_category_slug' => 'huile-moteur',
            ],

            // ── Freins ───────────────────────────────────────────────
            [
                'code' => 'freins', 'category' => 'freins', 'position' => 400,
                'title' => 'Freins : pédale, bruit, vibration',
                'help' => "Moteur tournant, appuie fort : la pédale doit rester ferme. Si elle s'enfonce lentement, ne prends pas la route. Puis un freinage à basse vitesse : un grincement métallique, c'est une plaquette finie ; une vibration dans le volant, un disque voilé.",
                'severity' => 'blocking', 'is_core' => true, 'part_category_slug' => 'disques-plaquettes',
            ],

            // ── Eclairage et visibilite ──────────────────────────────
            [
                'code' => 'eclairage-feux', 'category' => 'eclairage', 'position' => 500,
                'title' => 'Feux, stop et clignotants',
                'help' => "À deux, ou en reculant contre un mur : croisement, route, stop, clignotants, warning. Un feu stop mort, c'est le camion derrière qui ne sait pas que tu ralentis.",
                'severity' => 'blocking', 'is_core' => true, 'part_category_slug' => 'eclairage',
            ],
            [
                'code' => 'visibilite', 'category' => 'eclairage', 'position' => 510,
                'title' => 'Pare-brise, balais et rétroviseurs',
                'help' => "Un impact dans l'axe du regard s'agrandit avec la chaleur, et fait refuser la visite technique. Un balai durci transforme une averse en écran opaque — vérifie aussi que le lave-glace a de l'eau.",
                'severity' => 'watch', 'is_core' => true, 'part_category_slug' => 'pare-brise',
            ],

            // ── Moteur : les points qui attendent une raison ─────────
            [
                'code' => 'moteur-courroie', 'category' => 'moteur', 'position' => 600,
                'title' => "État de la courroie d'accessoires",
                'help' => "Craquelures, effilochage, jeu au doigt. Cette courroie entraîne l'alternateur et souvent la pompe à eau : si elle casse en route, tu t'arrêtes là où elle a cassé.",
                'severity' => 'blocking', 'is_core' => false, 'min_mileage_km' => 60000,
                // Une electrique n'a pas de courroie d'accessoires : demander de
                // la verifier, et bloquer le depart dessus, decredibilise la
                // liste entiere.
                'excluded_engine_codes' => ['electric'],
            ],
            [
                'code' => 'moteur-distribution', 'category' => 'moteur', 'position' => 610,
                'title' => 'Courroie de distribution : dernier remplacement',
                'help' => "Si personne ne sait quand elle a été changée, un long trajet n'est pas le moment de le découvrir : sa rupture casse le moteur, pas seulement la courroie.",
                'severity' => 'watch', 'is_core' => false,
                'min_age_years' => 10, 'min_trip_distance_km' => 200,
                'excluded_engine_codes' => ['electric'],
                'part_category_slug' => 'kit-distribution',
            ],
            [
                'code' => 'moteur-filtre-air', 'category' => 'moteur', 'position' => 620,
                'title' => 'Filtre à air',
                'help' => "En saison sèche, la poussière le bouche en quelques milliers de kilomètres : le moteur s'essouffle et la consommation monte. Sors-le et regarde-le à la lumière.",
                'severity' => 'watch', 'is_core' => false,
                'months' => [11, 12, 1, 2, 3],
                // Une saison ne se deduit pas d'un chiffre : sans ce libelle, le
                // point apparaitrait sans aucune raison affichee — exactement ce
                // qu'on cherche a eviter.
                'trigger_label' => 'Saison sèche',
                'excluded_engine_codes' => ['electric'],
                'part_category_slug' => 'filtre-air',
            ],
            [
                'code' => 'moteur-filtre-gasoil', 'category' => 'moteur', 'position' => 630,
                'title' => 'Filtre à gasoil',
                'help' => "Sur un diesel qui a du kilométrage, c'est la panne la plus banale et la plus immobilisante. Purge l'eau du décanteur s'il en a un.",
                'severity' => 'watch', 'is_core' => false,
                'engine_codes' => ['diesel'], 'min_mileage_km' => 80000,
                'part_category_slug' => 'filtre-carburant',
            ],
            [
                'code' => 'moteur-batterie', 'category' => 'moteur', 'position' => 640,
                'title' => 'Cosses et fixation de la batterie',
                'help' => "Cosses propres et serrées, batterie bien bridée. La chaleur raccourcit sa vie : passé huit ans, elle lâche sans prévenir — souvent au démarrage du retour.",
                'severity' => 'watch', 'is_core' => false, 'min_age_years' => 8,
                'part_category_slug' => 'batterie-12v',
            ],
            [
                'code' => 'moteur-refroidissement', 'category' => 'moteur', 'position' => 650,
                'title' => 'Ventilateur et durites de refroidissement',
                'help' => "Le ventilateur doit se déclencher moteur chaud. Durites souples sans craquelure, colliers serrés : c'est la panne la plus fréquente sur une longue nationale en pleine chaleur.",
                'severity' => 'watch', 'is_core' => false,
                'min_trip_distance_km' => 200, 'min_mileage_km' => 100000,
                // L'aide parle de ventilateur declenche moteur chaud : le geste
                // decrit n'est pas celui d'une electrique, meme si elle a bien un
                // circuit de refroidissement.
                'excluded_engine_codes' => ['electric'],
                'part_category_slug' => 'refroidissement',
            ],

            // ── Confort qui n'en est pas un ─────────────────────────
            [
                'code' => 'confort-clim', 'category' => 'confort', 'position' => 700,
                'title' => 'Climatisation',
                'help' => "Trois cents kilomètres à 40 degrés sans climatisation, ce n'est pas une question de confort : c'est la vigilance du conducteur qui baisse.",
                'severity' => 'watch', 'is_core' => false, 'min_trip_distance_km' => 150,
                'part_category_slug' => 'climatisation',
            ],

            // ── Securite reglementaire ──────────────────────────────
            [
                'code' => 'securite-equipement', 'category' => 'securite', 'position' => 800,
                'title' => 'Triangle, extincteur et gilet',
                'help' => "Exigés à la visite technique et demandés aux contrôles. L'extincteur porte une date de péremption : regarde-la. Le gilet sert la nuit, quand descendre sans être vu est le vrai danger d'une panne.",
                'severity' => 'blocking', 'is_core' => true,
            ],

            // ── Charge : ce qu'une berline n'a pas a verifier ───────
            [
                'code' => 'charge-arrimage', 'category' => 'charge', 'position' => 900,
                'title' => 'Arrimage du chargement',
                'help' => "Des sangles, pas des cordes usées. Un bidon qui se déplace au freinage change la tenue de route ; une charge qui tombe est un accident pour celui qui suit.",
                'severity' => 'blocking', 'is_core' => false,
                'body_types' => ['pick-up', 'utilitaire'], 'min_trip_distance_km' => 50,
            ],
            [
                'code' => 'charge-pression', 'category' => 'charge', 'position' => 910,
                'title' => 'Pression arrière adaptée à la charge',
                'help' => "En charge, l'arrière demande plus de pression qu'à vide. Le chiffre est sur la même étiquette, colonne « charge ».",
                'severity' => 'watch', 'is_core' => false,
                'body_types' => ['pick-up', 'utilitaire'], 'min_trip_distance_km' => 50,
                'part_category_slug' => 'pneus',
            ],

            // ── Le depart lui-meme ─────────────────────────────────
            [
                'code' => 'voyage-provisions', 'category' => 'voyage', 'position' => 1000,
                'title' => 'Plein, eau et téléphone chargé',
                'help' => "Repère où tu feras le plein : entre deux villes, une station fermée coûte cher. De l'eau à bord, un téléphone chargé et du crédit dessus.",
                'severity' => 'watch', 'is_core' => false, 'min_trip_distance_km' => 200,
            ],
        ];
    }
}
