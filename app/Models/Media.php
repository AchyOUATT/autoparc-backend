<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    protected $table = 'media';

    protected $fillable = [
        'collection', 'path', 'disk', 'mime_type', 'size',
        'caption', 'position', 'is_cover',
        // La variante bornee a 1200x630 servie a og:image, et ses dimensions
        // reelles. Nullables : les lignes anterieures a sa generation servent
        // leur original, et `media:apercus` les rattrape. Voir VignetteApercu.
        'preview_path', 'preview_width', 'preview_height',
        // Les echecs de fabrication de cette variante. Ce n'est pas de la
        // statistique : c'est ce qui permet au rattrapage d'avancer au lieu de
        // buter indefiniment sur la meme ligne. Voir GenererApercusMedia.
        'preview_attempts', 'preview_failed_at',
    ];

    protected function casts(): array
    {
        return [
            'is_cover'          => 'boolean',
            'preview_width'     => 'integer',
            'preview_height'    => 'integer',
            'preview_attempts'  => 'integer',
            'preview_failed_at' => 'datetime',
        ];
    }

    public function mediable()
    {
        return $this->morphTo();
    }

    public function getUrlAttribute(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }
}
