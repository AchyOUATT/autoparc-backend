<?php

use App\Support\FitmentEvidence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PROPOSITION (à fusionner seulement sur décision du propriétaire).
 *
 * Rétro-remplit la preuve STRUCTURÉE des compatibilités historiques à partir
 * des URL déjà écrites dans leurs notes, sans rien inventer :
 *  - seules les lignes sans aucune preuve (evidence_level ET
 *    evidence_sources vides) sont touchées ;
 *  - evidence_sources = les URL lues dans `notes`, telles quelles ;
 *  - evidence_level n'est posé que si TOUS les domaines cités figurent dans
 *    la liste ci-dessous ; le plus fort l'emporte (un document constructeur
 *    cité suffit) ; un domaine inconnu laisse la ligne intacte.
 *
 * Production au 26/11/2026 : 15 lignes, 3 constructeur (Caterpillar, John
 * Deere), 12 aftermarket (CUSCO, HKS) ; les 128 autres n'ont pas d'URL.
 * Idempotente : une ligne déjà qualifiée n'est jamais réécrite.
 */
return new class extends Migration
{
    /** Domaine (ou suffixe) → niveau de preuve, par ordre de force. */
    public const DOMAINES = [
        'parts.cat.com' => FitmentEvidence::MANUFACTURER_OFFICIAL,
        'deere.com' => FitmentEvidence::MANUFACTURER_OFFICIAL,
        'cusco.co.jp' => FitmentEvidence::AFTERMARKET_CROSSREF,
        'hks-power.co.jp' => FitmentEvidence::AFTERMARKET_CROSSREF,
    ];

    public function up(): void
    {
        foreach (['part_fitments', 'oem_number_fitments'] as $table) {
            if (! Schema::hasColumn($table, 'evidence_level') || ! Schema::hasColumn($table, 'evidence_sources')) {
                continue;
            }
            DB::table($table)
                ->whereNull('evidence_level')
                ->whereNull('evidence_sources')
                ->where('notes', 'like', '%http%')
                ->orderBy('id')
                ->get(['id', 'notes'])
                ->each(function (object $ligne) use ($table) {
                    $urls = FitmentEvidence::urlsInText($ligne->notes);
                    $niveau = self::niveau($urls);
                    if ($niveau === null) {
                        return;
                    }
                    DB::table($table)->where('id', $ligne->id)
                        ->whereNull('evidence_level')->whereNull('evidence_sources')
                        ->update(['evidence_level' => $niveau, 'evidence_sources' => json_encode($urls)]);
                });
        }
    }

    /** @param list<string> $urls */
    public static function niveau(array $urls): ?string
    {
        if ($urls === []) {
            return null;
        }
        $niveaux = [];
        foreach ($urls as $url) {
            $hote = strtolower((string) parse_url($url, PHP_URL_HOST));
            $trouve = null;
            foreach (self::DOMAINES as $domaine => $niveau) {
                if ($hote === $domaine || str_ends_with($hote, '.'.$domaine)) {
                    $trouve = $niveau;
                    break;
                }
            }
            if ($trouve === null) {
                return null; // domaine non classé : on ne devine pas
            }
            $niveaux[] = $trouve;
        }

        return in_array(FitmentEvidence::MANUFACTURER_OFFICIAL, $niveaux, true)
            ? FitmentEvidence::MANUFACTURER_OFFICIAL
            : FitmentEvidence::AFTERMARKET_CROSSREF;
    }

    public function down(): void
    {
        // Données : aucune suppression automatique (une preuve posée ensuite
        // à la main serait indiscernable). Voir la procédure de retour arrière.
    }
};
