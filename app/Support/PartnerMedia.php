<?php

namespace App\Support;

use App\Models\Accessory;
use App\Models\Media;
use App\Models\Part;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/** A partner gallery cannot expose internal documents or private storage. */
class PartnerMedia
{
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public static function scope(Builder|Relation $query): Builder|Relation
    {
        return $query->where('collection', 'gallery')
            ->whereIn('mime_type', self::IMAGE_TYPES)
            ->whereIn('disk', self::publicDisks());
    }

    public static function allows(Media $media, Model $product): bool
    {
        $folder = match (true) {
            $product instanceof Vehicle => 'vehicles',
            $product instanceof Part => 'parts',
            $product instanceof Accessory => 'accessories',
            default => null,
        };

        if ($folder === null) {
            return false;
        }

        $prefix = $folder.'/'.$product->getKey().'/';
        $path = (string) $media->path;

        return $media->collection === 'gallery'
            && in_array($media->mime_type, self::IMAGE_TYPES, true)
            && in_array($media->disk, self::publicDisks(), true)
            && str_starts_with($path, $prefix)
            && basename($path) === substr($path, strlen($prefix))
            && ! in_array(basename($path), ['', '.', '..'], true)
            && ! str_contains($path, '\\')
            && ($media->preview_path === null || $media->preview_path === VignetteApercu::chemin($path));
    }

    public static function publicDisks(): array
    {
        return array_keys(array_filter(config('filesystems.disks', []), function ($disk, string $name): bool {
            if (! is_array($disk) || $name === 'local' || ($disk['visibility'] ?? null) === 'private') {
                return false;
            }

            return $name === 'public' || $name === 's3' || ($disk['visibility'] ?? null) === 'public';
        }, ARRAY_FILTER_USE_BOTH));
    }
}
