<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Support\PartnerMedia;
use App\Support\PartnerOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class CatalogController extends Controller
{
    abstract protected function productClass(): string;

    abstract protected function resourceClass(): string;

    abstract protected function relations(): array;

    private function loadedRelations(): array
    {
        return array_merge($this->relations(), [
            'sellerAccount.user',
            'media' => fn ($media) => PartnerMedia::scope($media),
        ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $modelClass = $this->productClass();
        $resourceClass = $this->resourceClass();
        $products = $modelClass::query()
            ->ownedByPartnerAccount(PartnerOwnership::accountId($request->user()))
            ->with($this->loadedRelations())
            ->search($data['q'] ?? null)
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20)
            ->withQueryString();

        foreach ($products->getCollection() as $product) {
            $this->restrictMedia($product);
        }

        return $resourceClass::collection($products);
    }

    protected function showProduct(Request $request, Model $product)
    {
        PartnerOwnership::authorize($product, $request->user());
        $resourceClass = $this->resourceClass();
        $product->load($this->loadedRelations());
        $this->restrictMedia($product);

        return new $resourceClass($product);
    }

    private function restrictMedia(Model $product): void
    {
        $product->setRelation('media', $product->media
            ->filter(fn ($media) => PartnerMedia::allows($media, $product))->values());
    }

    protected function deleteProduct(Request $request, Model $product): JsonResponse
    {
        PartnerOwnership::authorize($product, $request->user());
        $product->delete();

        return response()->json(['message' => 'Annonce archivée.']);
    }

    protected function publishProduct(Request $request, Model $product, bool $publish)
    {
        PartnerOwnership::authorize($product, $request->user());
        $product->update(['partner_published_at' => $publish ? ($product->partner_published_at ?? now()) : null]);

        return $this->showProduct($request, $product->refresh());
    }
}
