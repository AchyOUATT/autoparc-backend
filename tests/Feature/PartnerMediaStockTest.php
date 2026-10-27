<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Brand;
use App\Models\EngineType;
use App\Models\Media;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartnerAccount;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Support\VignetteApercu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PartnerMediaStockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        config(['media.disk' => 'public']);
    }

    private function seller(string $status = 'approved'): array
    {
        $user = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $account = PartnerAccount::create([
            'user_id' => $user->id,
            'company_name' => 'Entreprise '.$user->id,
            'contact_name' => 'Contact de test',
            'phone' => $this->numeroDePartenaire($user->id),
            'status' => $status,
        ]);

        return [$user, $account];
    }

    private function product(string $type, ?PartnerAccount $account): Model
    {
        $product = match ($type) {
            'parts' => Part::create([
                'name' => 'Pièce de test',
                'part_category_id' => PartCategory::firstOrCreate(['slug' => 'test'], ['name' => 'Test'])->id,
                'type' => 'aftermarket', 'condition' => 'new', 'selling_price' => 1000,
                'stock_quantity' => 5,
            ]),
            'accessories' => Accessory::create([
                'name' => 'Accessoire de test', 'category' => 'confort',
                'selling_price' => 1000, 'stock_quantity' => 5,
            ]),
            'vehicles' => $this->vehicle(),
        };

        $product->forceFill(['owner_partner_account_id' => $account?->id])->save();

        return $product->refresh();
    }

    private function vehicle(): Vehicle
    {
        $brand = Brand::firstOrCreate(['slug' => 'marque-test'], ['name' => 'Marque de test']);
        $model = VehicleModel::firstOrCreate(['brand_id' => $brand->id, 'slug' => 'modele-test'], ['name' => 'Modèle de test']);
        $engine = EngineType::firstOrCreate(['code' => 'petrol'], ['label' => 'Essence']);

        return Vehicle::create([
            'reference' => 'TEST-'.Str::random(12),
            'brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'engine_type_id' => $engine->id, 'manufacturing_year' => 2018,
            'registration_status' => 'unregistered',
        ]);
    }

    private function image(Model $product, string $type, array $extra = []): Media
    {
        $media = $product->media()->create([
            'collection' => 'gallery', 'disk' => 'public',
            'path' => $type.'/'.$product->id.'/'.uniqid('photo-', true).'.jpg',
            'mime_type' => 'image/jpeg', 'size' => 50, 'position' => 7,
            'is_cover' => false, ...$extra,
        ]);
        Storage::disk($media->disk)->put($media->path, 'test file');

        return $media;
    }

    public static function products(): array
    {
        return [['vehicles'], ['parts'], ['accessories']];
    }

    public static function stockProducts(): array
    {
        return [['parts'], ['accessories']];
    }

    #[DataProvider('products')]
    public function test_un_partenaire_depose_et_lit_uniquement_la_galerie_de_son_produit(string $type): void
    {
        [$user, $account] = $this->seller();
        $product = $this->product($type, $account);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/partner/$type/{$product->id}/media", [
            'photos' => [UploadedFile::fake()->image('photo.jpg', 40, 30)],
            'disk' => 'local', 'mediable_id' => 999,
        ])->assertCreated()->assertJsonPath('data.0.collection', 'gallery')
            ->assertJsonPath('data.0.is_cover', true);
        $media = Media::findOrFail($response->json('data.0.id'));
        $this->assertSame('public', $media->disk);
        $this->assertSame($product->id, $media->mediable_id);
        Storage::disk('public')->assertExists($media->path);

        $this->getJson("/api/partner/$type/{$product->id}/media")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $media->id);
    }

    #[DataProvider('products')]
    public function test_les_medias_d_un_autre_compte_ou_du_stock_interne_restent_introuvables(string $type): void
    {
        [$user] = $this->seller();
        [, $otherAccount] = $this->seller();
        Sanctum::actingAs($user);

        foreach ([$otherAccount, null] as $account) {
            $product = $this->product($type, $account);
            $media = $this->image($product, $type);
            $base = "/api/partner/$type/{$product->id}/media";
            $this->getJson($base)->assertNotFound();
            $this->postJson($base, ['photos' => [UploadedFile::fake()->image('photo.jpg')]])->assertNotFound();
            $this->patchJson($base.'/reorder', ['ids' => [$media->id]])->assertNotFound();
            $this->patchJson("/api/partner/media/{$media->id}/cover")->assertNotFound();
            $this->deleteJson("/api/partner/media/{$media->id}")->assertNotFound();
            $this->assertDatabaseHas('media', ['id' => $media->id, 'position' => 7, 'is_cover' => false]);
            Storage::disk('public')->assertExists($media->path);
        }
    }

    #[DataProvider('products')]
    public function test_le_reordonnancement_verifie_tous_les_medias_avant_toute_ecriture(string $type): void
    {
        [$user, $account] = $this->seller();
        $product = $this->product($type, $account);
        $otherProduct = $this->product($type, $account);
        $own = $this->image($product, $type);
        $other = $this->image($otherProduct, $type);
        Sanctum::actingAs($user);

        $this->patchJson("/api/partner/$type/{$product->id}/media/reorder", ['ids' => [$own->id, $other->id]])
            ->assertNotFound();

        $this->assertEquals(7, $own->fresh()->position);
        $this->assertEquals(7, $other->fresh()->position);
    }

    public function test_documents_fichiers_prives_et_faux_chemins_ne_sont_pas_accessibles(): void
    {
        [$user, $account] = $this->seller();
        $part = $this->product('parts', $account);
        $public = $this->image($part, 'parts');
        $hidden = [
            $this->image($part, 'parts', ['collection' => 'documents']),
            $this->image($part, 'parts', ['disk' => 'local']),
            $this->image($part, 'parts', ['mime_type' => 'application/pdf']),
            $this->image($part, 'parts', ['path' => 'parts/999/document.jpg']),
            $this->image($part, 'parts', ['preview_path' => 'documents/private.pdf']),
        ];
        Sanctum::actingAs($user);

        $this->getJson("/api/partner/parts/{$part->id}/media")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $public->id);

        foreach ($hidden as $media) {
            $this->patchJson("/api/partner/media/{$media->id}/cover")->assertNotFound();
            $this->deleteJson("/api/partner/media/{$media->id}")->assertNotFound();
            $this->patchJson("/api/partner/parts/{$part->id}/media/reorder", ['ids' => [$media->id]])->assertNotFound();
            Storage::disk($media->disk)->assertExists($media->path);
        }
    }

    public function test_le_depot_refuse_documents_svg_et_images_hors_limites(): void
    {
        [$user, $account] = $this->seller();
        $part = $this->product('parts', $account);
        Sanctum::actingAs($user);
        $base = "/api/partner/parts/{$part->id}/media";

        foreach (['documents', 'damages'] as $collection) {
            $this->postJson($base, ['collection' => $collection, 'photos' => [UploadedFile::fake()->image('photo.jpg')]])
                ->assertUnprocessable()->assertJsonValidationErrors('collection');
        }

        foreach ([
            UploadedFile::fake()->createWithContent('document.pdf', '%PDF-1.7 test'),
            UploadedFile::fake()->createWithContent('image.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
            UploadedFile::fake()->image('photo.php'),
            UploadedFile::fake()->image('trop-large.png', 12001, 1),
        ] as $file) {
            $this->postJson($base, ['photos' => [$file]])->assertUnprocessable()->assertJsonValidationErrors('photos.0');
        }

        $this->assertDatabaseCount('media', 0);
    }

    public function test_le_depot_ne_peut_pas_utiliser_le_disque_prive(): void
    {
        [$user, $account] = $this->seller();
        $part = $this->product('parts', $account);
        Sanctum::actingAs($user);
        config(['media.disk' => 'local']);

        $this->postJson("/api/partner/parts/{$part->id}/media", ['photos' => [UploadedFile::fake()->image('photo.jpg')]])
            ->assertStatus(503);
        $this->assertDatabaseCount('media', 0);
    }

    public function test_couverture_ordre_et_suppression_restent_limites_a_la_galerie_publique(): void
    {
        [$user, $account] = $this->seller();
        $part = $this->product('parts', $account);
        $first = $this->image($part, 'parts', ['is_cover' => true, 'position' => 1]);
        $second = $this->image($part, 'parts', ['position' => 2]);
        $private = $this->image($part, 'parts', ['disk' => 'local', 'is_cover' => true, 'position' => 0]);
        $second->update(['preview_path' => VignetteApercu::chemin($second->path)]);
        Storage::disk('public')->put($second->preview_path, 'preview');
        Sanctum::actingAs($user);

        $this->patchJson("/api/partner/parts/{$part->id}/media/reorder", ['ids' => [$second->id, $first->id]])->assertOk();
        $this->patchJson("/api/partner/media/{$second->id}/cover")->assertOk()->assertJsonPath('data.is_cover', true);
        $this->assertFalse($first->fresh()->is_cover);
        $this->assertTrue($private->fresh()->is_cover);

        $this->deleteJson("/api/partner/media/{$second->id}")->assertOk();
        $this->assertDatabaseMissing('media', ['id' => $second->id]);
        $this->assertTrue($first->fresh()->is_cover);
        Storage::disk('public')->assertMissing($second->path);
        Storage::disk('public')->assertMissing($second->preview_path);
        Storage::disk('local')->assertExists($private->path);
    }

    #[DataProvider('stockProducts')]
    public function test_le_partenaire_ajuste_son_stock_et_sa_disponibilite(string $type): void
    {
        [$user, $account] = $this->seller();
        $product = $this->product($type, $account);
        Sanctum::actingAs($user);
        $base = "/api/partner/$type/{$product->id}";

        foreach ([['increase', 3, 8], ['decrease', 2, 6], ['set', 4, 4]] as [$operation, $quantity, $expected]) {
            $this->postJson($base.'/stock', compact('operation', 'quantity'))
                ->assertOk()->assertExactJson(['id' => $product->id, 'stock_quantity' => $expected, 'is_available' => true]);
            $this->assertSame($expected, $product->fresh()->stock_quantity);
        }

        $this->patchJson($base.'/availability')->assertOk()->assertJsonPath('is_available', false);
        $this->patchJson($base.'/availability')->assertOk()->assertJsonPath('is_available', true);

        if ($type === 'parts') {
            $this->assertDatabaseCount('stock_movements', 3);
            $this->assertSame([$user->id], StockMovement::pluck('user_id')->unique()->values()->all());
            $this->assertSame($product->id, StockMovement::firstOrFail()->part_id);
        } else {
            $this->assertDatabaseCount('stock_movements', 0);
        }
    }

    #[DataProvider('stockProducts')]
    public function test_aucun_stock_d_autrui_ni_interne_ne_peut_etre_modifie(string $type): void
    {
        [$user] = $this->seller();
        [, $otherAccount] = $this->seller();
        Sanctum::actingAs($user);

        foreach ([$otherAccount, null] as $account) {
            $product = $this->product($type, $account);
            $this->postJson("/api/partner/$type/{$product->id}/stock", ['operation' => 'set', 'quantity' => 0])->assertNotFound();
            $this->patchJson("/api/partner/$type/{$product->id}/availability")->assertNotFound();
            $this->assertSame(5, $product->fresh()->stock_quantity);
            $this->assertTrue($product->fresh()->is_available);
        }

        $this->assertDatabaseCount('stock_movements', 0);
    }

    #[DataProvider('stockProducts')]
    public function test_le_stock_ne_peut_pas_devenir_negatif_ou_deborder(string $type): void
    {
        [$user, $account] = $this->seller();
        $product = $this->product($type, $account);
        Sanctum::actingAs($user);

        foreach ([['decrease', 6], ['increase', -1], ['increase', 2147483647], ['set', 2147483648]] as [$operation, $quantity]) {
            $this->postJson("/api/partner/$type/{$product->id}/stock", compact('operation', 'quantity'))
                ->assertUnprocessable()->assertJsonValidationErrors('quantity');
            $this->assertSame(5, $product->fresh()->stock_quantity);
        }

        $this->assertDatabaseCount('stock_movements', 0);
    }
}
