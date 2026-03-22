<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationPreference extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'push_enabled',
        'weekly_summary_enabled',
        'budget_alert_enabled',
        'dss_tips_enabled',
        'quiet_hours_start',
        'quiet_hours_end',
        'timezone',
    ];

    protected function casts(): array
    {
        return [
            'push_enabled' => 'boolean',
            'weekly_summary_enabled' => 'boolean',
            'budget_alert_enabled' => 'boolean',
            'dss_tips_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
