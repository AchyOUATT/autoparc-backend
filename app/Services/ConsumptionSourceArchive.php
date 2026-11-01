<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Private content-addressed evidence; no HTTP route exposes these bodies. */
class ConsumptionSourceArchive
{
    public function store(string $content): array
    {
        $this->requireDatabaseStore();
        $sha256 = hash('sha256', $content);
        $size = strlen($content);
        if ($size > $this->maximumSize()) {
            throw new RuntimeException('Source de consommation trop volumineuse pour son archivage.');
        }

        if (! DB::table('consumption_source_archives')->where('sha256', $sha256)->exists()) {
            $compressed = gzencode($content, 9);
            if ($compressed === false) {
                throw new RuntimeException('Compression de la source de consommation impossible.');
            }
            try {
                $this->insertArchive([
                    'sha256' => $sha256,
                    'encoding' => 'gzip+base64',
                    'body' => base64_encode($compressed),
                    'original_size' => $size,
                    'created_at' => now(),
                ]);
            } catch (Throwable) {
                // A database exception can embed the INSERT bindings, including
                // the archived body. Report failure without exposing those bytes.
                throw new RuntimeException('Ecriture de l\'archive de consommation impossible.');
            }
        }

        // Check the bytes read back from durable storage, including an existing
        // row won by a concurrent import. A successful write alone proves nothing.
        return $this->describe($sha256);
    }

    protected function insertArchive(array $row): void
    {
        // A competing insertion of the same digest is idempotent. Existing
        // evidence is never replaced, even when it has become corrupt.
        DB::table('consumption_source_archives')->insertOrIgnore($row);
    }

    public function read(string $sha256): string
    {
        $this->requireDatabaseStore();
        $this->validateDigest($sha256);
        $row = DB::table('consumption_source_archives')->where('sha256', $sha256)->first();
        if ($row === null) {
            throw new RuntimeException('Archive de consommation absente.');
        }
        if ($row->encoding !== 'gzip+base64'
            || ! is_numeric($row->original_size)
            || (int) $row->original_size < 0
            || (int) $row->original_size > $this->maximumSize()) {
            throw new RuntimeException('Metadonnees de l\'archive de consommation invalides.');
        }
        $compressed = base64_decode($row->body, true);
        $content = $compressed === false ? false : @gzdecode($compressed, (int) $row->original_size + 1);
        if ($content === false
            || strlen($content) !== (int) $row->original_size
            || ! hash_equals($sha256, hash('sha256', $content))) {
            throw new RuntimeException('Integrite de l\'archive de consommation invalide.');
        }

        return $content;
    }

    public function describe(string $sha256): array
    {
        $content = $this->read($sha256);

        return [
            'status' => 'verified',
            'store' => 'database',
            'key' => $sha256,
            'sha256' => $sha256,
            'encoding' => 'gzip+base64',
            'original_size' => strlen($content),
            // Byte integrity, not documentary verification of a vehicle version.
            'verified_at' => now()->toIso8601String(),
        ];
    }

    /** Recover only bytes whose digest agrees with the previously recorded source. */
    public function recoverLegacyLocal(string $sha256): ?array
    {
        $this->validateDigest($sha256);
        $path = 'consumption-sources/'.$sha256;
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }
        $content = Storage::disk('local')->get($path);
        if (! hash_equals($sha256, hash('sha256', $content))) {
            throw new RuntimeException('Le fichier local ne correspond pas a l\'empreinte de la source.');
        }

        return $this->store($content);
    }

    public function exists(string $sha256): bool
    {
        $this->requireDatabaseStore();
        $this->validateDigest($sha256);

        return DB::table('consumption_source_archives')->where('sha256', $sha256)->exists();
    }

    public function unknown(string $sha256): array
    {
        $this->validateDigest($sha256);

        return ['status' => 'unknown', 'store' => null, 'key' => $sha256, 'sha256' => $sha256];
    }

    private function requireDatabaseStore(): void
    {
        if (config('consumption.archive_store') !== 'database') {
            throw new RuntimeException('Stockage d\'archive de consommation non pris en charge : utiliser database.');
        }
    }

    private function maximumSize(): int
    {
        return (int) config('consumption.archive_max_bytes', 32 * 1024 * 1024);
    }

    private function validateDigest(string $sha256): void
    {
        if (! preg_match('/\\A[a-f0-9]{64}\\z/', $sha256)) {
            throw new InvalidArgumentException('Empreinte de source de consommation invalide.');
        }
    }
}
