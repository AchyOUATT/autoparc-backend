<?php

/**
 * Compare deux instantanés (instantane-verdicts.php) et classe chaque
 * changement : « attendu » (règle nommée) ou « NON ATTENDU ».
 *
 *     php compare-verdicts.php avant.json apres.json [rapport.csv]
 *
 * Code de sortie 1 s'il existe au moins un changement non attendu.
 */
[$_, $fa, $fb] = $argv + [null, null, null];
if (! $fa || ! $fb) {
    fwrite(STDERR, "usage : php compare-verdicts.php avant.json apres.json [rapport.csv]\n");
    exit(2);
}
$a = json_decode(file_get_contents($fa), true);
$b = json_decode(file_get_contents($fb), true);
$va = $a['verdicts'];
$vb = $b['verdicts'];

// Règles des changements attendus (PR #42 à #44, validées le 26/11/2026),
// dans l'ordre où elles sont essayées.
$regles = [
    // Règle de preuve stricte (#42) : confirmé sans preuve documentée → non déterminé.
    'preuve_insuffisante' => fn ($x, $y) => $x['status'] === 'confirmed' && $y['status'] === 'candidate'
        && $y['reason'] === 'insufficient_evidence',
    // #42 : un critère connu contredit donne « excluded » au lieu de « unknown ».
    'critere_contredit_exclu' => fn ($x, $y) => $x['status'] === 'unknown' && $y['status'] === 'excluded'
        && $y['excluded_criteria'] !== [],
    // #42/#43 : modèle désactivé (ligne nue `sienna`) hors des recherches courantes.
    'modele_inactif' => fn ($x, $y) => $y['status'] === 'unknown' && $y['reason'] === 'inactive_vehicle_model',
    // #40/#41 : nouvelles compatibilités sourcées du filtre 90915-YZZD1.
    'nouvelles_lignes_yzzd1' => fn ($x, $y) => ($y['sku'] ?? $x['sku']) === '90915-YZZD1' && $x['status'] === 'unknown',
    // #43/#44 : lignes Sienna redistribuées par génération (XL20/XL30/XL40).
    'referentiel_sienna' => fn ($x, $y) => str_starts_with((string) ($y['model'] ?? $x['model'] ?? ''), 'sienna-')
        && in_array($y['sku'] ?? $x['sku'], ['90915-YZZD1', '04152-YZZA1', '90915-YZZN1'], true),
];

// Une clé catalogue n'est comparable que si sa recherche figure dans les
// DEUX instantanés (sinon « unknown » d'un côté veut dire « pas évaluée ») :
// c'est le cas d'un modèle créé entre les deux.
$echA = $a['echantillons'] ?? null;
$echB = $b['echantillons'] ?? null;
$comparable = function (string $cle) use ($echA, $echB): bool {
    if (! str_starts_with($cle, 'catalogue:') || $echA === null || $echB === null) {
        return true;
    }
    $e = substr($cle, strlen('catalogue:'), strrpos($cle, '|') - strlen('catalogue:'));

    return isset($echA[$e], $echB[$e]);
};

$changements = [];
foreach (array_unique(array_merge(array_keys($va), array_keys($vb))) as $cle) {
    $x = $va[$cle] ?? null;
    $y = $vb[$cle] ?? null;
    $sx = $x['status'] ?? 'unknown';
    $sy = $y['status'] ?? 'unknown';
    $mx = $x['missing_criteria'] ?? [];
    $my = $y['missing_criteria'] ?? [];
    if ($sx === $sy && ($sx === 'unknown' || ($mx == $my && ($x['reason'] ?? null) === ($y['reason'] ?? null)))) {
        continue;
    }
    $x = ($x ?? ['status' => 'unknown', 'reason' => null, 'missing_criteria' => [], 'excluded_criteria' => []]) + ($y ?? []);
    $y = ($y ?? ['status' => 'unknown', 'reason' => null, 'missing_criteria' => [], 'excluded_criteria' => []]) + $x;
    $x['status'] = $sx;
    $y['status'] = $sy;
    $regle = 'NON_ATTENDU';
    if (! $comparable($cle)) {
        // Recherche sur un modèle qui n'existait pas avant (générations
        // créées par #41/#43) : rien à comparer, listée à part.
        $regle = 'modele_nouveau';
    } else {
        foreach ($regles as $nom => $f) {
            if ($f($x, $y)) {
                $regle = $nom;
                break;
            }
        }
        if ($regle === 'NON_ATTENDU' && $sx === $sy) {
            $regle = 'detail_seul';
        }
    }
    $changements[] = [
        'cle' => $cle, 'type' => $y['type'] ?? $x['type'], 'vehicule' => $y['vehicle_id'] ?? '', 'modele' => $y['model'] ?? '',
        'annee' => $y['year'] ?? '', 'moteur' => $y['engine_code'] ?? '', 'sku' => $y['sku'] ?? $x['sku'],
        'avant' => $sx, 'raison_avant' => $x['reason'] ?? '', 'apres' => $sy, 'raison_apres' => $y['reason'] ?? '',
        'manquants_apres' => implode('+', $my), 'exclus_apres' => implode('+', $y['excluded_criteria'] ?? []),
        'classement' => $regle,
    ];
}

$compte = [];
foreach ($changements as $c) {
    $k = "{$c['type']}\t{$c['avant']} → {$c['apres']}\t{$c['classement']}";
    $compte[$k] = ($compte[$k] ?? 0) + 1;
}
ksort($compte);
echo "avant : {$a['meta']['commit']} ({$a['meta']['migrations']} migrations), après : {$b['meta']['commit']} ({$b['meta']['migrations']} migrations)\n";
echo count($changements)." changements\n";
foreach ($compte as $k => $n) {
    echo "$n\t$k\n";
}
$nonAttendus = array_filter($changements, fn ($c) => $c['classement'] === 'NON_ATTENDU');
foreach ($nonAttendus as $c) {
    echo "NON ATTENDU\t{$c['cle']}\t{$c['avant']} → {$c['apres']}\t{$c['raison_apres']}\n";
}
if (isset($argv[3])) {
    $f = fopen($argv[3], 'w');
    fputcsv($f, array_keys($changements[0] ?? ['cle' => '']));
    foreach ($changements as $c) {
        fputcsv($f, $c);
    }
    fclose($f);
}
exit($nonAttendus === [] ? 0 : 1);
