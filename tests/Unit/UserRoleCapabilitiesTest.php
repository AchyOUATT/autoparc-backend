<?php

namespace Tests\Unit;

use App\Enums\UserRole;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Verrouille la matrice des droits.
 *
 * Elargir une capacite doit etre un geste delibere : si quelqu'un ajoute un
 * role a `canDeleteCatalog`, ce test tombe et le dit.
 */
class UserRoleCapabilitiesTest extends TestCase
{
    /**
     * Capacite → roles qui la possedent. Tout role absent doit se voir refuser.
     *
     * @return array<string, array{string, array<int, UserRole>}>
     */
    public static function matrix(): array
    {
        return [
            'manage-catalog'   => ['manage-catalog',   [UserRole::Admin, UserRole::Manager, UserRole::Sales]],
            'delete-catalog'   => ['delete-catalog',   [UserRole::Admin, UserRole::Manager]],
            'manage-faults'    => ['manage-faults',    [UserRole::Admin, UserRole::Manager, UserRole::Mechanic]],
            'manage-stock'     => ['manage-stock',     [UserRole::Admin, UserRole::Manager, UserRole::Warehouse]],
            'manage-orders'    => ['manage-orders',    [UserRole::Admin, UserRole::Manager, UserRole::Sales]],
            'manage-customers' => ['manage-customers', [UserRole::Admin, UserRole::Manager, UserRole::Sales]],
            'manage-partners'  => ['manage-partners',  [UserRole::Admin, UserRole::Manager]],
            'broadcast'        => ['broadcast',        [UserRole::Admin, UserRole::Manager]],
        ];
    }

    /** @param array<int, UserRole> $allowed */
    #[DataProvider('matrix')]
    public function test_capacite_accordee_aux_seuls_roles_prevus(string $capability, array $allowed): void
    {
        foreach (UserRole::cases() as $role) {
            $expected = in_array($role, $allowed, true);

            $this->assertSame(
                $expected,
                $role->can($capability),
                sprintf(
                    '%s devrait %s « %s »',
                    $role->value,
                    $expected ? 'pouvoir' : 'se voir refuser',
                    $capability,
                ),
            );
        }
    }

    public function test_le_client_ne_possede_aucune_capacite_back_office(): void
    {
        foreach (array_keys(self::matrix()) as $capability) {
            $this->assertFalse(
                UserRole::Client->can($capability),
                "Le role client ne doit jamais obtenir « {$capability} ».",
            );
        }
    }

    /**
     * Une faute de frappe dans une route ferme l'acces, elle ne l'ouvre pas.
     */
    public function test_une_capacite_inconnue_est_refusee_a_tous(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->assertFalse($role->can('manage-catalogue'));
            $this->assertFalse($role->can(''));
        }
    }
}
