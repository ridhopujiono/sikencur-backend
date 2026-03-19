<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            'Food & Beverage',
            'Transportation',
            'Shopping',
            'Bills & Utilities',
            'Health',
            'Entertainment',
            'Education',
            'Salary',
            'Investment',
            'Other',
        ];

        foreach ($categories as $name) {
            Category::query()->firstOrCreate([
                'name' => $name,
            ]);
        }
    }
}
