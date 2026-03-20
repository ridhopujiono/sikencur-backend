<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DssProfile extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'profile_key',
        'profile_label',
        'confidence',
        'window_months',
        'features',
        'scores',
        'reasons',
        'ruleset_version',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:4',
            'window_months' => 'integer',
            'features' => 'array',
            'scores' => 'array',
            'reasons' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
