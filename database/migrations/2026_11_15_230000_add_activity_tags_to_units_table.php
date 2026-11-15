<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Étiquettes d'activité d'une unité (D-037) : elles indiquent à
     * l'aménagement les salles dont l'unité a besoin.
     */
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->json('activity_tags')->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table) {
            $table->dropColumn('activity_tags');
        });
    }
};
