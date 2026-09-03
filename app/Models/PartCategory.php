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
}
