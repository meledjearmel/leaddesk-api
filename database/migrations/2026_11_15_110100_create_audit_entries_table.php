<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // RM-34 : consultations, actions et changements sont enregistrés.
        // Le journal ne se modifie pas et ne se supprime pas depuis l'application.
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Auteur : « ceo » (humain), « agent » ou « system ».
            $table->string('actor_type', 16);
            $table->unsignedBigInteger('actor_id')->nullable();
            // Nature : « consultation », « action » ou « change ».
            $table->string('kind', 16);
            // Événement lisible, par exemple « unit.created » ou « agent.position_changed ».
            $table->string('event', 64);
            $table->nullableMorphs('subject');
            $table->json('properties')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_entries');
    }
};
