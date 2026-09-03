<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\Media;
use App\Models\Part;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Gestion des médias polymorphes (photos véhicules, pièces, accessoires).
 *
 * Routes (dans le groupe auth:sanctum + staff) :
 *   POST   /vehicles/{vehicle}/media
 *   POST   /parts/{part}/media
 *   POST   /accessories/{accessory}/media
 *   DELETE /media/{media}
 *   PATCH  /media/{media}/cover
 *   PATCH  /vehicles/{vehicle}/media/reorder
 *   PATCH  /parts/{part}/media/reorder
 *   PATCH  /accessories/{accessory}/media/reorder
 */
class MediaController extends Controller
{
    private const DISK       = 'public';
    private const MAX_SIZE   = 8192;   // ko — 8 Mo par fichier
    private const ALLOWED    = ['image/jpeg', 'image/png', 'image/webp'];

    // ── Upload ───────────────────────────────────────────────────────

    /** POST /vehicles/{vehicle}/media */
    public function storeForVehicle(Request $request, Vehicle $vehicle): JsonResponse
    {
        return $this->storeMedia($request, $vehicle, 'vehicles');
    }

    /** POST /parts/{part}/media */
    public function storeForPart(Request $request, Part $part): JsonResponse
    {
        return $this->storeMedia($request, $part, 'parts');
    }

    /** POST /accessories/{accessory}/media */
    public function storeForAccessory(Request $request, Accessory $accessory): JsonResponse
    {
        return $this->storeMedia($request, $accessory, 'accessories');
    }

    private function storeMedia(Request $request, $model, string $folder): JsonResponse
    {
        $request->validate([
            'photos'     => ['required', 'array', 'min:1'],
            'photos.*'   => ['file', 'mimes:jpg,jpeg,png,webp', 'max:' . self::MAX_SIZE],
            'collection' => ['nullable', 'string', 'in:gallery,documents,damages'],
        ]);

        $collection = $request->input('collection', 'gallery');
        $created    = [];

        foreach ($request->file('photos', []) as $file) {
            $ext  = $file->getClientOriginalExtension();
            $name = Str::uuid() . '.' . $ext;
            $path = $file->storeAs("{$folder}/{$model->id}", $name, self::DISK);

            // Position = dernier existant + 1
            $position = $model->media()
                ->where('collection', $collection)
                ->max('position') + 1;

            // Premier upload dans la galerie → couverture automatique
            $isCover = $collection === 'gallery'
                && ! $model->media()->where('collection', 'gallery')->exists();

            $media = $model->media()->create([
                'collection' => $collection,
                'path'       => $path,
                'disk'       => self::DISK,
                'mime_type'  => $file->getMimeType(),
                'size'       => $file->getSize(),
                'position'   => $position,
                'is_cover'   => $isCover,
            ]);

            $created[] = $this->formatMedia($media);
        }

        return response()->json(['data' => $created], 201);
    }

    // ── Suppression ──────────────────────────────────────────────────

    /** DELETE /media/{media} */
    public function destroy(Media $media): JsonResponse
    {
        Storage::disk($media->disk)->delete($media->path);

        $wasCover   = $media->is_cover;
        $collection = $media->collection;
        $model      = $media->mediable;

        $media->delete();

        // Si c'était la couverture → promouvoir la suivante
        if ($wasCover && $model) {
            $next = $model->media()
                ->where('collection', $collection)
                ->orderBy('position')
                ->first();
            $next?->update(['is_cover' => true]);
        }

        return response()->json(['message' => 'Média supprimé.']);
    }

    // ── Couverture ───────────────────────────────────────────────────

    /** PATCH /media/{media}/cover */
    public function setCover(Media $media): JsonResponse
    {
        $model = $media->mediable;

        if ($model) {
            // Retirer l'ancien cover
            $model->media()
                ->where('collection', $media->collection)
                ->where('is_cover', true)
                ->update(['is_cover' => false]);
        }

        $media->update(['is_cover' => true]);

        return response()->json(['data' => $this->formatMedia($media)]);
    }

    // ── Réordonnancement ─────────────────────────────────────────────

    /** PATCH /vehicles/{vehicle}/media/reorder */
    public function reorderForVehicle(Request $request, Vehicle $vehicle): JsonResponse
    {
        return $this->reorderMedia($request, $vehicle);
    }

    /** PATCH /parts/{part}/media/reorder */
    public function reorderForPart(Request $request, Part $part): JsonResponse
    {
        return $this->reorderMedia($request, $part);
    }

    /** PATCH /accessories/{accessory}/media/reorder */
    public function reorderForAccessory(Request $request, Accessory $accessory): JsonResponse
    {
        return $this->reorderMedia($request, $accessory);
    }

    private function reorderMedia(Request $request, $model): JsonResponse
    {
        $request->validate([
            'ids'   => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        foreach ($request->ids as $position => $id) {
            $model->media()->where('id', $id)->update(['position' => $position]);
        }

        return response()->json(['message' => 'Ordre mis à jour.']);
    }

    // ── Listing ──────────────────────────────────────────────────────

    /** GET /vehicles/{vehicle}/media */
    public function indexForVehicle(Vehicle $vehicle): JsonResponse
    {
        return $this->indexMedia($vehicle);
    }

    /** GET /parts/{part}/media */
    public function indexForPart(Part $part): JsonResponse
    {
        return $this->indexMedia($part);
    }

    /** GET /accessories/{accessory}/media */
    public function indexForAccessory(Accessory $accessory): JsonResponse
    {
        return $this->indexMedia($accessory);
    }

    private function indexMedia($model): JsonResponse
    {
        $media = $model->media()->orderBy('position')->get();
        return response()->json(['data' => $media->map(fn ($m) => $this->formatMedia($m))]);
    }

    // ── Helper ───────────────────────────────────────────────────────

    private function formatMedia(Media $media): array
    {
        return [
            'id'         => $media->id,
            'url'        => $media->url,
            'collection' => $media->collection,
            'mime_type'  => $media->mime_type,
            'size'       => $media->size,
            'caption'    => $media->caption,
            'position'   => $media->position,
            'is_cover'   => $media->is_cover,
        ];
    }
}
