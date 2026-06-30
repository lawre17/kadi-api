<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coin_transactions', function (Blueprint $table) {
            // Tournament buy-ins / refunds / prizes reference a tournament rather
            // than a single match. Idempotency is enforced at the application
            // level (player lifecycle + a prize-paid guard), so no unique index.
            $table->string('tournament_id')->nullable()->after('match_id');
            $table->foreign('tournament_id')->references('id')->on('tournaments')->nullOnDelete();
            $table->index('tournament_id');
        });
    }

    public function down(): void
    {
        Schema::table('coin_transactions', function (Blueprint $table) {
            $table->dropForeign(['tournament_id']);
            $table->dropIndex(['tournament_id']);
            $table->dropColumn('tournament_id');
        });
    }
};
