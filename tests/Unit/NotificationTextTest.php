<?php

namespace Tests\Unit;

use App\Support\NotificationText;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationTextTest extends TestCase
{
    public static function automaticMessages(): array
    {
        return [
            'vehicle match' => ['vehicle_match', 'Un véhicule correspond à votre recherche',
                "Citroën C4 vient d'être ajouté au catalogue.", 'A vehicle matches your search',
                'Citroën C4 has been added to the catalogue.'],
            'part with apostrophe in its name' => ['part_match', 'Pièce disponible !',
                "Une Filtration vient d'être ajoutée : Pièce qui vient d'être démontée",
                'Part available!', "Filtration added: Pièce qui vient d'être démontée"],
            'part with typographic template apostrophe' => ['part_match', 'Pièce disponible !',
                "Une Filtration vient d’être ajoutée : L'original de Paul", 'Part available!',
                "Filtration added: L'original de Paul"],
            'accessory' => ['accessory_match', 'Accessoire disponible !',
                "Un accessoire Confort vient d'être ajouté : Coussin de René", 'Accessory available!',
                'New Comfort accessory: Coussin de René'],
            'customer request preserves description' => ['new_need', 'Nouveau besoin client',
                'Type : Véhicule — Je cherche « Saison sèche », nom exact de la peinture.', 'New customer request',
                'Type: Vehicle — Je cherche « Saison sèche », nom exact de la peinture.'],
            'status preserves description' => ['need_status_update', 'Votre besoin a été mis à jour',
                'Statut : En attente — Description de René.', 'Your request has been updated',
                'Status: Pending — Description de René.'],
            'partner request with budget' => ['need_broadcast', 'Demande client à pourvoir',
                'Pièce détachée — budget 75 000 FCFA — Référence « Assurance » à conserver.', 'Customer request to fulfil',
                'Spare part — budget 75 000 FCFA — Référence « Assurance » à conserver.'],
            'partner request without budget' => ['need_broadcast', 'Demande client à pourvoir',
                'Accessoire — Coussin de René.', 'Customer request to fulfil', 'Accessory — Coussin de René.'],
            'parts order' => ['new_order', 'Nouvelle commande de pièces', 'Réf. CMD-É-001 — 2 article(s)',
                'New parts order', 'Ref. CMD-É-001 — 2 item(s)'],
            'accessories order' => ['new_order', "Nouvelle commande d'accessoires", 'Réf. ACC-001 — 1 article(s)',
                'New accessories order', 'Ref. ACC-001 — 1 item(s)'],
            'fuel alert' => ['fuel_anomaly', 'Consommation en hausse',
                "Ma voiture de René : 10,5 l/100 km, contre 8,0 l/100 km d'habitude. Faites vérifier le filtre à air et la pression des pneus — les pièces sont au catalogue.",
                'Fuel consumption has increased',
                'Ma voiture de René: 10.5 l/100 km, usually 8.0 l/100 km. Have the air filter and tyre pressure checked — parts are in the catalogue.'],
            'oil change due' => ['maintenance_service', 'Vidange bientot a echeance',
                'Ma voiture de René : Vidange — Dans 500 km.', 'Oil change due soon',
                'Ma voiture de René: Oil change — due in 500 km.'],
            'inspection overdue' => ['maintenance_technical_inspection', 'Visite technique depassee',
                'Citroën : Visite technique expiree depuis 2 jour(s).', 'Vehicle inspection overdue',
                'Citroën: Vehicle inspection expired 2 day(s) ago.'],
            'insurance today' => ['maintenance_insurance', 'Assurance bientot a echeance',
                "René : Assurance expire aujourd'hui.", 'Insurance due soon', 'René: Insurance expires today.'],
            'partner account approved' => ['partner_account_approved', 'Compte approuvé',
                'Votre compte est approuvé. Publiez votre première annonce.', 'Account approved',
                'Your account is approved. Publish your first listing.'],
            'partner account reinstated' => ['partner_account_approved', 'Compte approuvé',
                'Votre compte est de nouveau actif.', 'Account approved', 'Your account is active again.'],
            'partner account rejected keeps the reason' => ['partner_account_rejected', 'Demande refusée',
                'Votre demande a été refusée : Document de vérification manquant.', 'Application declined',
                'Your application was declined: Document de vérification manquant.'],
            'partner account suspended keeps the reason' => ['partner_account_suspended', 'Compte suspendu',
                'Votre compte est suspendu : Trois plaintes « non livré ».', 'Account suspended',
                'Your account is suspended: Trois plaintes « non livré ».'],
        ];
    }

    #[DataProvider('automaticMessages')]
    public function test_only_system_wording_is_translated_and_french_is_unchanged(
        string $type, string $title, string $body, string $englishTitle, string $englishBody,
    ): void {
        $this->assertSame(['title' => $englishTitle, 'body' => $englishBody], NotificationText::forLocale($title, $body, ['type' => $type], 'en'));
        $this->assertSame(compact('title', 'body'), NotificationText::forLocale($title, $body, ['type' => $type], 'fr'));
    }

    public function test_manual_tips_and_unknown_types_are_never_translated_even_when_they_resemble_system_messages(): void
    {
        $title = 'Nouveau besoin client';
        $body = 'Type : Véhicule — Texte rédigé librement.';
        foreach (['tip', 'future_notification', 'maintenance_custom'] as $type) {
            $this->assertSame(compact('title', 'body'), NotificationText::forLocale($title, $body, ['type' => $type], 'en'));
        }
    }

    public function test_an_unknown_maintenance_body_is_preserved_instead_of_rewriting_its_free_text(): void
    {
        $body = "René : Assurance cette phrase libre contient expire aujourd'hui. et doit rester entière.";
        $this->assertSame($body, NotificationText::forLocale('Titre libre', $body, ['type' => 'maintenance_insurance'], 'en')['body']);
    }
}
