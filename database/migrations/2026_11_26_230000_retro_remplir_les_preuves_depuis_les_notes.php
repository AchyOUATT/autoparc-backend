<?php

use App\Support\FitmentEvidence;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PROPOSITION (à fusionner seulement sur décision du propriétaire).
 *
 * Rétro-remplit la preuve STRUCTURÉE de 15 compatibilités historiques, et de
 * celles-là seulement, dont l'URL citée dans les notes a été RELUE le
 * 26/11/2026 et nomme bien la pièce pour ce véhicule. Pas de règle par
 * domaine : une adresse deere.com ou cat.com ne prouve rien tant que la page
 * n'a pas été lue pour la ligne en question.
 *
 *  - 3 lignes « manufacturer_official » : pages du constructeur de l'engin
 *    (Caterpillar, John Deere) qui nomment le numéro pour le modèle ;
 *  - 12 lignes « aftermarket_crossref » : tableaux de CUSCO et HKS, marques
 *    de l'après-vente qui recopient le numéro Toyota. Niveau insuffisant pour
 *    confirmer : ces lignes restent « candidat » ; on enregistre seulement
 *    la nature réelle de leur source.
 *
 * Une ligne est visée par SKU + modèle (slug) + moteur + années ; elle n'est
 * touchée que si elle n'a encore aucune preuve et que ses notes citent bien
 * chaque URL. down() ne retire que ce que up() a posé, à l'identique.
 */
