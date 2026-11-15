<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plans d'aménagement du bureau (D-035) : versions successives, propositions
     * et plan en vigueur.
     */
    public function up(): void
    {
        Schema::create('office_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 16);
            $table->json('layout');
            // Poste qui a proposé le plan ; vide quand le CEO l'a dessiné.
            $table->foreignId('proposed_by_position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->foreignId('proposed_by_agent_id')->nullable()->constrained('agents')->nullOnDelete();
            // Plan en vigueur au moment de la proposition, pour la comparaison.
            $table->foreignId('based_on_id')->nullable()->constrained('office_plans')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->text('decision_comment')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'version']);
            $table->index(['company_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX office_plans_one_accepted_per_company ON office_plans (company_id) WHERE status = 'accepted' AND deleted_at IS NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('office_plans');
    }
};
