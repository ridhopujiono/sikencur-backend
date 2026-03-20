<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_budgets', function (Blueprint $table) {
            $table->decimal('target_remaining', 15, 2)->nullable()->after('limit');
        });
    }

    public function down(): void
    {
        Schema::table('user_budgets', function (Blueprint $table) {
            $table->dropColumn('target_remaining');
        });
    }
};
