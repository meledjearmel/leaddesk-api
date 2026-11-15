<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agent : identité stable, un seul poste à la fois (RM-05).
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->text('bio')->nullable();
            // « draft » ou « active » (RM-04, RM-06).
            $table->string('status', 16)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'position_id']);
        });

        // Historique des affectations : séparé de l'identité pour qu'un
        // changement de poste n'efface pas le parcours (RM-05).
        Schema::create('agent_position_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->constrained()->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();

            $table->index(['agent_id', 'started_at']);
        });

        // Au plus une affectation ouverte par agent : un seul poste à la fois.
        DB::statement('CREATE UNIQUE INDEX agent_open_assignment_unique ON agent_position_assignments (agent_id) WHERE ended_at IS NULL');

        // Fournisseurs de modèles IA connectés par l'entreprise (RM-10).
        // Aucune clé d'API n'est stockée sur le serveur.
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // Type de source : « anthropic », « openai », « openrouter »…
            $table->string('driver', 40);
            $table->string('base_url', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_provider_id')->constrained()->cascadeOnDelete();
            // Identifiant du modèle chez le fournisseur, par exemple « claude-opus-5-5 ».
            $table->string('identifier', 120);
            $table->string('label', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'ai_provider_id']);
        });

        DB::statement('CREATE UNIQUE INDEX ai_models_provider_identifier_unique ON ai_models (ai_provider_id, identifier) WHERE deleted_at IS NULL');

        // Modèles affectés à un agent, selon les tâches qu'il réalise (RM-10).
        Schema::create('agent_ai_model', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_model_id')->constrained()->cascadeOnDelete();
            // Usage prévu de ce modèle pour cet agent, en texte libre.
            $table->string('purpose', 160)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['agent_id', 'ai_model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_ai_model');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
        Schema::dropIfExists('agent_position_assignments');
        Schema::dropIfExists('agents');
    }
};
