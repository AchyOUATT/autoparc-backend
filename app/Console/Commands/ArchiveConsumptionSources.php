<?php

namespace App\Console\Commands;

use App\Models\Motorisation;
use App\Services\ConsumptionSourceArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/** One-time recovery of retained local evidence; never download a substitute source. */
class ArchiveConsumptionSources extends Command
{
    protected $signature = 'catalog:archive-consommations';

    protected $description = 'Verifie les archives privees et recupere les anciennes sources locales dont le SHA256 concorde';

    public function handle(ConsumptionSourceArchive $archives): int
    {
        $verified = 0;
        $unknown = 0;
        $failed = 0;
        Motorisation::query()->orderBy('id')->chunkById(500, function ($references) use ($archives, &$verified, &$unknown, &$failed) {
            $descriptors = [];
            foreach ($references as $reference) {
                $provenance = $reference->provenance ?? [];
                $hash = $provenance['file_sha256'] ?? null;
                if (! is_string($hash) || ! preg_match('/\A[a-f0-9]{64}\z/', $hash)) {
                    $unknown++;

                    continue;
                }
                try {
                    if (! array_key_exists($hash, $descriptors)) {
                        $descriptors[$hash] = $archives->exists($hash)
                            ? $archives->describe($hash)
                            : $archives->recoverLegacyLocal($hash);
                    }
                    $descriptor = $descriptors[$hash];
                    $provenance['archive'] = $descriptor ?? $archives->unknown($hash);
                    DB::table('motorisations')->where('id', $reference->id)->update([
                        'provenance' => json_encode($provenance, JSON_THROW_ON_ERROR),
                    ]);
                    $descriptor === null ? $unknown++ : $verified++;
                } catch (Throwable $exception) {
                    $failed++;
                    $provenance['archive'] = [
                        'status' => 'failed', 'store' => 'database', 'key' => $hash,
                        'sha256' => $hash, 'checked_at' => now()->toIso8601String(),
                    ];
                    DB::table('motorisations')->where('id', $reference->id)->update([
                        'provenance' => json_encode($provenance, JSON_THROW_ON_ERROR),
                    ]);
                    // Do not expose source content or turn corruption into a success.
                    $this->error('Reference '.$reference->id.' : '.$exception->getMessage());
                }
            }
        });
        $this->info(sprintf('Archives verifiees : %d ; sources absentes/inconnues : %d ; erreurs : %d.', $verified, $unknown, $failed));

        return $failed > 0 || $unknown > 0 ? self::FAILURE : self::SUCCESS;
    }
}
