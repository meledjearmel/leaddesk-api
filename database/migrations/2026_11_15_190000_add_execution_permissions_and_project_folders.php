<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Permissions d'exécution du poste : pour chaque outil local, « allow »
        // (autorisé d'office), « ask » (soumis à approbation) ou « deny ».
        Schema::table('positions', function (Blueprint $table) {
            $table->json('tool_permissions')->nullable();
        });

        // Dossiers autorisés d'un projet sur un ordinateur. Le serveur ne garde
        // que le chemin : aucun fichier de travail n'y passe jamais.
        Schema::create('project_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            // « documentation » ou « code ».
            $table->string('kind', 20);
            $table->string('path', 1000);
            // Date de l'autorisation donnée par le CEO pour ce chemin.
            $table->timestamp('authorized_at');
            $table->timestamps();

            $table->unique(['project_id', 'device_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_folders');
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('tool_permissions');
        });
    }
};
