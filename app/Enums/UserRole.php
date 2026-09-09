<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin     = 'admin';
    case Manager   = 'manager';
    case Sales     = 'sales';
    case Mechanic  = 'mechanic';
    case Warehouse = 'warehouse';
    case Viewer    = 'viewer';
    case Client    = 'client'; // compte public : gere son propre garage, pas d'acces back-office

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Tout role sauf Client a acces au back-office. */
    public function isStaff(): bool
    {
        return $this !== self::Client;
    }

    // ── Capacites ────────────────────────────────────────────────────────
    //
    // EnsureStaff ne distingue que staff et client : sans ce qui suit, un
    // compte « viewer » creait et supprimait des vehicules comme un
    // administrateur. Les capacites sont exprimees par verbe plutot que par
    // role, pour que les regles se lisent la ou elles s'appliquent.

    /** Creer et modifier une fiche du catalogue. */
    public function canManageCatalog(): bool
    {
        return in_array($this, [self::Admin, self::Manager, self::Sales], true);
    }

    /**
     * Supprimer definitivement une fiche.
     *
     * Volontairement plus restreint que la modification : une suppression ne
     * se rattrape pas depuis l'application.
     */
    public function canDeleteCatalog(): bool
    {
        return in_array($this, [self::Admin, self::Manager], true);
    }

    /** Declarer et resoudre une panne. */
    public function canManageFaults(): bool
    {
        return in_array($this, [self::Admin, self::Manager, self::Mechanic], true);
    }
}
