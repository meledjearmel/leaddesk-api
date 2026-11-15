<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Type d'activité : un modèle de travail créé sans code, avec ses champs
        // propres et une base de tâches proposée (RM-20).
        Schema::create('activity_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            // Unité responsable par défaut des activités de ce type.
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            // Parcours des activités de ce type et parcours de leurs tâches (RM-18).
            $table->foreignId('activity_workflow_id')->nullable()->constrained('workflows')->nullOnDelete();
            $table->foreignId('task_workflow_id')->nullable()->constrained('workflows')->nullOnDelete();
            // Champs propres au métier : [{key, label, type, required, options}].
            $table->json('fields')->nullable();
            // Base de tâches proposée : [{title, description}].
            $table->json('task_templates')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_types');
    }
};
