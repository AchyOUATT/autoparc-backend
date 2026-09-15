<?php

namespace Tests\Unit;

use App\Support\FitmentPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Les deux proprietes dont depend la commande de rattrapage.
 *
 * *Stabilite* : la commande se relance sur une base deja traitee, y compris en
 * production. Si la selection variait d'une execution a l'autre, chaque passage
 * deplacerait les compatibilites — une piece visible hier disparaitrait
 * aujourd'hui sans que personne n'ait rien change.
 *
 * *Coherence* : la portee depend de la famille de la piece. L'ancien seeder
 * tirait trois modeles au hasard pour tout, ce qui declarait un pare-brise
 * compatible avec trois modeles differents et un filtre a huile avec trois
 * seulement.
 */
class FitmentPlannerTest extends TestCase
{
    /** @return list<int> */
    private function modeles(int $combien): array
    {
        return range(1, $combien);
    }

    public function test_la_selection_ne_bouge_pas_entre_deux_appels(): void
    {
        $modeles = $this->modeles(80);

        $premier = FitmentPlanner::modelesPour('PRT-ABC123', 'plaquettes-avant', $modeles);
        $second  = FitmentPlanner::modelesPour('PRT-ABC123', 'plaquettes-avant', $modeles);

        $this->assertSame($premier, $second);
    }

    public function test_deux_pieces_ne_recoivent_pas_la_meme_selection(): void
    {
        $modeles = $this->modeles(80);

        $this->assertNotSame(
            FitmentPlanner::modelesPour('PRT-AAA', 'plaquettes-avant', $modeles),
            FitmentPlanner::modelesPour('PRT-BBB', 'plaquettes-avant', $modeles),
        );
    }

    public function test_un_consommable_couvre_plus_de_modeles_qu_un_optique(): void
    {
        $modeles = $this->modeles(80);

        $filtre  = FitmentPlanner::modelesPour('PRT-1', 'filtre-huile', $modeles);
        $optique = FitmentPlanner::modelesPour('PRT-1', 'phares', $modeles);

        $this->assertGreaterThan(count($optique), count($filtre));
        $this->assertCount(1, $optique, 'Un optique avant ne va que sur un modele.');
    }

    public function test_une_famille_inconnue_retombe_sur_une_portee_moderee(): void
    {
        $modeles = $this->modeles(80);

        $piece = FitmentPlanner::modelesPour('PRT-1', null, $modeles);

        $this->assertNotEmpty($piece);
        $this->assertLessThanOrEqual(6, count($piece));
    }

    /**
     * Sans cette reserve, une selection uniforme sur cent soixante modeles ne
     * toucherait presque jamais les quelques modeles reellement possedes, et
     * l'ecran resterait vide malgre des milliers de lignes en base.
     */
    public function test_les_modeles_reellement_utilises_sont_servis_en_premier(): void
    {
        $tous         = $this->modeles(160);
        $prioritaires = [7, 42, 99];

        $choisis = FitmentPlanner::modelesPour('PRT-1', 'plaquettes-avant', $tous, $prioritaires);

        $this->assertNotEmpty(
            array_intersect($choisis, $prioritaires),
            'Au moins un modele prioritaire doit etre couvert.',
        );
    }

    public function test_sans_modele_prioritaire_la_selection_reste_valide(): void
    {
        $choisis = FitmentPlanner::modelesPour('PRT-1', 'filtre-air', $this->modeles(20), []);

        $this->assertNotEmpty($choisis);
        $this->assertSame($choisis, array_unique($choisis));
    }

    public function test_on_ne_demande_jamais_plus_de_modeles_qu_il_n_en_existe(): void
    {
        $choisis = FitmentPlanner::modelesPour('PRT-1', 'batterie-12v', [3, 8], []);

        $this->assertCount(2, $choisis);
    }

    public function test_une_plage_d_annees_epouse_la_fenetre_de_production(): void
    {
        // On balaie plusieurs cles : une sur trois environ pose une plage, les
        // autres valent pour toutes les annees.
        $avecPlage = 0;

        for ($i = 0; $i < 60; $i++) {
            [$de, $a] = FitmentPlanner::plageAnnees("cle-{$i}", 2013, 2019);

            if ($de !== null) {
                $avecPlage++;
                $this->assertSame(2013, $de);
                $this->assertSame(2019, $a);
            } else {
                $this->assertNull($a);
            }
        }

        $this->assertGreaterThan(0, $avecPlage, 'Des plages doivent etre posees.');
        $this->assertLessThan(60, $avecPlage, 'Toutes ne doivent pas en poser.');
    }

    /**
     * Un modele dont on ignore les annees de production ne peut pas porter de
     * plage : l'inventer reviendrait a exclure des vehicules sans raison.
     */
    public function test_sans_annee_de_production_la_compatibilite_vaut_pour_toutes(): void
    {
        $this->assertSame([null, null], FitmentPlanner::plageAnnees('cle', null, null));
    }
}
