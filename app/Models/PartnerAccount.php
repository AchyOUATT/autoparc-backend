<?php

namespace App\Models;

use App\Support\NumeroDeTelephone;
use Illuminate\Database\Eloquent\Model;

/** Compte d'une entreprise vendeuse, distinct de l'annuaire de fournisseurs Partner. */
class PartnerAccount extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'suspended'];

    /**
     * `phone_key` n'est PAS remplissable : elle se derive de `phone`.
     *
     * La laisser dans cette liste permettrait de la poser a une valeur qui ne
     * correspond pas au numero affiche — et c'est elle qui porte l'unicite,
     * donc l'identite du compte. Le crochet `saving` plus bas la tient a jour,
     * quel que soit le chemin d'ecriture.
     */
    protected $fillable = [
        'user_id', 'company_name', 'contact_name', 'phone', 'whatsapp',
        'status', 'review_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    /**
     * Le numero normalise suit le numero, toujours.
     *
     * Ce crochet plutot qu'une ligne dans le controleur : il n'y a pas qu'un
     * chemin d'ecriture — inscription, correction par le personnel, fabrique
     * de banc, commande de reprise — et un seul oubli rendrait un compte
     * injoignable par la connexion tout en laissant son numero s'afficher sur
     * les annonces.
     */
    protected static function booted(): void
    {
        static::saving(function (self $compte) {
            if ($compte->isDirty('phone') || $compte->phone_key === null) {
                $compte->phone_key = NumeroDeTelephone::cle($compte->phone);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
