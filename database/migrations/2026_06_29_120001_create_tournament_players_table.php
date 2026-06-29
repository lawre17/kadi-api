<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournament_players', function (Blueprint $table) {
            $table->id();
            $table->string('tournament_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // registered → active (in play) → eliminated | champion.
            $table->enum('status', ['registered', 'active', 'eliminated', 'champion'])->default('registered');
            // League scoring; final placement once known.
            $table->integer('points')->default(0);
            $table->unsignedSmallInteger('place')->nullable();
            $table->unsignedTinyInteger('eliminated_round')->nullable();
            $table->timestamps();

            $table->foreign('tournament_id')->references('id')->on('tournaments')->cascadeOnDelete();
            $table->unique(['tournament_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournament_players');
    }
};
