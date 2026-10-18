<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Brand;
use App\Models\Location;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La ligne de compatibilite des cartes du catalogue.
 *
 * ── LE DEFAUT QUE CE FICHIER EXISTE POUR EMPECHER ───────────────────────────
 *
 * Une cle rendue sous `whenLoaded` DISPARAIT du JSON quand la route n'a pas
 * charge la relation. Pas d'erreur, pas de valeur nulle : la cle n'est pas la,
 * et l'ecran affiche du vide en silence.
 *
 * Ce depot a livre ce defaut CINQ fois :
 *   — `fuel_tracking` absent d'une route ;
 *   — les photos des accessoires absentes de la fiche de detail, signalees du
 *     terrain (« les photos des accessoires ne s'affichent pas ») ;
 *   — deux fois de plus, releves en relecture ;
 *   — et `compatibility` sur la liste des accessoires, que ce fichier couvre.
 *
 * La ligne des cartes lit `compatibility`. Les PIECES le chargeaient deja
 * (`fitments.vehicleModel.brand` dans PartController::index) ; les ACCESSOIRES
 * ne chargeaient que `manufacturer, location, media`, donc leur carte serait
 * restee vide sur chaque accessoire, sans que rien ne le signale.
 *
 * Les tests portent donc sur les ROUTES DE LISTE, pas sur les ressources :
 * c'est le chargement qui manque, et une ressource testee seule passe
 * toujours.
 */
class CompatibiliteSurLesCartesTest extends TestCase
{
    use RefreshDatabase;

    private function modele(string $marque, string $modele): VehicleModel
    {
        $b = Brand::firstOrCreate(
            ['slug' => strtolower($marque)],
            ['name' => $marque, 'is_active' => true],
        );

        return VehicleModel::firstOrCreate(
            ['slug' => strtolower($marque.'-'.$modele)],
            ['brand_id' => $b->id, 'name' => $modele, 'is_active' => true],
        );
    }

    private function emplacement(): Location
    {
        return Location::firstOrCreate(
            ['name' => 'Entrepot'],
            ['city' => 'Ouagadougou'],
        );
    }

    public function test_la_liste_des_pieces_porte_le_resume_de_compatibilite(): void
    {
        // PartFactory::categoryFor retombe sur `firstOrFail` : sans categorie
        // en base, la fabrique leve au lieu d'en creer une.
        PartCategory::firstOrCreate(
            ['slug' => 'filtres-compat'],
            ['name' => 'Filtres'],
        );

        $piece = Part::factory()->create([
            'is_active' => true,
            'location_id' => $this->emplacement()->id,
        ]);

        $piece->fitments()->create([
            'vehicle_model_id' => $this->modele('Toyota', 'Corolla')->id,
        ]);

        $reponse = $this->getJson('/api/catalog/parts?per_page=5')->assertOk();

        $article = $reponse->json('data.0');

        $this->assertArrayHasKey(
            'compatibility',
            $article,
            'La cle `compatibility` a disparu de la liste des pieces. Elle est '
            .'rendue sous whenLoaded : la route a cesse de charger '
            .'`fitments.vehicleModel.brand`, et la ligne des cartes est vide '
            .'sur chaque piece, sans erreur.',
        );

        $this->assertSame(
            ['Toyota Corolla'],
            $article['compatibility']['vehicles'],
            'Le resume ne porte pas « Marque Modele ». C\'est exactement le '
            .'texte que la carte affiche.',
        );
    }

    public function test_la_liste_des_accessoires_porte_le_resume_de_compatibilite(): void
    {
        $accessoire = Accessory::factory()->create([
            'is_active' => true,
            'is_available' => true,
            'location_id' => $this->emplacement()->id,
        ]);

        $accessoire->fitments()->create([
            'vehicle_model_id' => $this->modele('Hyundai', 'Tucson')->id,
        ]);

        $reponse = $this->getJson('/api/catalog/accessories?per_page=5')->assertOk();

        $article = $reponse->json('data.0');

        $this->assertArrayHasKey(
            'compatibility',
            $article,
            'La cle `compatibility` a disparu de la liste des accessoires. '
            .'C\'est le defaut d\'origine : la route ne chargeait que '
            .'`manufacturer, location, media`, donc la ligne des cartes serait '
            .'restee vide sur CHAQUE accessoire, sans erreur et sans que rien '
            .'ne le signale.',
        );

        $this->assertSame(
            ['Hyundai Tucson'],
            $article['compatibility']['vehicles'],
        );
    }

    public function test_un_article_sans_compatibilite_rend_un_resume_vide_et_non_une_cle_absente(): void
    {
        // La carte doit pouvoir distinguer « aucune compatibilite declaree »
        // de « la route a oublie de charger la relation ». Sans cette
        // distinction, les deux s'affichent pareil — c'est-a-dire pas du tout.
        Accessory::factory()->create([
            'is_active' => true,
            'is_available' => true,
            'location_id' => $this->emplacement()->id,
        ]);

        $article = $this->getJson('/api/catalog/accessories?per_page=5')
            ->assertOk()
            ->json('data.0');

        $this->assertArrayHasKey('compatibility', $article);
        $this->assertSame(0, $article['compatibility']['count']);
        $this->assertSame([], $article['compatibility']['vehicles']);
    }

    public function test_le_resume_plafonne_a_trois_vehicules_mais_annonce_le_total(): void
    {
        // La carte tronque a deux lignes de toute facon ; le plafond sert le
        // POIDS de la reponse, qui porte vingt articles. Mais `count` doit
        // rester le total REEL, sans quoi la carte ne pourrait pas dire
        // combien de modeles elle n'affiche pas.
        $accessoire = Accessory::factory()->create([
            'is_active' => true,
            'is_available' => true,
            'location_id' => $this->emplacement()->id,
        ]);

        foreach ([
            ['Toyota', 'Corolla'],
            ['Hyundai', 'Tucson'],
            ['Kia', 'Rio'],
            ['Mazda', 'CX-9'],
            ['Suzuki', 'Alto'],
        ] as [$marque, $modele]) {
            $accessoire->fitments()->create([
                'vehicle_model_id' => $this->modele($marque, $modele)->id,
            ]);
        }

        $resume = $this->getJson('/api/catalog/accessories?per_page=5')
            ->assertOk()
            ->json('data.0.compatibility');

        $this->assertSame(5, $resume['count']);
        $this->assertCount(3, $resume['vehicles']);
    }
}
