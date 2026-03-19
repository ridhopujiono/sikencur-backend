<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $column = DB::selectOne("SHOW COLUMNS FROM `users` WHERE `Field` = 'id'");

        if (! $column || ! isset($column->Type)) {
            return;
        }

        $type = strtolower((string) $column->Type);

        if (str_contains($type, 'char(36)') || str_contains($type, 'varchar(36)')) {
            return;
        }

        DB::statement('ALTER TABLE `users` MODIFY `id` CHAR(36) NOT NULL');
    }

    public function down(): void
    {
        //
    }
};
