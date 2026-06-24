<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coin_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('match_id')->nullable();
            $table->bigInteger('amount');
            $table->string('reason');
            $table->timestamps();

            $table->foreign('match_id')->references('id')->on('matches')->nullOnDelete();

            // Backstop against double-award: one transaction per (match, reason).
            $table->unique(['match_id', 'reason']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coin_transactions');
    }
};
