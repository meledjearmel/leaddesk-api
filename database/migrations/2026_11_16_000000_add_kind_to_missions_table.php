<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nature d'une mission (D-039) : vide pour une mission du CEO,
     * `office_layout` pour une demande de réaménagement automatique.
     */
    public function up(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->string('kind', 32)->nullable()->after('activity_id');
            $table->index(['company_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('missions', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'kind', 'status']);
            $table->dropColumn('kind');
        });
    }
};
