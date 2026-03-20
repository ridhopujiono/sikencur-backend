<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_range' => ['nullable', 'string', 'in:today,this_week,this_month,this_year,last_7_days,last_30_days'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'category' => ['nullable', 'string'],
            'transaction_type' => ['nullable', 'string'],
            'merchant_name' => ['nullable', 'string'],
            'input_method' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
            'min_total' => ['nullable', 'numeric', 'min:0'],
            'max_total' => ['nullable', 'numeric', 'gte:min_total'],
            'sort_by' => ['nullable', 'string', 'in:transaction_date,price_total,created_at'],
            'sort_direction' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
