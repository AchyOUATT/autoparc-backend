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
    ];

    protected function casts(): array
    {
        return ['is_cover' => 'boolean'];
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
