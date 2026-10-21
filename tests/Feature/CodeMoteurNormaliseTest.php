<?php

namespace Tests\Feature;

use App\Http\Requests\CompatiblePartsCriteriaRequest;
use App\Http\Requests\StoreOwnedVehicleRequest;
use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateOwnedVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le code moteur arrive en MAJUSCULES, par quelque porte qu'il entre.
 *
 * ── POURQUOI CETTE REGLE EXISTE ────────────────────────────────────────────
 *
 * `CompatibilityService` compare le code moteur par EGALITE EXACTE :
 *
 *     $q->whereNull('engine_code')->orWhere('engine_code', $engineCode)
 *
 * et le referentiel les stocke en majuscules avec tiret — « 2AR-FE »,
 * « A25A-FKS », « 1GD-FTV ». Un code saisi « 2ar-fe » ne rencontre donc
 * JAMAIS la ligne qui lui correspond : la recherche ne rend que les
 * compatibilites sans code moteur, et l'utilisateur conclut qu'il n'y en a
 * pas. Sur PostgreSQL, qui est le moteur de la production, la comparaison est
 * sensible a la casse — ce n'est pas une precaution theorique.
 *
 * ── CE QUE CE BANC CORRIGE, ET A QUI LA FAUTE ──────────────────────────────
 *
 * La normalisation n'existait QUE sur la route publique de recherche
 * (`CompatiblePartsCriteriaRequest`), posee avec le filtrage par code moteur.
 * Les DEUX cotes de la saisie — le garage et le catalogue commercial — ne la
 * faisaient pas, alors que ce sont eux qui ECRIVENT la valeur comparee.
 *
 * Autrement dit : on avait soigne la question et laisse la reponse en l'etat.
 * Releve par un audit externe du 21/10/2026, pas par la relecture interne.
 *
 * ── POURQUOI UN BANC SUR LES REQUETES ET NON SUR LES ROUTES ────────────────
 *
 * Le defaut vit dans `prepareForValidation`, qui s'execute avant les regles et
 * avant le controleur. Un test de route passerait par l'authentification
 * Firebase ou Sanctum et par des fixtures de vehicule, pour mesurer une
 * transformation de chaine qui se verifie ici directement. Les quatre classes
 * sont instanciees telles que le cadre les instancie.
 */
class CodeMoteurNormaliseTest extends TestCase
{
    /**
     * Les quatre portes d'entree qui ECRIVENT un code moteur, plus la route
     * publique qui le LIT. Les deux `Update` heritent de leur `Store` et ne
     * surchargent pas `prepareForValidation` — c'est verifie par leur presence
     * ici, puisqu'un jour ou l'une d'elles le surchargerait sans normaliser,
     * son cas tomberait.
     */
    public static function portes(): array
    {
        return [
            'garage, creation'      => [StoreOwnedVehicleRequest::class],
            'garage, modification'  => [UpdateOwnedVehicleRequest::class],
            'catalogue, creation'   => [StoreVehicleRequest::class],
            'catalogue, modification' => [UpdateVehicleRequest::class],
            'recherche publique'    => [CompatiblePartsCriteriaRequest::class],
        ];
    }

    #[DataProvider('portes')]
    public function test_un_code_minuscule_arrive_en_majuscules(string $classe): void
    {
        $requete = $classe::create('/', 'POST', ['engine_code' => '2ar-fe']);

        $this->preparer($requete);

        $this->assertSame(
            '2AR-FE',
            $requete->input('engine_code'),
            "{$classe} laisse passer un code moteur en minuscules : la recherche de pieces ".
            'ne le retrouvera jamais, et le vendeur conclura qu\'aucune piece ne convient.'
        );
    }

    #[DataProvider('portes')]
    public function test_les_espaces_autour_sont_retires(string $classe): void
    {
        $requete = $classe::create('/', 'POST', ['engine_code' => '  a25a-fks  ']);

        $this->preparer($requete);

        $this->assertSame('A25A-FKS', $requete->input('engine_code'), $classe);
    }

    #[DataProvider('portes')]
    public function test_une_chaine_vide_devient_nulle(string $classe): void
    {
        // Une chaine vide passerait la regle `nullable` et serait ECRITE telle
        // quelle. `whereNull` ne l'attraperait pas, et l'egalite non plus : le
        // vehicule n'aurait ni code moteur ni absence de code moteur.
        $requete = $classe::create('/', 'POST', ['engine_code' => '   ']);

        $this->preparer($requete);

        $this->assertNull($requete->input('engine_code'), $classe);
    }

    #[DataProvider('portes')]
    public function test_un_code_deja_correct_ne_bouge_pas(string $classe): void
    {
        $requete = $classe::create('/', 'POST', ['engine_code' => '1GD-FTV']);

        $this->preparer($requete);

        $this->assertSame('1GD-FTV', $requete->input('engine_code'), $classe);
    }

    #[DataProvider('portes')]
    public function test_un_champ_absent_le_reste(string $classe): void
    {
        // La distinction compte : `has()` et non `filled()`. Une requete qui
        // n'envoie pas le champ ne doit pas se voir ajouter un `engine_code`
        // nul, qui EFFACERAIT la valeur existante lors d'une modification
        // partielle.
        $requete = $classe::create('/', 'POST', ['vehicle_model_id' => 1]);

        $this->preparer($requete);

        $this->assertFalse(
            $requete->has('engine_code'),
            "{$classe} ajoute un engine_code la ou la requete n'en envoyait pas : ".
            'une modification partielle effacerait le code moteur existant.'
        );
    }

    /**
     * `prepareForValidation` est `protected` : le cadre l'appelle, pas nous.
     * On passe donc par la reflexion plutot que de monter une route et son
     * authentification pour mesurer une transformation de chaine.
     */
    private function preparer(object $requete): void
    {
        $methode = new \ReflectionMethod($requete, 'prepareForValidation');
        $methode->setAccessible(true);
        $methode->invoke($requete);
    }
}
