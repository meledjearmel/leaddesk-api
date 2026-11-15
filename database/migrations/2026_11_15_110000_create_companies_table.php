<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Configuration de départ choisie à la création : « blank » ou « studio » (RM-02).
            $table->string('template', 32);
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // RM-01 : un compte correspond à une entreprise. L'index est partiel pour
        // qu'une entreprise placée à la corbeille ne bloque pas une nouvelle création.
        DB::statement('CREATE UNIQUE INDEX companies_user_id_unique ON companies (user_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