return new class extends Migration
{
    private const CUSCO = 'https://www.cusco.co.jp/products/pdf/oilfilter_toyota.pdf';

    private const HKS = 'https://www.hks-power.co.jp/en/product_search/product/935/media/print';

    /**
     * [sku, slug du modèle, moteur, année début, année fin, niveau, sources, ce que dit la source].
     *
     * @var list<array{0:string,1:string,2:?string,3:?int,4:?int,5:string,6:list<string>,7:string}>
     */
    public const LIGNES = [
        ['435-6493', 'cat-140-gc', null, null, null, FitmentEvidence::MANUFACTURER_OFFICIAL,
            ['https://parts.cat.com/en/catcorp/product/435-6493'], 'Cat Parts Store : 435-6493, applications « 140 GC » (Motor Grader) et C7.1'],
        ['RE504836', 'jd-5090m', null, null, null, FitmentEvidence::MANUFACTURER_OFFICIAL,
            ['https://www.deere.com/assets/pdfs/common/qrg/5m-series-5075m-5090m-5100m-5115m-tractors.pdf'], 'Guide John Deere 5M (5075M, 5090M, 5100M, 5115M) : « OIL FILTER - RE504836 »'],
        ['RE59754', 'jd-5410', null, null, null, FitmentEvidence::MANUFACTURER_OFFICIAL,
            ['https://www.deere.com/assets/pdfs/common/parts-and-service/quick-reference-guides/5010-series-5210-5310-5410-5510.pdf'], 'Guide John Deere série 5010 : « ENGINE RE59754 (5410, 5510) »'],
        ['90915-10001', 'corolla-e100', '4A-FE', 1991, 1995, FitmentEvidence::AFTERMARKET_CROSSREF, [self::CUSCO], 'CUSCO : AE101 4A-FE 91.06-95.05 → 90915-10001'],
        ['90915-10001', 'corolla-e110', '4A-FE', 1995, 2000, FitmentEvidence::AFTERMARKET_CROSSREF, [self::CUSCO], 'CUSCO : AE111 4A-FE 95.05-00.07 → 90915-10001'],
        ['90915-10001', 'camry-xv10', '5S-FE', 1992, 1996, FitmentEvidence::AFTERMARKET_CROSSREF, [self::CUSCO], 'CUSCO : SXV10/SXV15 5S-FE 92.11-96.09 → 90915-10001'],
        ['90915-10001', 'camry-xv20', '5S-FE', 1996, 2001, FitmentEvidence::AFTERMARKET_CROSSREF, [self::CUSCO], 'CUSCO : SXV20/SXV20W 5S-FE 96.12-01.08 → 90915-10001'],
        ['90915-10001', 'rav4-xa10', '3S-FE', 1994, 2000, FitmentEvidence::AFTERMARKET_CROSSREF, [self::CUSCO], 'CUSCO : SXA10G/SXA11G 3S-FE 94.04-00.05 → 90915-10001'],
        ['90915-10003', 'corolla-e120', '1ZZ-FE', 2000, 2006, FitmentEvidence::AFTERMARKET_CROSSREF, [self::CUSCO, self::HKS], 'CUSCO et HKS : ZZE122/ZZE124 1ZZ-FE 00.08-06.10 → 90915-10003'],
        ['90915-10003', 'corolla-e120', '1NZ-FE', 2000, 2006, FitmentEvidence::AFTERMARKET_CROSSREF, [self::CUSCO, self::HKS], 'CUSCO et HKS : NZE121/NZE124 1NZ-FE 00.08-06.10 → 90915-10003'],
        ['90915-10003', 'corolla-e120', '2NZ-FE', 2000, 2006, FitmentEvidence::AFTERMARKET_CROSSREF, [self::CUSCO, self::HKS], 'CUSCO et HKS : NZE120 2NZ-FE 00.08-06.10 → 90915-10003'],
        ['90915-10003', 'corolla-e140', '1NZ-FE', 2006, 2012, FitmentEvidence::AFTERMARKET_CROSSREF, [self::CUSCO, self::HKS], 'CUSCO et HKS : NZE141/NZE144 1NZ-FE 06.10- → 90915-10003'],
        ['90915-10003', 'yaris-xp90', '1NZ-FE', 2005, 2010, FitmentEvidence::AFTERMARKET_CROSSREF, [self::CUSCO, self::HKS], 'CUSCO et HKS : NCP91 1NZ-FE 05.02- → 90915-10003'],
        ['90915-10003', 'yaris-xp90', '2NZ-FE', 2005, 2010, FitmentEvidence::AFTERMARKET_CROSSREF, [self::CUSCO, self::HKS], 'CUSCO et HKS : NCP95 2NZ-FE 05.02- → 90915-10003'],
        ['90915-10003', 'yaris-xp90', '2SZ-FE', 2005, 2010, FitmentEvidence::AFTERMARKET_CROSSREF, [self::CUSCO, self::HKS], 'CUSCO et HKS : SCP90 2SZ-FE 05.02- → 90915-10003'],
    ];

    public function up(): void
    {
        foreach ($this->lignesVisees(enAttente: true) as [$id, $niveau, $sources]) {
            DB::table('part_fitments')->where('id', $id)
                ->whereNull('evidence_level')->whereNull('evidence_sources')
                ->update(['evidence_level' => $niveau, 'evidence_sources' => json_encode($sources)]);
        }
    }

    public function down(): void
    {
        foreach ($this->lignesVisees(enAttente: false) as [$id, $niveau, $sources]) {
            $ligne = DB::table('part_fitments')->where('id', $id)->first(['evidence_level', 'evidence_sources']);
            if ($ligne && $ligne->evidence_level === $niveau && json_decode((string) $ligne->evidence_sources, true) === $sources) {
                DB::table('part_fitments')->where('id', $id)->update(['evidence_level' => null, 'evidence_sources' => null]);
            }
        }
    }

    /** @return list<array{0:int,1:string,2:list<string>}> */
    private function lignesVisees(bool $enAttente): array
    {
        if (! Schema::hasColumn('part_fitments', 'evidence_level') || ! Schema::hasColumn('part_fitments', 'evidence_sources')) {
            return [];
        }
        $visees = [];
        foreach (self::LIGNES as [$sku, $slug, $moteur, $debut, $fin, $niveau, $sources]) {
            $requete = DB::table('part_fitments as f')
                ->join('parts as p', 'p.id', '=', 'f.part_id')
                ->join('vehicle_models as m', 'm.id', '=', 'f.vehicle_model_id')
                ->where('p.sku', $sku)->where('m.slug', $slug)
                ->select('f.id', 'f.notes', 'f.evidence_level', 'f.evidence_sources');
            foreach (['f.engine_code' => $moteur, 'f.year_from' => $debut, 'f.year_to' => $fin] as $colonne => $valeur) {
                $valeur === null ? $requete->whereNull($colonne) : $requete->where($colonne, $valeur);
            }
            foreach ($requete->get() as $ligne) {
                $citees = FitmentEvidence::urlsInText($ligne->notes);
                if (array_diff($sources, $citees) !== []) {
                    continue; // la note ne cite pas cette source : on ne pose rien
                }
                if ($enAttente && ($ligne->evidence_level !== null || $ligne->evidence_sources !== null)) {
                    continue;
                }
                $visees[] = [(int) $ligne->id, $niveau, $sources];
            }
        }

        return $visees;
    }
};
