<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_tables', function (Blueprint $table) {
            $table->id();
            $table->string('tournament_id');
            $table->unsignedTinyInteger('round');
            // The engine match backing this table (null until seated).
            $table->string('match_id')->nullable()->index();
            $table->enum('status', ['pending', 'running', 'finished'])->default('pending');
            // The user ids seated at this table.
            $table->json('player_user_ids');
            $table->foreignId('winner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('tournament_id')->references('id')->on('tournaments')->cascadeOnDelete();
            $table->index(['tournament_id', 'round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_tables');
    }
};
