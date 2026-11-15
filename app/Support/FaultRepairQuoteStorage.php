<?php

namespace App\Support;

use App\Models\FaultRepairQuote;
use Illuminate\Support\Facades\Storage;

final class FaultRepairQuoteStorage
{
    public static function privateDisk(): string
    {
        $disk = (string) config('equipment.documents_disk', 'local');
        $settings = config('filesystems.disks.'.$disk);

        if (! is_array($settings) || ($settings['visibility'] ?? null) === 'public') {
            $disk = 'local';
        }

        return $disk;
    }

    public static function isPublicDisk(?string $disk): bool
    {
        if ($disk === null || $disk === '') {
            return false;
        }
        $settings = config('filesystems.disks.'.$disk);

        return is_array($settings) && ($settings['visibility'] ?? null) === 'public';
    }

    public static function reclaim(FaultRepairQuote $quote): void
    {
        $private = self::privateDisk();
        if ($quote->disk === $private || ! self::isPublicDisk($quote->disk)) {
            return;
        }

        $from = Storage::disk($quote->disk);
        $to = Storage::disk($private);
        if ($from->exists($quote->path)) {
            $to->put($quote->path, $from->get($quote->path), 'private');
            $from->delete($quote->path);
        } elseif (! $to->exists($quote->path)) {
            return;
        }

        $quote->forceFill(['disk' => $private])->save();
    }

    public static function reclaimPublishedCopies(): int
    {
        $moved = 0;
        FaultRepairQuote::query()->where('kind', FaultRepairQuote::KIND_PUBLISHED)->each(function (FaultRepairQuote $quote) use (&$moved) {
            $origin = $quote->disk;
            self::reclaim($quote);
            if ($quote->fresh()?->disk !== $origin) {
                $moved++;
            }
        });

        return $moved;
    }
}
