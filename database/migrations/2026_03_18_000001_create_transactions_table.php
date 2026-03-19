<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('merchant_name')->nullable();
            $table->text('description')->nullable();
            $table->decimal('price_total', 15, 2);
            $table->decimal('tax', 15, 2)->nullable();
            $table->decimal('service_charge', 15, 2)->nullable();
            $table->dateTime('transaction_date');
            $table->string('input_method');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
