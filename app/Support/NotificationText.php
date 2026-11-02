<?php

namespace App\Support;

/** Translates system templates at delivery time, retaining names and user text. */
final class NotificationText
{
    /** @return array{title: string, body: string} */
    public static function forLocale(string $title, string $body, array $data, string $locale): array
    {
        if ($locale !== 'en') {
            return compact('title', 'body');
        }

        $type = $data['type'] ?? '';
        $titles = [
            'new_need' => ['Nouveau besoin client', 'New customer request'],
            'need_status_update' => ['Votre besoin a été mis à jour', 'Your request has been updated'],
            'need_broadcast' => ['Demande client à pourvoir', 'Customer request to fulfil'],
            'vehicle_match' => ['Un véhicule correspond à votre recherche', 'A vehicle matches your search'],
            'part_match' => ['Pièce disponible !', 'Part available!'],
            'accessory_match' => ['Accessoire disponible !', 'Accessory available!'],
            'fuel_anomaly' => ['Consommation en hausse', 'Fuel consumption has increased'],
        ];
        if (isset($titles[$type]) && $title === $titles[$type][0]) {
            $title = $titles[$type][1];
        }

        $translateReference = fn (string $text): string => (string) __($text, [], 'en');
        if ($type === 'new_need' && preg_match('/^Type : (.*?) — (.*)$/su', $body, $m)) {
            $body = 'Type: '.$translateReference($m[1]).' — '.$m[2];
        } elseif ($type === 'need_status_update' && preg_match('/^Statut : (.*?) — (.*)$/su', $body, $m)) {
            $body = 'Status: '.$translateReference($m[1]).' — '.$m[2];
        } elseif ($type === 'need_broadcast' && preg_match('/^(Véhicule|Pièce détachée|Accessoire)(.*?) — (.*)$/su', $body, $m)) {
            $body = $translateReference($m[1]).$m[2].' — '.$m[3];
        } elseif ($type === 'vehicle_match' && preg_match("/^(.*) vient d'être ajouté au catalogue\.$/su", $body, $m)) {
            $body = $m[1].' has been added to the catalogue.';
        } elseif ($type === 'part_match' && preg_match("/^Une (.*?) vient d['’]être ajoutée : (.*)$/su", $body, $m)) {
            $body = $translateReference($m[1]).' added: '.$m[2];
        } elseif ($type === 'accessory_match' && preg_match("/^Un accessoire (.*?) vient d'être ajouté : (.*)$/su", $body, $m)) {
            $body = 'New '.$translateReference($m[1]).' accessory: '.$m[2];
        } elseif ($type === 'new_order') {
            $title = match ($title) {
                'Nouvelle commande de pièces' => 'New parts order',
                "Nouvelle commande d'accessoires" => 'New accessories order',
                default => $title,
            };
            if (preg_match('/^Réf\. (.*?) — (\d+) article\(s\)$/su', $body, $m)) {
                $body = "Ref. {$m[1]} — {$m[2]} item(s)";
            }
        } elseif ($type === 'fuel_anomaly' && preg_match("/^(.*) : ([\d ,]+) l\/100 km, contre ([\d ,]+) l\/100 km d'habitude\. Faites vérifier le filtre à air et la pression des pneus — les pièces sont au catalogue\.$/su", $body, $m)) {
            $name = $m[1] === 'Votre vehicule' ? 'Your vehicle' : $m[1];
            $body = $name.': '.str_replace(',', '.', $m[2]).' l/100 km, usually '.str_replace(',', '.', $m[3]).' l/100 km. Have the air filter and tyre pressure checked — parts are in the catalogue.';
        } elseif (in_array($type, ['maintenance_service', 'maintenance_technical_inspection', 'maintenance_insurance'], true)) {
            $labels = ['Vidange' => 'Oil change', 'Visite technique' => 'Vehicle inspection', 'Assurance' => 'Insurance'];
            foreach ($labels as $fr => $en) {
                $title = match ($title) {
                    "$fr depassee" => "$en overdue",
                    "$fr bientot a echeance" => "$en due soon",
                    default => $title,
                };
                if (!preg_match('/^(.*) : '.preg_quote($fr, '/').' (.*)$/su', $body, $m)) {
                    continue;
                }
                $detail = $m[2];
                $detail = preg_replace('/^expiree depuis (\d+) jour\(s\)\.$/u', 'expired $1 day(s) ago.', $detail);
                $detail = preg_replace('/^expire dans (\d+) jour\(s\)\.$/u', 'expires in $1 day(s).', $detail);
                $detail = $detail === "expire aujourd'hui." ? 'expires today.' : $detail;
                $detail = preg_replace('/^— Dans (\d+) km\.$/u', '— due in $1 km.', $detail);
                $detail = preg_replace('/^— Depassee de (\d+) km\.$/u', '— overdue by $1 km.', $detail);
                if ($detail === $m[2]) {
                    continue; // A future or manual body is not an automatic template.
                }
                $name = $m[1] === 'Votre vehicule' ? 'Your vehicle' : $m[1];
                $body = "$name: $en $detail";
                break;
            }
        }

        // Manual broadcasts (tip) and unknown templates are deliberately literal.
        return compact('title', 'body');
    }
}
