<?php

use App\Models\Part;
use Illuminate\Database\Migrations\Migration;

/**
 * Pose le stock initial des filtres a huile : quinze de chaque.
 *
 * Les dix references ont ete publiees sans quantite, donc a zero. L'ecran
 * affichait « Rupture de stock » et desactivait le bouton d'achat — la
 * boutique etait en ligne mais rien n'etait vendable.
 *
 * Pourquoi une migration et non le seeder. Depuis sa correction, celui-ci ne
 * pose prix et stock qu'a la CREATION d'une reference : il ne les reecrit plus
 * ensuite, pour qu'un ajustement fait depuis l'application survive au
 * deploiement suivant. Les dix pieces existant deja, modifier le seeder ne les
 * aurait pas touchees. Ce geste doit donc etre explicite — c'est tout l'objet
 * de ce fichier.
 *
 * Elle ne touche QUE les references a zero. Si le commercant a deja corrige un
 * stock depuis l'application entre la publication et ce deploiement, sa valeur
 * est conservee : une migration de rattrapage n'a pas a defaire un inventaire
 * fait a la main.
 */
return new class extends Migration
{
    private const STOCK = 15;

    private const REFERENCES = [
        '90915-YZZF2', '90915-YZZE1', '90915-YZZD1', '90915-YZZD2',
        '90915-YZZN1', '90915-YZZN2', '04152-YZZA1', '04152-YZZA6',
        '90915-YZZD4', '90915-10001',
    ];

    public function up(): void
    {
        $touchees = Part::whereIn('sku', self::REFERENCES)
            ->where('stock_quantity', 0)
            ->update(['stock_quantity' => self::STOCK]);

        echo sprintf(
            '  %d reference(s) passee(s) a %d en stock.%s',
            $touchees,
            self::STOCK,
            PHP_EOL,
        );
    }

    /**
     * Pas de retour arriere.
     *
     * Remettre zero effacerait les mouvements de stock faits depuis. Un
     * inventaire se corrige depuis l'application, pas en rejouant une
     * migration a l'envers.
     */
    public function down(): void
    {
        //
    }
};
