<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptScan extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'file_path',
        'status',
        'result_data',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'result_data' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
