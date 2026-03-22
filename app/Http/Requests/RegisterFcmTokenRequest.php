<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterFcmTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fcm_token' => ['required', 'string', 'max:500'],
            'platform' => ['nullable', 'string', 'in:android,ios,web,unknown'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
