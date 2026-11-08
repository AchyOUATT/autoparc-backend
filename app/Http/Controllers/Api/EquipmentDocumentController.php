<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EquipmentDocument;
use App\Models\Vehicle;
use App\Support\PartnerOwnership;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Authenticated document access only; storage paths are never returned. */
class EquipmentDocumentController extends Controller
{
    private const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function index(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorizeVehicle($request, $vehicle);
        $this->privateDiskName();

        return response()->json(['data' => EquipmentDocument::where('vehicle_id', $vehicle->id)
            ->orderByDesc('id')->get()->map(fn (EquipmentDocument $document) => $this->present($document))->values()]);
    }

    public function store(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorizeVehicle($request, $vehicle);
        $rules = [
            'document' => ['required', 'file', 'mimetypes:'.implode(',', array_keys(self::MIME_EXTENSIONS)), 'max:8192'],
            'document_type' => ['required', Rule::in(EquipmentDocument::TYPES)],
            'expires_on' => ['nullable', 'date_format:Y-m-d'],
        ];
        // Document state and storage ownership are server-controlled. Reject
        // unknown inputs as well as explicit nulls for forged metadata.
        $unknown = array_diff(array_keys($request->all()), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('Ce champ n’est pas accepté.')));
        }
        $data = $request->validate($rules);
        $file = $request->file('document');
        $mime = $file->getMimeType();
        abort_unless(isset(self::MIME_EXTENSIONS[$mime]), 422, __('Format de document non accepté.'));
        $filename = Str::uuid().'.'.self::MIME_EXTENSIONS[$mime];
        $disk = $this->privateDiskName();
        try {
            $path = $file->storeAs('equipment-documents/'.$vehicle->id, $filename, [
                'disk' => $disk, 'visibility' => 'private',
            ]);
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, __('Les justificatifs sont temporairement indisponibles.'));
        }
        abort_unless(is_string($path) && $path !== '', 503, __('Les justificatifs sont temporairement indisponibles.'));

        try {
            $document = DB::transaction(fn () => EquipmentDocument::create([
                'vehicle_id' => $vehicle->id,
                'document_type' => $data['document_type'],
                'original_name' => $this->safeFilename($file->getClientOriginalName()),
                'mime_type' => $mime,
                'size' => $file->getSize(),
                'disk' => $disk,
                'path' => $path,
                'expires_on' => $data['expires_on'] ?? null,
                // A file being present is not evidence of a passed inspection.
                'status' => 'provided',
                'uploaded_by' => $request->user()->id,
            ]));
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);
            throw $exception;
        }

        return response()->json(['data' => $this->present($document)], 201);
    }

    public function download(Request $request, Vehicle $vehicle, EquipmentDocument $document): StreamedResponse
    {
        $this->authorizeDocument($request, $vehicle, $document);
        $storage = $this->privateStorage($document);
        try {
            $exists = $storage->exists($document->path);
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, __('Les justificatifs sont temporairement indisponibles.'));
        }
        abort_unless($exists, 404);

        return $storage->download($document->path, $document->original_name, [
            'Content-Type' => $document->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, Vehicle $vehicle, EquipmentDocument $document): JsonResponse
    {
        $this->authorizeDocument($request, $vehicle, $document);
        // Keep the record available for retry if storage reports a deletion
        // failure. Missing files can still have their stale record removed.
        $storage = $this->privateStorage($document);
        try {
            $deleted = ! $storage->exists($document->path) || $storage->delete($document->path);
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, __('Les justificatifs sont temporairement indisponibles.'));
        }
        abort_unless($deleted, 503, __('Les justificatifs sont temporairement indisponibles.'));
        $document->delete();

        return response()->json(['message' => __('Document supprimé.')]);
    }

    private function authorizeVehicle(Request $request, Vehicle $vehicle): void
    {
        $user = $request->user();
        if ($user?->isPartner()) {
            PartnerOwnership::authorize($vehicle, $user);
        } else {
            abort_unless($user?->role?->canManageCatalog(), 403);
        }
        abort_unless($vehicle->asset_kind === 'equipment', 404);
    }

    private function authorizeDocument(Request $request, Vehicle $vehicle, EquipmentDocument $document): void
    {
        $this->authorizeVehicle($request, $vehicle);
        abort_unless((int) $document->vehicle_id === (int) $vehicle->id, 404);
        $path = (string) $document->path;
        // Never turn a corrupted database path into an arbitrary local-file
        // read or deletion, even for an otherwise authorized user.
        abort_unless(dirname($path) === 'equipment-documents/'.$vehicle->id
            && ! str_contains($path, '\\')
            && Str::isUuid(pathinfo($path, PATHINFO_FILENAME))
            && in_array(pathinfo($path, PATHINFO_EXTENSION), self::MIME_EXTENSIONS, true), 404);
    }

    private function safeFilename(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = mb_substr(trim($name), 0, 255);

        return $name !== '' && ! in_array($name, ['.', '..'], true) ? $name : 'document';
    }

    private function privateDiskName(?EquipmentDocument $document = null): string
    {
        $disk = $document?->disk ?? config('equipment.documents_disk');
        $settings = is_string($disk) ? config('filesystems.disks.'.$disk) : null;
        $valid = is_string($disk) && $disk !== 'public' && is_array($settings)
            && ($settings['visibility'] ?? 'private') === 'private';
        if (app()->isProduction()) {
            // The application container filesystem is temporary. Production
            // requires durable private object storage, including for downloads.
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

    private function privateStorage(EquipmentDocument $document): FilesystemAdapter
    {
        $disk = $this->privateDiskName($document);
        try {
            return Storage::disk($disk);
        } catch (\Throwable $exception) {
            report($exception);
            abort(503, __('Les justificatifs sont temporairement indisponibles.'));
        }
    }

    private function present(EquipmentDocument $document): array
    {
        return [
            'id' => $document->id,
            'document_type' => $document->document_type,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size' => $document->size,
            'expires_on' => $document->expires_on?->format('Y-m-d'),
            'status' => $document->status,
            'uploaded_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
