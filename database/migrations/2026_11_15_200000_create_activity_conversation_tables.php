<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Conversation de travail d'une activité (panneau « Conversation »).
        Schema::create('activity_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            // Vide : message du CEO.
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->text('body');
            // Références à des fichiers restés sur l'ordinateur hôte (jamais leur contenu).
            $table->json('file_references')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['activity_id', 'id']);
        });

        // Contexte partagé d'une activité (panneau « Contexte partagé »).
        Schema::create('activity_context_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 160);
            $table->text('content');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['activity_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_context_items');
        Schema::dropIfExists('activity_messages');
    }
};
