<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'merchant_name' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'price_total' => ['required', 'numeric'],
            'tax' => ['nullable', 'numeric'],
            'service_charge' => ['nullable', 'numeric'],
            'transaction_date' => ['required', 'date_format:Y-m-d H:i:s'],
            'input_method' => ['required', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_name' => ['required', 'string'],
            'items.*.price' => ['required', 'numeric'],
            'items.*.category' => ['nullable', 'string'],
        ];
    }
}
