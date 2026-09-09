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
