<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tournaments', function (Blueprint $table) {
            // String/uuid PK so ids aren't enumerable (used in the realtime
            // channel name tournament.{id}).
            $table->string('id')->primary();
            // Short human-friendly join code.
            $table->string('code', 8)->unique();
            $table->enum('format', ['bracket', 'league', 'survival'])->default('bracket');
            $table->enum('status', ['registering', 'running', 'finished'])->default('registering');
            $table->foreignId('host_user_id')->constrained('users')->cascadeOnDelete();
            // Coins each entrant pays in; pool is paid to the winner. 0 = free.
            $table->unsignedInteger('buy_in')->default(0);
            $table->unsignedInteger('prize_pool')->default(0);
            // Max seats per table (2..6); a table fills with available players.
            $table->unsignedTinyInteger('table_size')->default(4);
            // League only: how many rounds everyone plays.
            $table->unsignedTinyInteger('rounds_total')->nullable();
            $table->unsignedTinyInteger('current_round')->default(0);
            $table->foreignId('winner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tournaments');
    }
};
