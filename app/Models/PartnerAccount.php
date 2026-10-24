<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Compte d'une entreprise vendeuse, distinct de l'annuaire de fournisseurs Partner. */
class PartnerAccount extends Model
{
    public const STATUSES = ['pending', 'approved', 'rejected', 'suspended'];

    protected $fillable = [
        'user_id', 'company_name', 'contact_name', 'phone', 'whatsapp',
        'status', 'review_reason', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
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
