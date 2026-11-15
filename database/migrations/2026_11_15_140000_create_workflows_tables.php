<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Parcours configurable. Un workflow sert soit aux activités, soit aux
        // tâches : les deux suivent des workflows distincts (RM-18).
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            // « activity » ou « task ».
            $table->string('scope', 16);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'scope']);
        });

        // Étapes du parcours, dans l'ordre. Le libellé est libre ; la catégorie
        // dit au logiciel ce que l'étape signifie.
        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // « todo », « in_progress », « review », « done » ou « cancelled ».
            $table->string('category', 16);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workflow_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflows');
    }
};
