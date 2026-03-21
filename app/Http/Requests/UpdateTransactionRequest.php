<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'merchant_name' => ['sometimes', 'nullable', 'string'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price_total' => ['sometimes', 'numeric'],
            'tax' => ['sometimes', 'nullable', 'numeric'],
            'service_charge' => ['sometimes', 'nullable', 'numeric'],
            'transaction_date' => ['sometimes', 'date_format:Y-m-d H:i:s'],
            'input_method' => ['sometimes', 'string'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.item_name' => ['required_with:items', 'string'],
            'items.*.price' => ['required_with:items', 'numeric'],
            'items.*.category' => ['nullable', 'string'],
        ];
    }
}
