<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Resources\VehicleResource;
use App\Models\EquipmentDocument;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EquipmentDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        config(['filesystems.default' => 'public']);
        config(['equipment.documents_disk' => 'local']);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    private function equipment(array $extra = []): Vehicle
    {
        return Vehicle::create(array_replace([
            'reference' => 'DOCUMENT-'.Str::uuid(), 'asset_kind' => 'equipment',
            'equipment_type' => 'excavator',
            'equipment_details' => ['brand_label' => 'Marque déclarée', 'model_label' => 'Pelle déclarée'],
            'condition' => 'used', 'status' => 'in_stock', 'availability' => 'sale',
            'sale_price' => 1000000, 'published_at' => now(),
        ], $extra));
    }

    private function account(string $status = 'approved'): PartnerAccount
    {
        $user = User::factory()->create(['role' => 'partner']);

        return PartnerAccount::create([
            'user_id' => $user->id, 'company_name' => 'Entreprise '.$user->id,
            'contact_name' => 'Responsable', 'phone' => $this->numeroDePartenaire($user->id),
            'status' => $status,
        ]);
    }

    /** Real bytes and Symfony MIME detection, independent of the claimed filename/type. */
    private function upload(string $name = 'justificatif.pdf', string $contents = self::PDF, string $claimedMime = 'application/pdf'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'equipment-test-');
        file_put_contents($path, $contents);
        $this->temporaryFiles[] = $path;

        return new UploadedFile($path, $name, $claimedMime, UPLOAD_ERR_OK, true);
    }

    private function url(Vehicle $vehicle, bool $partner = false): string
    {
        return '/api/'.($partner ? 'partner/' : '').'vehicles/'.$vehicle->id.'/equipment-documents';
    }

    private function document(Vehicle $vehicle): EquipmentDocument
    {
        $path = 'equipment-documents/'.$vehicle->id.'/'.Str::uuid().'.pdf';
        Storage::disk('local')->put($path, self::PDF, 'private');

        return EquipmentDocument::create([
            'vehicle_id' => $vehicle->id, 'document_type' => 'ownership',
            'original_name' => 'PRIVATE-DOCUMENT.pdf', 'mime_type' => 'application/pdf',
            'size' => strlen(self::PDF), 'path' => $path, 'status' => 'provided',
        ]);
    }

    public function test_an_owned_document_is_uploaded_listed_downloaded_and_deleted_privately(): void
    {
        $account = $this->account();
        $equipment = $this->equipment(['owner_partner_account_id' => $account->id]);
        Sanctum::actingAs($account->user);
        $url = $this->url($equipment, true);

        $response = $this->post($url, [
            'document' => $this->upload('C:\\dossier\\titre.pdf'),
            'document_type' => 'ownership', 'expires_on' => '2027-11-30',
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.document_type', 'ownership')
            ->assertJsonPath('data.original_name', 'titre.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.size', strlen(self::PDF))
            ->assertJsonPath('data.status', 'provided')
            ->assertJsonPath('data.expires_on', '2027-11-30');

        $document = EquipmentDocument::findOrFail($response->json('data.id'));
        $this->assertSame($account->user_id, $document->uploaded_by);
        $this->assertMatchesRegularExpression('~^equipment-documents/'.$equipment->id.'/[0-9a-f-]{36}\.pdf$~', $document->path);
        $this->assertTrue(Str::isUuid(pathinfo($document->path, PATHINFO_FILENAME)));
        $this->assertSame('private', Storage::disk('local')->getVisibility($document->path));
        Storage::disk('local')->assertExists($document->path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([
            'id', 'document_type', 'original_name', 'mime_type', 'size', 'expires_on', 'status', 'uploaded_at',
        ], array_keys($response->json('data')));
        $this->assertStringNotContainsString($document->path, $response->getContent());
        $this->assertArrayNotHasKey('path', $document->toArray());

        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', $response->json('data'));
        $download = $this->get($url.'/'.$document->id.'/download')->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertDownload('titre.pdf');
        $this->assertStringContainsString('private', $download->headers->get('cache-control'));
        $this->assertStringContainsString('no-store', $download->headers->get('cache-control'));
        $this->assertSame(self::PDF, $download->streamedContent());

        $this->deleteJson($url.'/'.$document->id)->assertOk();
        Storage::disk('local')->assertMissing($document->path);
        $this->assertDatabaseMissing('equipment_documents', ['id' => $document->id]);
        $this->getJson($url)->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_all_supported_document_types_are_optional_evidence_with_no_verification_claim(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $equipment = $this->equipment();
        $this->getJson($this->url($equipment))->assertOk()->assertExactJson(['data' => []]);

        foreach (EquipmentDocument::TYPES as $type) {
            $this->postJson($this->url($equipment), [
                'document' => UploadedFile::fake()->create('evidence.pdf', 12, 'application/pdf'),
                'document_type' => $type,
            ])->assertCreated()->assertJsonPath('data.document_type', $type)
                ->assertJsonPath('data.status', 'provided')->assertJsonPath('data.expires_on', null);
        }
        $this->assertDatabaseCount('equipment_documents', 5);
    }

    public function test_only_catalog_management_staff_can_access_document_routes(): void
    {
        $equipment = $this->equipment();
        $document = $this->document($equipment);
        $url = $this->url($equipment);

        foreach (UserRole::cases() as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $allowed = $role->canManageCatalog();
            $this->getJson($url)->assertStatus($allowed ? 200 : 403);
            $this->getJson($url.'/'.$document->id.'/download')->assertStatus($allowed ? 200 : 403);
            $response = $this->postJson($url, [
                'document' => $this->upload(), 'document_type' => 'inspection',
            ])->assertStatus($allowed ? 201 : 403);
            if ($allowed) {
                $this->deleteJson($url.'/'.$response->json('data.id'))->assertOk();
            } else {
                $this->deleteJson($url.'/'.$document->id)->assertForbidden();
            }
        }
        Storage::disk('local')->assertExists($document->path);
    }

    public function test_unauthenticated_and_public_routes_cannot_read_or_modify_documents(): void
    {
        $equipment = $this->equipment();
        $document = $this->document($equipment);
        foreach ([false, true] as $partner) {
            $url = $this->url($equipment, $partner);
            $this->getJson($url)->assertUnauthorized();
            $this->getJson($url.'/'.$document->id.'/download')->assertUnauthorized();
            $this->postJson($url, ['document' => $this->upload(), 'document_type' => 'other'])->assertUnauthorized();
            $this->deleteJson($url.'/'.$document->id)->assertUnauthorized();
        }
        $this->getJson('/api/catalog/vehicles/'.$equipment->id.'/equipment-documents')->assertNotFound();
        $this->getJson('/api/catalog/vehicles/'.$equipment->id.'/equipment-documents/'.$document->id.'/download')->assertNotFound();
        $this->get('/storage/'.$document->path)->assertForbidden();
        Storage::disk('local')->assertExists($document->path);
    }

    public function test_a_partner_cannot_access_foreign_internal_or_cross_vehicle_documents(): void
    {
        $owner = $this->account();
        $other = $this->account();
        $own = $this->equipment(['owner_partner_account_id' => $owner->id]);
        $ownDocument = $this->document($own);
        Sanctum::actingAs($owner->user);

        foreach ([$other->id, null] as $foreignOwner) {
            $foreign = $this->equipment(['owner_partner_account_id' => $foreignOwner]);
            $document = $this->document($foreign);
            $url = $this->url($foreign, true);
            $this->getJson($url)->assertNotFound();
            $this->postJson($url, ['document' => $this->upload(), 'document_type' => 'other'])->assertNotFound();
            $this->getJson($url.'/'.$document->id.'/download')->assertNotFound();
            $this->deleteJson($url.'/'.$document->id)->assertNotFound();
            $this->getJson($this->url($own, true).'/'.$document->id.'/download')->assertNotFound();
            $this->deleteJson($this->url($own, true).'/'.$document->id)->assertNotFound();
            Storage::disk('local')->assertExists($document->path);
        }
        $this->getJson($this->url($own, true).'/'.$ownDocument->id.'/download')->assertOk();
    }

    public function test_cross_vehicle_documents_are_also_rejected_for_staff(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $first = $this->equipment();
        $second = $this->equipment();
        $document = $this->document($second);
        $this->getJson($this->url($first).'/'.$document->id.'/download')->assertNotFound();
        $this->deleteJson($this->url($first).'/'.$document->id)->assertNotFound();
        Storage::disk('local')->assertExists($document->path);
    }

    public function test_unapproved_and_disabled_partner_accounts_cannot_access_documents(): void
    {
        foreach (['pending', 'rejected', 'suspended', 'approved'] as $status) {
            $account = $this->account($status);
            if ($status === 'approved') {
                $account->user->update(['is_active' => false]);
            }
            $equipment = $this->equipment(['owner_partner_account_id' => $account->id]);
            $document = $this->document($equipment);
            Sanctum::actingAs($account->user->fresh());
            $url = $this->url($equipment, true);
            $this->getJson($url)->assertForbidden();
            $this->postJson($url, ['document' => $this->upload(), 'document_type' => 'other'])->assertForbidden();
            $this->getJson($url.'/'.$document->id.'/download')->assertForbidden();
            $this->deleteJson($url.'/'.$document->id)->assertForbidden();
        }
    }

    public function test_road_vehicle_document_routes_are_unavailable_to_staff_and_owners(): void
    {
        $account = $this->account();
        $road = $this->equipment(['asset_kind' => 'road_vehicle', 'equipment_type' => null, 'equipment_details' => null,
            'owner_partner_account_id' => $account->id]);
        $document = $this->document($road);

        foreach ([false, true] as $partner) {
            Sanctum::actingAs($partner ? $account->user : User::factory()->create(['role' => 'admin']));
            $url = $this->url($road, $partner);
            $this->getJson($url)->assertNotFound();
            $this->postJson($url, ['document' => $this->upload(), 'document_type' => 'other'])->assertNotFound();
            $this->getJson($url.'/'.$document->id.'/download')->assertNotFound();
            $this->deleteJson($url.'/'.$document->id)->assertNotFound();
        }
        Storage::disk('local')->assertExists($document->path);
    }

    public function test_server_controlled_metadata_and_unknown_fields_are_rejected_even_when_null(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $equipment = $this->equipment();
        foreach (['status', 'verified', 'verified_at', 'path', 'url', 'disk', 'vehicle_id', 'uploaded_by', 'mime_type', 'size', 'unknown', 'metadata.forged'] as $field) {
            $this->postJson($this->url($equipment), [
                'document' => $this->upload(), 'document_type' => 'inspection', $field => null,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertDatabaseCount('equipment_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_mime_detection_rejects_executables_disguised_as_allowed_files(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $equipment = $this->equipment();
        foreach (['application/pdf', 'image/jpeg', 'image/png', 'image/webp'] as $claimedMime) {
            $this->postJson($this->url($equipment), [
                'document' => $this->upload('document.pdf', '<?php echo "danger";', $claimedMime),
                'document_type' => 'other',
            ])->assertUnprocessable()->assertJsonValidationErrors('document');
        }
        $this->assertDatabaseCount('equipment_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_all_allowed_mimes_use_a_server_chosen_extension_and_the_eight_megabyte_limit(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $equipment = $this->equipment();
        foreach (['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'] as $mime => $extension) {
            $response = $this->postJson($this->url($equipment), [
                'document' => UploadedFile::fake()->create('untrusted.bin', 8192, $mime), 'document_type' => 'other',
            ])->assertCreated();
            $document = EquipmentDocument::findOrFail($response->json('data.id'));
            $this->assertSame($extension, pathinfo($document->path, PATHINFO_EXTENSION));
            $this->postJson($this->url($equipment), [
                'document' => UploadedFile::fake()->create('oversize.'.$extension, 8193, $mime), 'document_type' => 'other',
            ])->assertUnprocessable()->assertJsonValidationErrors('document');
        }
        $this->assertDatabaseCount('equipment_documents', 4);
    }

    public function test_missing_files_unknown_types_and_invalid_expiry_dates_are_rejected(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $equipment = $this->equipment();
        $this->postJson($this->url($equipment), ['document_type' => 'ownership'])
            ->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->postJson($this->url($equipment), ['document' => $this->upload(), 'document_type' => 'verified'])
            ->assertUnprocessable()->assertJsonValidationErrors('document_type');
        foreach (['2027-02-30', 'tomorrow', '2027-01-01T00:00:00Z'] as $expiry) {
            $this->postJson($this->url($equipment), [
                'document' => $this->upload(), 'document_type' => 'insurance', 'expires_on' => $expiry,
            ])->assertUnprocessable()->assertJsonValidationErrors('expires_on');
        }
        $this->assertDatabaseCount('equipment_documents', 0);
    }

    public function test_corrupt_storage_paths_cannot_be_downloaded_or_deleted(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $equipment = $this->equipment();
        $document = $this->document($equipment);
        $originalPath = $document->path;
        $sensitive = 'sensitive.pdf';
        Storage::disk('local')->put($sensitive, 'do not read or delete');

        foreach ([
            '../sensitive.pdf', 'equipment-documents/'.$equipment->id.'/../../sensitive.pdf',
            '/etc/passwd', 'equipment-documents/'.$equipment->id.'/not-a-uuid.pdf',
            'equipment-documents/99999/'.Str::uuid().'.pdf',
            'equipment-documents/'.$equipment->id.'/'.Str::uuid().'.php',
            'equipment-documents\\'.$equipment->id.'\\'.Str::uuid().'.pdf',
        ] as $path) {
            $document->update(['path' => $path]);
            $this->getJson($this->url($equipment).'/'.$document->id.'/download')->assertNotFound();
            $this->deleteJson($this->url($equipment).'/'.$document->id)->assertNotFound();
            $this->assertDatabaseHas('equipment_documents', ['id' => $document->id]);
        }
        Storage::disk('local')->assertExists($originalPath);
        $this->assertSame('do not read or delete', Storage::disk('local')->get($sensitive));
    }

    public function test_a_missing_file_returns_404_and_its_stale_record_can_be_deleted(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $equipment = $this->equipment();
        $document = $this->document($equipment);
        Storage::disk('local')->delete($document->path);
        $this->getJson($this->url($equipment).'/'.$document->id.'/download')->assertNotFound();
        $this->deleteJson($this->url($equipment).'/'.$document->id)->assertOk();
        $this->assertDatabaseMissing('equipment_documents', ['id' => $document->id]);
    }

    public function test_existing_documents_keep_their_private_disk_after_a_configuration_change(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $equipment = $this->equipment();
        $response = $this->postJson($this->url($equipment), [
            'document' => $this->upload(), 'document_type' => 'registration',
        ])->assertCreated();
        $document = EquipmentDocument::findOrFail($response->json('data.id'));
        $this->assertSame('local', $document->disk);
        $this->assertArrayNotHasKey('disk', $document->toArray());

        Storage::fake('next_private');
        config(['equipment.documents_disk' => 'next_private']);
        $this->get($this->url($equipment).'/'.$document->id.'/download')->assertOk()
            ->assertStreamedContent(self::PDF);
        $this->deleteJson($this->url($equipment).'/'.$document->id)->assertOk();
        Storage::disk('local')->assertMissing($document->path);
        $this->assertSame([], Storage::disk('next_private')->allFiles());
    }

    public function test_public_or_missing_disk_configuration_fails_closed_without_creating_files(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $equipment = $this->equipment();
        Storage::fake('exposed');
        config(['filesystems.disks.exposed.visibility' => 'public']);
        foreach ([null, 'public', 'exposed', 'does-not-exist'] as $disk) {
            config(['equipment.documents_disk' => $disk]);
            $this->getJson($this->url($equipment))->assertStatus(503);
            $this->postJson($this->url($equipment), [
                'document' => $this->upload(), 'document_type' => 'ownership',
            ])->assertStatus(503)->assertJsonPath('message', 'Les justificatifs sont temporairement indisponibles.');
        }
        $this->assertDatabaseCount('equipment_documents', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('exposed')->allFiles());
    }

    public function test_production_requires_a_separate_durable_private_bucket(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $equipment = $this->equipment();
        $existing = $this->document($equipment);
        $this->app->detectEnvironment(fn () => 'production');

        // An existing local file cannot make production's temporary disk valid.
        $this->getJson($this->url($equipment).'/'.$existing->id.'/download')->assertStatus(503);
        $this->deleteJson($this->url($equipment).'/'.$existing->id)->assertStatus(503);
        foreach ([
            ['disk' => null, 'driver' => 's3', 'visibility' => 'private', 'bucket' => 'private-evidence'],
            ['disk' => 'private_test', 'driver' => 'local', 'visibility' => 'private', 'bucket' => 'private-evidence'],
            ['disk' => 'private_test', 'driver' => 's3', 'visibility' => 'public', 'bucket' => 'private-evidence'],
            ['disk' => 'private_test', 'driver' => 's3', 'visibility' => 'private', 'bucket' => null],
            ['disk' => 'private_test', 'driver' => 's3', 'visibility' => 'private', 'bucket' => 'public-gallery'],
        ] as $configuration) {
            config([
                'equipment.documents_disk' => $configuration['disk'],
                'filesystems.disks.private_test' => $configuration,
                'media.disk' => 's3', 'filesystems.disks.s3.bucket' => 'public-gallery',
            ]);
            $this->postJson($this->url($equipment), [
                'document' => $this->upload(), 'document_type' => 'other',
            ])->assertStatus(503);
        }
        $this->assertDatabaseCount('equipment_documents', 1);
        Storage::disk('local')->assertExists($existing->path);

        // The fake intercepts all writes. No bucket or cloud object is created.
        Storage::fake('private_test');
        config([
            'equipment.documents_disk' => 'private_test',
            'filesystems.disks.private_test.driver' => 's3',
            'filesystems.disks.private_test.visibility' => 'private',
            'filesystems.disks.private_test.bucket' => 'private-evidence',
        ]);
        $response = $this->postJson($this->url($equipment), [
            'document' => $this->upload(), 'document_type' => 'other',
        ])->assertCreated();
        $document = EquipmentDocument::findOrFail($response->json('data.id'));
        $this->assertSame('private_test', $document->disk);
        Storage::disk('private_test')->assertExists($document->path);
        $this->get($this->url($equipment).'/'.$document->id.'/download')->assertOk()
            ->assertStreamedContent(self::PDF);
    }

    public function test_a_failed_storage_write_never_creates_a_document_record(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $equipment = $this->equipment();
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->postJson($this->url($equipment), [
            'document' => $this->upload(), 'document_type' => 'other',
        ])->assertStatus(503)->assertJsonPath('message', 'Les justificatifs sont temporairement indisponibles.');
        $this->assertDatabaseCount('equipment_documents', 0);
    }

    public function test_a_failed_storage_deletion_keeps_the_record_available_for_retry(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $equipment = $this->equipment();
        $document = $this->document($equipment);
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->with($document->path)->once()->andReturn(true);
        $disk->shouldReceive('delete')->with($document->path)->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->deleteJson($this->url($equipment).'/'.$document->id)->assertStatus(503);
        $this->assertDatabaseHas('equipment_documents', ['id' => $document->id, 'path' => $document->path]);
    }

    public function test_a_database_failure_after_upload_removes_the_private_file(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $equipment = $this->equipment();
        Event::listen('eloquent.creating: '.EquipmentDocument::class, function () {
            throw new \RuntimeException('Simulated database failure');
        });
        $this->withoutExceptionHandling();
        try {
            $this->postJson($this->url($equipment), ['document' => $this->upload(), 'document_type' => 'other']);
            $this->fail('The simulated database failure was not propagated.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated database failure', $exception->getMessage());
        }
        $this->assertDatabaseCount('equipment_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_rollback_refuses_to_drop_existing_document_metadata_and_preserves_the_private_file(): void
    {
        $equipment = $this->equipment();
        $document = $this->document($equipment);
        $before = $document->fresh()->getRawOriginal();
        $migration = require database_path('migrations/2026_11_07_000003_create_equipment_documents.php');

        try {
            $migration->down();
            $this->fail('Rollback must refuse to remove existing equipment documents.');
        } catch (\RuntimeException $exception) {
            $this->assertSame(
                'Retrait refusé : des justificatifs d’engins existent. Aucune donnée ne sera supprimée.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('equipment_documents', 1);
        $this->assertSame($before, $document->fresh()->getRawOriginal());
        Storage::disk('local')->assertExists($document->path);
        $this->assertSame(self::PDF, Storage::disk('local')->get($document->path));
    }

    public function test_documents_are_absent_from_the_public_vehicle_resource_and_media_gallery(): void
    {
        $equipment = $this->equipment();
        $document = $this->document($equipment);
        // Even an eager-loaded private relationship must not leak to a public serializer.
        $equipment->setRelation('equipmentDocuments', collect([$document]));
        $request = Request::create('/api/catalog/vehicles/'.$equipment->id);
        $data = (new VehicleResource($equipment))->resolve($request);
        $json = json_encode($data, JSON_THROW_ON_ERROR);
        foreach (['equipment_documents', 'equipmentDocuments', $document->path, $document->original_name, 'storage_url'] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $json);
        }
        $this->assertDatabaseCount('media', 0);
        $this->getJson('/api/catalog/vehicles/'.$equipment->id)->assertOk()
            ->assertJsonMissingPath('data.equipment_documents')->assertJsonMissingPath('data.equipmentDocuments');
    }
}
