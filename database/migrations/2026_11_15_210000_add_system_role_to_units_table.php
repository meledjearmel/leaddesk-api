<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rôle système d'une unité (D-013) : RH et Moyens généraux, une seule de
     * chaque par entreprise.
     */
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->string('system_role', 20)->nullable()->after('kind');
        });

        // Index unique partiel : les unités sans rôle ne sont pas concernées.
        DB::statement('CREATE UNIQUE INDEX units_company_system_role_unique ON units (company_id, system_role) WHERE system_role IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS units_company_system_role_unique');

        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('system_role');
        });
    }
};
