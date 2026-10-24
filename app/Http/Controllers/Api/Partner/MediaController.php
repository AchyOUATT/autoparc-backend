<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Api\MediaController as CatalogMediaController;
use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\Media;
use App\Models\Part;
use App\Models\Vehicle;
use App\Support\PartnerOwnership;
use App\Support\PartnerMedia;
use App\Support\VignetteApercu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Partner access is limited to public product-gallery images. */
class MediaController extends Controller
{
    public function __construct(private CatalogMediaController $media) {}

    public function indexForVehicle(Request $request, Vehicle $vehicle): JsonResponse
    {
        return $this->index($request, $vehicle);
    }

    public function indexForPart(Request $request, Part $part): JsonResponse
    {
        return $this->index($request, $part);
    }

    public function indexForAccessory(Request $request, Accessory $accessory): JsonResponse
    {
        return $this->index($request, $accessory);
    }

    public function storeForVehicle(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->validateUpload($request, $vehicle);

        return $this->media->storeForVehicle($request, $vehicle);
    }

    public function storeForPart(Request $request, Part $part): JsonResponse
    {
        $this->validateUpload($request, $part);

        return $this->media->storeForPart($request, $part);
    }

    public function storeForAccessory(Request $request, Accessory $accessory): JsonResponse
    {
        $this->validateUpload($request, $accessory);

        return $this->media->storeForAccessory($request, $accessory);
    }

    public function reorderForVehicle(Request $request, Vehicle $vehicle): JsonResponse
    {
        return $this->reorder($request, $vehicle);
    }

    public function reorderForPart(Request $request, Part $part): JsonResponse
    {
        return $this->reorder($request, $part);
    }

    public function reorderForAccessory(Request $request, Accessory $accessory): JsonResponse
    {
        return $this->reorder($request, $accessory);
    }

    public function setCover(Request $request, Media $media): JsonResponse
    {
        $product = $this->authorizeMedia($request, $media);

        DB::transaction(function () use ($product, $media) {
            $ids = $this->gallery($product)->modelKeys();
            $product->media()->whereIn('id', $ids)->update(['is_cover' => false]);
            $media->update(['is_cover' => true]);
        });

        return response()->json(['data' => $this->format($media)]);
    }

    public function destroy(Request $request, Media $media): JsonResponse
    {
        $product = $this->authorizeMedia($request, $media);

        // Only the generated preview associated with this original may be
        // removed. An unrelated stored path must never become a delete target.
        abort_if($media->preview_path !== null && $media->preview_path !== VignetteApercu::chemin($media->path), 404);

        Storage::disk($media->disk)->delete($media->path);

        if ($media->preview_path !== null) {
            Storage::disk($media->disk)->delete($media->preview_path);
        }

        DB::transaction(function () use ($product, $media) {
            $wasCover = $media->is_cover;
            $media->delete();

            if ($wasCover) {
                $this->gallery($product)->first()?->update(['is_cover' => true]);
            }
        });

        return response()->json(['message' => 'Photo supprimée.']);
    }

    private function index(Request $request, Model $product): JsonResponse
    {
        PartnerOwnership::authorize($product, $request->user());

        return response()->json(['data' => $this->gallery($product)->map(fn (Media $media) => $this->format($media))->values()]);
    }

    private function validateUpload(Request $request, Model $product): void
    {
        PartnerOwnership::authorize($product, $request->user());
        abort_unless(in_array(config('media.disk'), PartnerMedia::publicDisks(), true), 503, 'Le stockage public des photos est indisponible.');

        $request->validate([
            'collection' => ['sometimes', 'required', 'in:gallery'],
            'photos' => ['required', 'array', 'min:1', 'max:10'],
            'photos.*' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'dimensions:max_width=12000,max_height=12000'],
        ]);

        // The shared uploader retains its upload-size guards, storage error
        // handling and preview generation. Partners cannot select a collection.
        $request->merge(['collection' => 'gallery']);
    }

    private function reorder(Request $request, Model $product): JsonResponse
    {
        PartnerOwnership::authorize($product, $request->user());
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:1000'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $allowed = $this->gallery($product)->keyBy('id');

        // Resolve the complete request before changing a position. A mixed list
        // containing another product's media must leave every image untouched.
        foreach ($data['ids'] as $id) {
            abort_unless($allowed->has($id), 404);
        }

        DB::transaction(function () use ($product, $data) {
            foreach ($data['ids'] as $position => $id) {
                $product->media()->whereKey($id)->update(['position' => $position]);
            }
        });

        return response()->json(['message' => 'Ordre des photos mis à jour.']);
    }

    private function authorizeMedia(Request $request, Media $media): Model
    {
        $product = $media->mediable;
        abort_unless($product instanceof Vehicle || $product instanceof Part || $product instanceof Accessory, 404);
        PartnerOwnership::authorize($product, $request->user());
        abort_unless(PartnerMedia::allows($media, $product), 404);

        return $product;
    }

    private function gallery(Model $product)
    {
        return PartnerMedia::scope($product->media())
            ->orderBy('id')->get()
            ->filter(fn (Media $media) => PartnerMedia::allows($media, $product))->values();
    }

    private function format(Media $media): array
    {
        return [
            'id' => $media->id,
            'url' => $media->url,
            'collection' => 'gallery',
            'mime_type' => $media->mime_type,
            'size' => $media->size,
            'caption' => $media->caption,
            'position' => $media->position,
            'is_cover' => $media->is_cover,
        ];
    }
}
