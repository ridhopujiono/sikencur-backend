<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dss_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('profile_key');
            $table->string('profile_label');
            $table->decimal('confidence', 5, 4);
            $table->unsignedTinyInteger('window_months');
            $table->json('features');
            $table->json('scores');
            $table->json('reasons');
            $table->string('ruleset_version')->default('dss-v1');
            $table->timestamp('analyzed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dss_profiles');
    }
};
