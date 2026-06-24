<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_players', function (Blueprint $table) {
            $table->id();
            $table->string('match_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('seat_index')->nullable();
            $table->boolean('is_ai')->default(false);
            $table->enum('result', ['won', 'lost'])->nullable();
            $table->timestamps();

            $table->foreign('match_id')->references('id')->on('matches')->cascadeOnDelete();
            $table->unique(['match_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_players');
    }
};
