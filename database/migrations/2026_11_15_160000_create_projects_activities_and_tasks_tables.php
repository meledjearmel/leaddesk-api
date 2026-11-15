<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Projet : regroupement facultatif d'activités (RM-17).
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
        });

        // Activité : un travail concret créé depuis un type. Elle peut exister
        // sans projet et mobiliser plusieurs équipes (RM-17).
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            // Étape en cours dans le workflow d'activité du type.
            $table->foreignId('workflow_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            // Valeurs des champs propres au type : {clé: valeur}.
            $table->json('field_values')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'project_id']);
            $table->index(['company_id', 'activity_type_id']);
        });

        // Équipes mobilisées par une activité.
        Schema::create('activity_unit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();

            $table->unique(['activity_id', 'unit_id']);
        });

        // Tâche : une part du travail d'une activité, confiée à une équipe.
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            // Équipe dont le responsable confirme la fin de la tâche (RM-19).
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            // Étape en cours dans le workflow de tâche du type (RM-18).
            $table->foreignId('workflow_step_id')->nullable()->constrained('workflow_steps')->nullOnDelete();
            // Confirmation de fin : par le responsable d'équipe, ou par le CEO
            // quand aucune équipe de la hiérarchie n'a de responsable en service.
            $table->foreignId('confirmed_by_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->boolean('confirmed_by_ceo')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'activity_id']);
            $table->index(['company_id', 'unit_id']);
        });

        // Agents affectés à une tâche : un agent principal et des collaborateurs,
        // ou plusieurs agents ensemble.
        Schema::create('agent_task', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_lead')->default(false);
            $table->timestamps();

            $table->unique(['task_id', 'agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_task');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('activity_unit');
        Schema::dropIfExists('activities');
        Schema::dropIfExists('projects');
    }
};
