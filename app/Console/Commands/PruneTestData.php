<?php

namespace App\Console\Commands;

use App\Models\CoinTransaction;
use App\Models\GameMatch;
use App\Models\MatchPlayer;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remove throwaway test accounts (emails ending in @k.test / @kadi.test) and all
 * their data, in FK-safe order, inside a transaction. Idempotent and safe to run
 * repeatedly. Real players have normal emails and are never touched.
 *
 * Use --dry-run to print counts of what WOULD be deleted without deleting.
 */
class PruneTestData extends Command
{
    protected $signature = 'db:prune-test-data {--dry-run : Report counts without deleting}';

    protected $description = 'Delete test accounts (@k.test / @kadi.test) and their matches/players/transactions/tokens.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Collect the throwaway user ids.
        $userIds = User::where('email', 'like', '%@k.test')
            ->orWhere('email', 'like', '%@kadi.test')
            ->pluck('id')
            ->all();

        if (empty($userIds)) {
            $this->info('No test users found. Nothing to prune.');

            return self::SUCCESS;
        }

        // Matches hosted by these users (matches PK is a string uuid).
        $matchIds = GameMatch::whereIn('host_user_id', $userIds)
            ->pluck('id')
            ->all();

        // Query builders for each deletable set. Built up front so dry-run and
        // the real delete report identical counts.
        $tokens = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds);

        $coinTx = CoinTransaction::query()
            ->whereIn('user_id', $userIds)
            ->when(! empty($matchIds), fn ($q) => $q->orWhereIn('match_id', $matchIds));

        $players = MatchPlayer::query()
            ->whereIn('user_id', $userIds)
            ->when(! empty($matchIds), fn ($q) => $q->orWhereIn('match_id', $matchIds));

        $matches = GameMatch::whereIn('host_user_id', $userIds);

        $users = User::whereIn('id', $userIds);

        $counts = [
            'personal_access_tokens' => $tokens->count(),
            'coin_transactions' => $coinTx->count(),
            'match_players' => $players->count(),
            'matches' => $matches->count(),
            'users' => $users->count(),
        ];

        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be deleted.');
            $this->summary($counts, $userIds, $matchIds);

            return self::SUCCESS;
        }

        DB::transaction(function () use ($userIds, $matchIds) {
            // FK-safe order: leaves first, then matches, then users.
            DB::table('personal_access_tokens')
                ->where('tokenable_type', User::class)
                ->whereIn('tokenable_id', $userIds)
                ->delete();

            CoinTransaction::query()
                ->whereIn('user_id', $userIds)
                ->when(! empty($matchIds), fn ($q) => $q->orWhereIn('match_id', $matchIds))
                ->delete();

            MatchPlayer::query()
                ->whereIn('user_id', $userIds)
                ->when(! empty($matchIds), fn ($q) => $q->orWhereIn('match_id', $matchIds))
                ->delete();

            GameMatch::whereIn('host_user_id', $userIds)->delete();

            User::whereIn('id', $userIds)->delete();
        });

        $this->info('Deleted test data.');
        $this->summary($counts, $userIds, $matchIds);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<int, int>  $userIds
     * @param  array<int, string>  $matchIds
     */
    private function summary(array $counts, array $userIds, array $matchIds): void
    {
        $this->line('Test users: '.count($userIds).' | hosted matches: '.count($matchIds));
        foreach ($counts as $table => $n) {
            $this->line(sprintf('  %-24s %d', $table, $n));
        }
    }
}
