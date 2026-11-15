<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
/** A private quote, or a staff-published redacted copy. Paths stay off the public API. */
class FaultRepairQuote extends Model
{
    public const KIND_PRIVATE = 'private';

    public const KIND_PUBLISHED = 'published';

    public const MIME_EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    protected $fillable = [
        'kind', 'original_name', 'mime_type', 'size', 'disk', 'path', 'uploaded_by',
    ];

    protected $hidden = ['disk', 'path', 'uploaded_by'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function fault()
    {
        return $this->morphTo();
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isPublished(): bool
    {
        return $this->kind === self::KIND_PUBLISHED;
    }

    public function publicUrl(): ?string
    {
        if (! $this->isPublished()) {
            return null;
        }

        return url('/api/catalog/repair-quotes/'.$this->id);
    }
}
