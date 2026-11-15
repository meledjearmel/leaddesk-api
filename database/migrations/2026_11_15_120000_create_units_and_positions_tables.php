<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Unités hiérarchiques imbriquées : départements, pôles, équipes…
        // Leur nombre et leur profondeur ne sont pas fixés (RM-03).
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->string('name', 120);
            // Libellé libre du niveau (« Département », « Pôle »…), choisi par le CEO.
            $table->string('kind', 40)->nullable();
            // Couleur d'affichage, par exemple « #7C6BD6 ».
            $table->string('color', 9)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'parent_id']);
        });

        // Postes : fonction, mission, responsabilités et place hiérarchique.
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('reports_to_id')->nullable()->constrained('positions')->restrictOnDelete();
            $table->string('title', 120);
            $table->text('mission')->nullable();
            $table->json('responsibilities')->nullable();
            // Vrai pour le poste de responsable de l'unité.
            $table->boolean('is_head')->default(false);
            // « draft » ou « active » (RM-04 : les postes du modèle studio sont en brouillon).
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
        Schema::dropIfExists('units');
    }
};
