<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class PartCategory extends Model
{
    use HasFactory;

    protected $fillable = ['parent_id', 'name', 'slug'];

    protected static function booted(): void
    {
        static::saving(function (PartCategory $category) {
            $category->slug = $category->slug ?: Str::slug($category->name);
        });
    }

    public function parent()
    {
        return $this->belongsTo(PartCategory::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(PartCategory::class, 'parent_id');
    }

    public function parts()
    {
        return $this->hasMany(Part::class);
    }

    /**
     * L'arbre reduit aux categories qui menent quelque part.
     *
     * Les 89 categories sont servies telles quelles depuis toujours, et
     * l'application en propose les 9 racines en barre de filtres. Le catalogue
     * n'en occupe qu'une : « Moteur », par la feuille « Filtre a huile ». Les
     * huit autres pastilles — Carrosserie, Climatisation, Echappement,
     * Electricite, Freinage, Pneus & Roues, Suspension & Direction,
     * Transmission — rendaient zero resultat. Une barre de filtres qui promet
     * neuf entrees et n'en honore qu'une se lit comme une panne.
     *
     * Une categorie est gardee si elle porte elle-meme une piece visible du
     * catalogue, ou si l'une de ses descendantes en porte une : « Moteur » doit
     * survivre pour mener a « Filtre a huile », sans quoi la seule branche
     * peuplee serait injoignable depuis les racines.
     *
     * « Visible du catalogue » veut dire ici exactement ce que la liste des
     * pieces entend par la : `active()`, soit `is_active` et non archivee. Pas
     * `is_available` : la liste affiche les ruptures de stock, et une pastille
     * qui les ignorerait annoncerait vide une categorie dont l'ecran montre les
     * articles.
     *
     * `has_own_parts` distingue une categorie qui porte des pieces d'une
     * categorie qui n'est la que pour mener a ses descendantes. L'application
     * s'en sert pour descendre dans l'arbre jusqu'au niveau qui separe
     * vraiment : sans ce drapeau, descendre sous une categorie qui porte ses
     * propres pieces les rendrait injoignables.
     *
     * La forme des lignes est identique a celle de l'arbre complet, a ce
     * drapeau pres : l'application lit le meme modele, et aucun compteur ne
     * voyage — un nombre fige dans un cache de 24 h annoncerait dix pieces la
     * ou il n'y en a plus.
     *
     * @return \Illuminate\Support\Collection<int, array{id:int,parent_id:?int,name:string,has_own_parts:bool}>
     */
    public static function arbreNonVide(): \Illuminate\Support\Collection
    {
        $toutes = self::query()->orderBy('name')->get(['id', 'parent_id', 'name']);

        // `distinct` sur une seule colonne plutot qu'un GROUP BY : MySQL tourne
        // ici en mode strict et PostgreSQL applique ONLY_FULL_GROUP_BY, ou un
        // regroupement sur des colonnes non agregees est refuse.
        $portantDesPieces = Part::query()
            ->active()
            ->whereNotNull('part_category_id')
            ->distinct()
            ->pluck('part_category_id')
            ->all();

        $parId   = $toutes->keyBy('id');
        $propres = array_fill_keys($portantDesPieces, true);
        $gardees = [];

        // Remontee vers la racine depuis chaque categorie peuplee. L'arret des
        // qu'un ancetre est deja garde evite de reparcourir la meme branche.
        foreach ($portantDesPieces as $id) {
            $courant = $id;

            while ($courant !== null && ! isset($gardees[$courant])) {
                $gardees[$courant] = true;
                $courant = $parId[$courant]->parent_id ?? null;
            }
        }

        return $toutes
            ->filter(fn (self $categorie) => isset($gardees[$categorie->id]))
            ->map(fn (self $categorie) => [
                'id'            => $categorie->id,
                'parent_id'     => $categorie->parent_id,
                'name'          => $categorie->name,
                'has_own_parts' => isset($propres[$categorie->id]),
            ])
            ->values();
    }

    /**
     * Identifiants de la categorie et de toutes ses descendantes.
     *
     * L'arbre compte trois niveaux et les pieces sont rattachees aux feuilles :
     * filtrer sur « Freinage » sans descendre ne renverrait aucun resultat,
     * alors que c'est precisement le niveau propose a l'utilisateur.
     *
     * L'arbre est petit — quelques dizaines de lignes — donc une seule requete
     * puis un parcours en memoire, plutot qu'une requete par niveau.
     *
     * @return array<int, int>
     */
    public static function descendantIds(int $id): array
    {
        $byParent = self::query()
            ->select(['id', 'parent_id'])
            ->get()
            ->groupBy('parent_id');

        $ids   = [$id];
        $queue = [$id];

        while ($queue) {
            $current = array_shift($queue);

            foreach ($byParent[$current] ?? [] as $child) {
                $ids[]   = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
    }
}
