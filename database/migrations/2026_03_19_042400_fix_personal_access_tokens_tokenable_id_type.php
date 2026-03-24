<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        $column = DB::selectOne("SHOW COLUMNS FROM `personal_access_tokens` WHERE `Field` = 'tokenable_id'");

        if (! $column || ! isset($column->Type)) {
            return;
        }

        $type = strtolower((string) $column->Type);

        if (str_contains($type, 'char(36)') || str_contains($type, 'varchar(36)')) {
            return;
        }

        DB::statement('ALTER TABLE `personal_access_tokens` MODIFY `tokenable_id` CHAR(36) NOT NULL');
    }

    public function down(): void
    {
        //
    }
};
