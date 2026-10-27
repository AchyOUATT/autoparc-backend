<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'type',
        'title',
        'body',
        'data',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data'    => 'array',
            'read_at' => 'datetime',
        ];
    }

    // ── Scopes ───────────────────────────────────────────────────────

    public function scopeForStaff($query, int $userId)
    {
        return $query->where('recipient_type', 'staff')
                     ->where('recipient_id', (string) $userId);
    }

    public function scopeForClient($query, string $firebaseUid)
    {
        return $query->where('recipient_type', 'client')
                     ->where('recipient_id', $firebaseUid);
    }

    /**
     * La boite d'un partenaire, designee par l'identifiant de son UTILISATEUR.
     *
     * Et non par celui de son compte partenaire : c'est l'utilisateur qui se
     * connecte et qui lit, et `recipient_id` designe partout celui qui lit —
     * un identifiant d'utilisateur pour le personnel, un `firebase_uid` pour
     * un client. Employer l'autre obligerait chaque lecture a traverser une
     * jointure pour repondre a « est-ce la mienne ».
     */
    public function scopeForPartner($query, int $userId)
    {
        return $query->where('recipient_type', 'partner')
                     ->where('recipient_id', (string) $userId);
    }

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    // ── Factory methods ──────────────────────────────────────────────

    /**
     * Cree une notification pour tous les utilisateurs staff.
     */
    public static function notifyAllStaff(
        string $type,
        string $title,
        string $body,
        array  $data = [],
    ): void {
        $staffIds = User::whereIn('role', UserRole::staffValues())->pluck('id');

        $rows = $staffIds->map(fn ($id) => [
            'recipient_type' => 'staff',
            'recipient_id'   => (string) $id,
            'type'           => $type,
            'title'          => $title,
            'body'           => $body,
            'data'           => json_encode($data),
            'created_at'     => now(),
            'updated_at'     => now(),
        ])->all();

        self::insert($rows);
    }

    /**
     * Diffuse a tous les partenaires APPROUVES et ACTIFS.
     *
     * ── LES DEUX FILTRES, ET CE QU'ILS EMPECHENT ──────────────────────
     *
     * `status = approved` : un compte en attente n'a pas encore ete examine,
     * un compte rejete ou suspendu l'a ete et ne doit plus rien recevoir.
     * Leur diffuser les demandes des clients reviendrait a donner le marche
     * a des vendeurs que la boutique n'a pas valides — ou qu'elle a ecartes.
     *
     * `is_active` sur l'utilisateur : un compte desactive ne doit plus rien
     * recevoir non plus, et c'est le meme drapeau que celui qui lui refuse
     * l'acces a tout le reste.
     *
     * ── POURQUOI UNE SEULE INSERTION ──────────────────────────────────
     *
     * Une ligne par partenaire, posee d'un coup. Quinze partenaires font
     * quinze lignes et UNE requete : une boucle de `create()` en ferait
     * quinze, dans une transaction qui bloque la reponse du personnel.
     *
     * @return int  le nombre de partenaires touches
     */
    public static function notifyAllPartners(
        string $type,
        string $title,
        string $body,
        array  $data = [],
    ): int {
        $ids = User::query()
            ->where('role', UserRole::Partner)
            ->where('is_active', true)
            ->whereHas('partnerAccount', fn ($q) => $q->where('status', 'approved'))
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        self::insert($ids->map(fn ($id) => [
            'recipient_type' => 'partner',
            'recipient_id'   => (string) $id,
            'type'           => $type,
            'title'          => $title,
            'body'           => $body,
            'data'           => json_encode($data),
            'created_at'     => now(),
            'updated_at'     => now(),
        ])->all());

        return $ids->count();
    }

    /**
     * Cree une notification pour un client identifié par son firebase_uid.
     */
    public static function notifyClient(
        string $firebaseUid,
        string $type,
        string $title,
        string $body,
        array  $data = [],
    ): void {
        self::create([
            'recipient_type' => 'client',
            'recipient_id'   => $firebaseUid,
            'type'           => $type,
            'title'          => $title,
            'body'           => $body,
            'data'           => $data,
        ]);
    }

    /**
     * Broadcast a tous les clients qui ont un token FCM enregistré.
     */
    public static function broadcastToClients(
        string $type,
        string $title,
        string $body,
        array  $data = [],
    ): void {
        $uids = ClientFcmToken::distinct()->pluck('firebase_uid');

        $rows = $uids->map(fn ($uid) => [
            'recipient_type' => 'client',
            'recipient_id'   => $uid,
            'type'           => $type,
            'title'          => $title,
            'body'           => $body,
            'data'           => json_encode($data),
            'created_at'     => now(),
            'updated_at'     => now(),
        ])->all();

        if ($rows) {
            self::insert($rows);
        }
    }
}
