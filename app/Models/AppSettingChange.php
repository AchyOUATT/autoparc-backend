<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppSettingChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['key', 'old_value', 'new_value', 'user_id', 'created_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'user_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
