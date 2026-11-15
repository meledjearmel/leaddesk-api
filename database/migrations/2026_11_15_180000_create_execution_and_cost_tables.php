<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ordinateur du CEO sur lequel l'application est installée. Les agents
        // s'y exécutent et les fichiers de travail n'en sortent jamais.
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Identifiant généré par l'application à son installation.
            $table->uuid('installation_id');
            $table->string('name', 100);
            $table->string('platform', 20);
            // Dernier signe de vie : sert à dire si l'hôte est en ligne.
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        DB::statement('CREATE UNIQUE INDEX devices_installation_unique ON devices (company_id, installation_id) WHERE deleted_at IS NULL');

        // Exécution d'une tâche par un agent sur un ordinateur hôte.
        Schema::create('task_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained()->restrictOnDelete();
            // Ordinateur hôte : celui qui détient les fichiers de la tâche.
            $table->foreignId('device_id')->constrained()->restrictOnDelete();
            // « running », « paused », « paused_cost », « completed », « failed » ou « cancelled ».
            $table->string('status', 16)->default('running');
            $table->string('status_reason', 500)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'task_id']);
            $table->index(['company_id', 'device_id']);
        });

        // Une seule exécution ouverte par tâche.
        DB::statement('CREATE UNIQUE INDEX task_runs_open_unique ON task_runs (task_id) WHERE ended_at IS NULL');

        // Coût réel d'un appel de modèle ou d'outil, rattaché au travail (RM-35).
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_run_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('activity_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            // « model » ou « tool ».
            $table->string('kind', 16)->default('model');
            // Fournisseur et modèle qui ont réellement répondu.
            $table->string('provider', 40)->nullable();
            $table->string('model', 120)->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cache_read_tokens')->default(0);
            $table->unsignedBigInteger('cache_write_tokens')->default(0);
            // Coût réel en millionièmes de dollar ; vide si le tarif est inconnu.
            $table->unsignedBigInteger('cost_micros')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'occurred_at']);
            $table->index(['company_id', 'task_id']);
            $table->index(['company_id', 'project_id']);
            $table->index(['company_id', 'agent_id']);
        });

        // Limite de coût de l'entreprise, d'un projet ou d'une tâche (RM-36).
        Schema::create('cost_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // « company », « project » ou « task ».
            $table->string('scope', 16);
            // Identifiant du projet ou de la tâche ; 0 pour l'entreprise.
            $table->unsignedBigInteger('scope_id')->default(0);
            $table->unsignedBigInteger('limit_micros');
            $table->timestamps();

            $table->unique(['company_id', 'scope', 'scope_id']);
        });

        // Estimation de coût d'une tâche, toujours distincte du coût réel (RM-35).
        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedBigInteger('estimated_cost_micros')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('estimated_cost_micros');
        });
        Schema::dropIfExists('cost_limits');
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('task_runs');
        Schema::dropIfExists('devices');
    }
};
