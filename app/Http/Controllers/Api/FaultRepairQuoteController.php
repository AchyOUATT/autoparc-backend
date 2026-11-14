<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FaultRepairQuote;
use App\Models\PrivateListingFault;
use App\Models\PrivateVehicleListing;
use App\Models\Vehicle;
use App\Models\VehicleFault;
use App\Services\FaultDeclarations;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Private quotes stay off the public API. Only a staff-published redacted copy is public. */
class FaultRepairQuoteController extends Controller
{
    public function storeForListing(Request $request, PrivateVehicleListing $listing, PrivateListingFault $fault): JsonResponse
    {
        $this->assertListingFault($listing, $fault);
        $this->authorizeListingOwnerOrStaff($request, $listing);

        return $this->storePrivate($request, $fault);
    }

    public function storeForVehicle(Request $request, Vehicle $vehicle, VehicleFault $fault): JsonResponse
    {
        $this->assertVehicleFault($vehicle, $fault);
        $this->authorizeVehicleEditor($request, $vehicle);

        return $this->storePrivate($request, $fault);
    }

    public function downloadPrivateForListing(Request $request, PrivateVehicleListing $listing, PrivateListingFault $fault): StreamedResponse
    {
        $this->assertListingFault($listing, $fault);
        $this->authorizeListingOwnerOrStaff($request, $listing);

        return $this->download($this->privateOrFail($fault));
    }

    public function downloadPrivateForVehicle(Request $request, Vehicle $vehicle, VehicleFault $fault): StreamedResponse
    {
        $this->assertVehicleFault($vehicle, $fault);
        $this->authorizeVehicleEditor($request, $vehicle);

        return $this->download($this->privateOrFail($fault));
    }

    public function destroyPrivateForListing(Request $request, PrivateVehicleListing $listing, PrivateListingFault $fault): JsonResponse
    {
        $this->assertListingFault($listing, $fault);
        $this->authorizeListingOwnerOrStaff($request, $listing);
        $this->deleteQuote($this->privateOrFail($fault));

        return response()->json(['message' => __('Devis retiré.')]);
    }

    public function destroyPrivateForVehicle(Request $request, Vehicle $vehicle, VehicleFault $fault): JsonResponse
    {
        $this->assertVehicleFault($vehicle, $fault);
        $this->authorizeVehicleEditor($request, $vehicle);
        $this->deleteQuote($this->privateOrFail($fault));

        return response()->json(['message' => __('Devis retiré.')]);
    }

    public function publishForListing(Request $request, PrivateVehicleListing $listing, PrivateListingFault $fault): JsonResponse
    {
        $this->assertListingFault($listing, $fault);
        $this->authorizeStaff($request);

        return $this->storePublished($request, $fault);
    }

    public function publishForVehicle(Request $request, Vehicle $vehicle, VehicleFault $fault): JsonResponse
    {
        $this->assertVehicleFault($vehicle, $fault);
        $this->authorizeStaff($request);

        return $this->storePublished($request, $fault);
    }

    public function destroyPublishedForListing(Request $request, PrivateVehicleListing $listing, PrivateListingFault $fault): JsonResponse
    {
        $this->assertListingFault($listing, $fault);
        $this->authorizeStaff($request);
        $quote = $fault->publishedQuote;
        abort_unless($quote, 404);
        $this->deleteQuote($quote);

        return response()->json(['message' => __('Copie publique retirée.')]);
    }

    public function destroyPublishedForVehicle(Request $request, Vehicle $vehicle, VehicleFault $fault): JsonResponse
    {
        $this->assertVehicleFault($vehicle, $fault);
        $this->authorizeStaff($request);
        $quote = $fault->publishedQuote;
        abort_unless($quote, 404);
        $this->deleteQuote($quote);

        return response()->json(['message' => __('Copie publique retirée.')]);
    }

    public function showPublished(FaultRepairQuote $quote): StreamedResponse
    {
        abort_unless($quote->isPublished(), 404);

        return $this->download($quote, public: true);
    }

    private function storePrivate(Request $request, VehicleFault|PrivateListingFault $fault): JsonResponse
    {
        $file = $this->acceptedFile($request);
        $disk = $this->privateDiskName();
        $path = $this->storeFile($file, $disk, 'private', visibility: 'private');
        $quote = $this->replaceQuote($fault, FaultRepairQuote::KIND_PRIVATE, $disk, $path, $file, $request->user()->id);
        $target = $fault instanceof VehicleFault ? $fault->vehicle : $fault->listing;

        return response()->json([
            'data' => FaultDeclarations::row($fault->fresh(['privateQuote', 'publishedQuote']), true, $request->user(), $target),
        ], 201);
    }

