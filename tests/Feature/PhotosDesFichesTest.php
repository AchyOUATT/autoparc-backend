<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Media;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les photos arrivent jusqu'a la fiche, pas seulement jusqu'a la liste.
 *
 * Signale du terrain : « les photos des accessoires ne s'affichent pas dans
 * les pages de detail ». Elles s'affichaient pourtant dans la liste, et les
 * fiches de PIECES les montraient.
 *
 * La cause tenait a une ligne. AccessoryController::catalog() chargeait
 * `media`, catalogShow() et show() ne le chargeaient pas. AccessoryResource
 * publie cette cle sous whenLoaded() : quand la relation n'est pas chargee,
 * la cle DISPARAIT de la reponse au lieu de valoir un tableau vide. Le
 * modele Dart lit alors « aucune photo », ce qui est indistinguable d'un
 * accessoire sans photo. Aucune erreur nulle part.
 *
 * PartController::show chargeait `media` depuis le debut. Les deux catalogues
 * se comportaient donc differemment pour une raison qu'aucun test ne gardait.
 *
 * Ces tests couvrent les DEUX catalogues ensemble, volontairement : la
 * prochaine divergence devra casser un test au lieu d'attendre un signalement.
 */
class PhotosDesFichesTest extends TestCase
{
    use RefreshDatabase;

    /** On passe par la relation : mediable_type n'est pas assignable en masse. */
    private function photoSur(object $modele, string $chemin): Media
    {
        return $modele->media()->create([
            'collection' => 'gallery',
            'path'       => $chemin,
            'disk'       => 'public',
        ]);
    }

    public function test_la_fiche_publique_d_un_accessoire_porte_ses_photos(): void
    {
        $accessoire = Accessory::create([
            'name'         => 'Camera de recul',
            'category'     => 'securite',
            'price'        => 6500,
            'is_active'    => true,
            'is_available' => true,
        ]);

        $this->photoSur($accessoire, 'accessories/camera-1.jpg');
        $this->photoSur($accessoire, 'accessories/camera-2.jpg');

        $reponse = $this->getJson("/api/catalog/accessories/{$accessoire->id}")->assertOk();

        $this->assertArrayHasKey(
            'media',
            $reponse->json('data'),
            "La cle disparait des que la relation n'est pas chargee : c'est "
            ."exactement le defaut, et il ne leve aucune erreur.",
        );
        $this->assertCount(2, $reponse->json('data.media'));
    }

    public function test_la_fiche_publique_d_une_piece_porte_ses_photos(): void
    {
        $categorie = PartCategory::create(['name' => 'Filtres', 'slug' => 'filtres']);

        $piece = Part::create([
            'name'             => 'Filtre a huile',
            'part_category_id' => $categorie->id,
            'price'            => 3000,
            'is_active'        => true,
        ]);

        $this->photoSur($piece, 'parts/filtre-1.jpg');

        $reponse = $this->getJson("/api/catalog/parts/{$piece->id}")->assertOk();

        $this->assertArrayHasKey('media', $reponse->json('data'));
        $this->assertCount(1, $reponse->json('data.media'));
    }

    public function test_la_liste_des_accessoires_porte_aussi_ses_photos(): void
    {
        // La liste marchait deja. Le test existe pour que la correction de la
        // fiche ne se fasse pas au prix de la liste.
        $accessoire = Accessory::create([
            'name'         => 'Camera de recul',
            'category'     => 'securite',
            'price'        => 6500,
            'is_active'    => true,
            'is_available' => true,
        ]);

        $this->photoSur($accessoire, 'accessories/camera-1.jpg');

        $reponse = $this->getJson('/api/catalog/accessories')->assertOk();

        $this->assertCount(1, $reponse->json('data.0.media'));
    }
}
