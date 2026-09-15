<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * Rattache le catalogue fraichement seede aux modeles de vehicule.
 *
 * Il remplace PartSeeder et AccessorySeeder, qui creaient bien des
 * compatibilites mais n'etaient appeles nulle part — et qui, appeles, auraient
 * cree leurs propres pieces en doublon de la factory, rattachees a trois
 * modeles tires au hasard. Un filtre a huile de Corolla s'y declarait
 * compatible avec un Hilux.
 *
 * La logique vit dans la commande `catalog:backfill-fitments` plutot qu'ici :
 * la base en ligne existe deja et ne sera jamais reseedee, elle a donc besoin
 * de la meme chose sous forme de commande. Dupliquer le calcul aurait garanti
 * que les deux versions divergent.
 */
class FitmentsSeeder extends Seeder
{
    public function run(): void
    {
        Artisan::call('catalog:backfill-fitments', [], $this->command?->getOutput());
    }
}
