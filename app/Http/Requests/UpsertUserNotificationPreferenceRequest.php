<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertUserNotificationPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'push_enabled' => ['sometimes', 'boolean'],
            'weekly_summary_enabled' => ['sometimes', 'boolean'],
            'budget_alert_enabled' => ['sometimes', 'boolean'],
            'dss_tips_enabled' => ['sometimes', 'boolean'],
            'quiet_hours_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['sometimes', 'nullable', 'date_format:H:i'],
            'timezone' => ['sometimes', 'string', 'max:100'],
        ];
    }
}