    private function storePublished(Request $request, VehicleFault|PrivateListingFault $fault): JsonResponse
    {
        $file = $this->acceptedFile($request);
        $disk = config('media.disk', 'public');
        $settings = config('filesystems.disks.'.$disk);
        abort_unless(is_array($settings) && ($settings['visibility'] ?? null) === 'public', 503, __('Le stockage des photos est indisponible.'));
        $path = $this->storeFile($file, $disk, 'published', visibility: 'public', stripImage: true);
        $quote = $this->replaceQuote($fault, FaultRepairQuote::KIND_PUBLISHED, $disk, $path, $file, $request->user()->id);
        $target = $fault instanceof VehicleFault ? $fault->vehicle : $fault->listing;

        return response()->json([
            'data' => FaultDeclarations::row($fault->fresh(['privateQuote', 'publishedQuote']), true, $request->user(), $target),
        ], 201);
    }

    private function acceptedFile(Request $request)
    {
        $rules = ['document' => ['required', 'file', 'mimetypes:'.implode(',', array_keys(FaultRepairQuote::MIME_EXTENSIONS)), 'max:8192']];
        $unknown = array_diff(array_keys($request->all()), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('Ce champ n’est pas accepté.')));
        }
        $request->validate($rules);
        $file = $request->file('document');
        $mime = $file->getMimeType();
        abort_unless(isset(FaultRepairQuote::MIME_EXTENSIONS[$mime]), 422, __('Format de document non accepté.'));

        return $file;
    }

    private function storeFile($file, string $disk, string $kind, string $visibility, bool $stripImage = false): string
    {
        $extension = FaultRepairQuote::MIME_EXTENSIONS[$file->getMimeType()];
        $filename = Str::uuid().'.'.$extension;
        $directory = 'fault-repair-quotes/'.$kind;
        if ($stripImage && str_starts_with((string) $file->getMimeType(), 'image/') && function_exists('imagecreatefromstring')) {
            $image = imagecreatefromstring(file_get_contents($file->getRealPath()));
            abort_unless($image !== false, 422, __('Document illisible.'));
            $opaque = imagecreatetruecolor(imagesx($image), imagesy($image));
            abort_unless($opaque !== false, 503, __('Le stockage des photos est indisponible.'));
            imagefill($opaque, 0, 0, imagecolorallocate($opaque, 255, 255, 255));
            imagecopyresampled($opaque, $image, 0, 0, 0, 0, imagesx($image), imagesy($image), imagesx($image), imagesy($image));
            imagedestroy($image);
            ob_start();
            imagejpeg($opaque, null, 88);
            $bytes = ob_get_clean();
            imagedestroy($opaque);
            $filename = Str::uuid().'.jpg';
            $path = $directory.'/'.$filename;
            abort_unless(Storage::disk($disk)->put($path, $bytes, $visibility), 503, __('Le devis n’a pas pu être enregistré. Réessayez.'));

            return $path;
        }
        try {
            $path = $file->storeAs($directory, $filename, ['disk' => $disk, 'visibility' => $visibility]);
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, __('Les justificatifs sont temporairement indisponibles.'));
        }
        abort_unless(is_string($path) && $path !== '', 503, __('Les justificatifs sont temporairement indisponibles.'));

        return $path;
    }

    private function replaceQuote(VehicleFault|PrivateListingFault $fault, string $kind, string $disk, string $path, $file, int $userId): FaultRepairQuote
    {
        return DB::transaction(function () use ($fault, $kind, $disk, $path, $file, $userId) {
            $existing = $fault->repairQuotes()->where('kind', $kind)->lockForUpdate()->first();
            $quote = $existing ?? $fault->repairQuotes()->make(['kind' => $kind]);
            $previousDisk = $existing?->disk;
            $previousPath = $existing?->path;
            $quote->fill([
                'original_name' => $this->safeFilename($file->getClientOriginalName()),
                'mime_type' => $kind === FaultRepairQuote::KIND_PUBLISHED && str_ends_with($path, '.jpg')
                    ? 'image/jpeg'
                    : $file->getMimeType(),
                'size' => Storage::disk($disk)->size($path),
                'disk' => $disk,
                'path' => $path,
                'uploaded_by' => $userId,
            ])->save();
            if ($previousPath && ($previousDisk !== $disk || $previousPath !== $path)) {
                try {
                    Storage::disk($previousDisk)->delete($previousPath);
                } catch (\Throwable) {
                }
            }

            return $quote;
        });
    }

    private function deleteQuote(FaultRepairQuote $quote): void
    {
        try {
            $deleted = ! Storage::disk($quote->disk)->exists($quote->path) || Storage::disk($quote->disk)->delete($quote->path);
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, __('Les justificatifs sont temporairement indisponibles.'));
        }
        abort_unless($deleted, 503, __('Les justificatifs sont temporairement indisponibles.'));
        $quote->delete();
    }

    private function download(FaultRepairQuote $quote, bool $public = false): StreamedResponse
    {
        $storage = $this->storage($quote);
        try {
            $exists = $storage->exists($quote->path);
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, __('Les justificatifs sont temporairement indisponibles.'));
        }
        abort_unless($exists, 404);
        $this->assertSafePath($quote);

        return $storage->download($quote->path, $quote->original_name, [
            'Content-Type' => $quote->mime_type,
            'Cache-Control' => $public ? 'public, max-age=86400' : 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function storage(FaultRepairQuote $quote): FilesystemAdapter
    {
        try {
            return Storage::disk($quote->disk);
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, __('Les justificatifs sont temporairement indisponibles.'));
        }
    }

    private function assertSafePath(FaultRepairQuote $quote): void
    {
        $path = (string) $quote->path;
        $kind = $quote->kind;
        abort_unless(dirname($path) === 'fault-repair-quotes/'.$kind
            && ! str_contains($path, '\\')
            && Str::isUuid(pathinfo($path, PATHINFO_FILENAME))
            && in_array(pathinfo($path, PATHINFO_EXTENSION), FaultRepairQuote::MIME_EXTENSIONS, true), 404);
    }

    private function privateOrFail(VehicleFault|PrivateListingFault $fault): FaultRepairQuote
    {
        $quote = $fault->privateQuote;
        abort_unless($quote, 404);

        return $quote;
    }

    private function privateDiskName(): string
    {
        $disk = config('equipment.documents_disk');
        $settings = is_string($disk) ? config('filesystems.disks.'.$disk) : null;
        $valid = is_string($disk) && $disk !== 'public' && is_array($settings)
            && ($settings['visibility'] ?? 'private') === 'private';
        if (app()->isProduction()) {
            $bucket = $settings['bucket'] ?? null;
            $valid = $valid && ($settings['driver'] ?? null) === 's3'
                && ($settings['visibility'] ?? null) === 'private'
                && is_string($bucket) && trim($bucket) !== '';
            $galleryDisk = config('media.disk', 'public');
            $galleryBucket = config('filesystems.disks.'.$galleryDisk.'.bucket');
            $valid = $valid && ($galleryBucket === null || $bucket !== $galleryBucket);
        }
        abort_unless($valid, 503, __('Les justificatifs sont temporairement indisponibles.'));

        return $disk;
    }

    private function safeFilename(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = mb_substr(trim($name), 0, 255);

        return $name !== '' && ! in_array($name, ['.', '..'], true) ? $name : 'devis';
    }

    private function assertListingFault(PrivateVehicleListing $listing, PrivateListingFault $fault): void
    {
        abort_unless((int) $fault->private_vehicle_listing_id === (int) $listing->id, 404);
    }

    private function assertVehicleFault(Vehicle $vehicle, VehicleFault $fault): void
    {
        abort_unless((int) $fault->vehicle_id === (int) $vehicle->id, 404);
    }

    private function authorizeListingOwnerOrStaff(Request $request, PrivateVehicleListing $listing): void
    {
        $user = $request->user();
        abort_unless($user, 401);
        if ($this->isStaffEditor($user)) {
            return;
        }
        abort_unless((int) $listing->user_id === (int) $user->id, 404);
    }

    private function authorizeVehicleEditor(Request $request, Vehicle $vehicle): void
    {
        $user = $request->user();
        abort_unless($user, 401);
        abort_unless(FaultDeclarations::canManageStock($request, $vehicle) || $this->isStaffEditor($user), 403);
    }

    private function authorizeStaff(Request $request): void
    {
        abort_unless($this->isStaffEditor($request->user()), 403);
    }

    private function isStaffEditor($user): bool
    {
        return $user !== null && $user->isStaff()
            && ($user->role?->canManageFaults() || $user->role?->canManageCatalog());
    }
}
