<?php

namespace App\Models;

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
        $staffIds = User::pluck('id');

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
