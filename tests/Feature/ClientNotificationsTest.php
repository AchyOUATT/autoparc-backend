<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La cloche de notifications, cote client.
 *
 * Les notifications client sont adressees au firebase_uid, jamais a l'id local
 * : c'est le seul identifiant que l'envoi (FCM) et la lecture partagent. Le
 * controleur le lisait dans les attributs de la requete, le middleware ne l'y
 * posait pas. Personne ne verifiait cette moitie de contrat, et le defaut ne se
 * voyait que dans les logs : unread-count rendait 500, et marquer une
 * notification lue rendait 403 — la comparaison se faisant contre null.
 *
 * Ces tests tiennent les quatre routes par leur resultat, pas par la mecanique
 * : ils echouent si l'attribut disparait a nouveau, quelle qu'en soit la cause.
 */
class ClientNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const UID = 'firebase-uid-test';

    private function notifier(string $uid, ?string $lue = null): AppNotification
    {
        return AppNotification::create([
            'recipient_type' => 'client',
            'recipient_id'   => $uid,
            'type'           => 'reminder',
            'title'          => 'Visite technique',
            'body'           => 'Elle expire dans 7 jours.',
            'read_at'        => $lue,
        ]);
    }

    public function test_le_compteur_ne_voit_que_les_non_lues_du_client(): void
    {
        $this->notifier(self::UID);
        $this->notifier(self::UID);
        $this->notifier(self::UID, now()->toDateTimeString());
        // Adressee a quelqu'un d'autre : elle ne doit jamais etre comptee.
        $this->notifier('un-autre-uid');

        $this->actingAsClient()
            ->getJson('/api/my/notifications/unread-count')
            ->assertOk()
            ->assertJson(['count' => 2]);
    }

    public function test_la_liste_ne_contient_que_les_notifications_du_client(): void
    {
        $this->notifier(self::UID);
        $this->notifier('un-autre-uid');

        $reponse = $this->actingAsClient()
            ->getJson('/api/my/notifications')
            ->assertOk();

        $this->assertCount(1, $reponse->json('data'));
        $this->assertSame(self::UID, $reponse->json('data.0.recipient_id'));
    }

    public function test_marquer_lue_fonctionne_pour_son_proprietaire(): void
    {
        $notification = $this->notifier(self::UID);

        $this->actingAsClient()
            ->postJson("/api/my/notifications/{$notification->id}/read")
            ->assertOk();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    /// La verification d'appartenance doit rester une vraie verification : avec
    /// un uid nul elle refusait tout le monde, ce qui ressemble a de la securite
    /// mais n'en est pas — elle ne distinguait plus personne.
    public function test_on_ne_marque_pas_lue_la_notification_d_un_autre(): void
    {
        $notification = $this->notifier('un-autre-uid');

        $this->actingAsClient()
            ->postJson("/api/my/notifications/{$notification->id}/read")
            ->assertForbidden();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_tout_marquer_lu_ne_touche_pas_les_autres_clients(): void
    {
        $sienne = $this->notifier(self::UID);
        $autre  = $this->notifier('un-autre-uid');

        $this->actingAsClient()
            ->postJson('/api/my/notifications/read-all')
            ->assertOk();

        $this->assertNotNull($sienne->fresh()->read_at);
        $this->assertNull($autre->fresh()->read_at);
    }
}
