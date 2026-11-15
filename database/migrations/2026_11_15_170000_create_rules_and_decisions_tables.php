<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Règle de l'entreprise ou d'une unité. Elle s'applique à son niveau et
        // à tous les niveaux inférieurs (RM-24).
        Schema::create('rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Niveau de la règle : vide pour toute l'entreprise, sinon une unité.
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            // Règle héritée que celle-ci précise ou renforce.
            $table->foreignId('parent_rule_id')->nullable()->constrained('rules')->restrictOnDelete();
            // « precise » ou « reinforce » ; il n'existe pas de relation « assouplir ».
            $table->string('relation', 16)->nullable();
            $table->string('title', 200);
            $table->text('body');
            $table->unsignedInteger('version')->default(1);
            // Date d'entrée en vigueur de la version actuelle : la règle vaut
            // pour les activités créées à partir de cette date (RM-27).
            $table->timestamp('effective_at');
            // Vrai quand le CEO a étendu la règle aux activités déjà en cours.
            $table->boolean('extended_to_running')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'unit_id']);
        });

        // File des décisions du CEO : un seul mécanisme pour les règles, puis
        // les coûts, les accès, les Skills et les embauches.
        Schema::create('decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Nature de la demande, par exemple « rule_change ».
            $table->string('kind', 32);
            $table->string('title', 200);
            // « pending », « needs_info », « approved » ou « rejected ».
            $table->string('status', 16)->default('pending');
            // Agent qui a présenté la demande ; vide si elle vient du système.
            $table->foreignId('requested_by_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            $table->nullableMorphs('subject');
            // Contenu de la demande, propre à sa nature.
            $table->json('payload')->nullable();
            // Réponse du CEO.
            $table->text('decision_note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('decisions');
        Schema::dropIfExists('rules');
    }
};
