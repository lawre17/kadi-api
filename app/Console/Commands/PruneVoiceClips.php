<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Backstop cleanup for voice clips. The primary cleanup happens inline when a
 * game finishes (MatchPlayController@move deletes voice/{matchId}). This command
 * sweeps anything left behind by abandoned games — files or per-match dirs under
 * the public voice/ path whose last-modified time is older than 24h.
 *
 * Registered to run hourly in routes/console.php. Only runs if the Forge
 * scheduler cron is active; the delete-on-finish path is the main mechanism.
 */
class PruneVoiceClips extends Command
{
    protected $signature = 'voice:prune';

    protected $description = 'Delete voice clips older than 24h (backstop for abandoned games).';

    public function handle(): int
    {
        $disk = Storage::disk('public');
        $cutoff = now()->subDay()->getTimestamp();
        $removed = 0;

        // Delete stale loose files directly under voice/.
        foreach ($disk->files('voice') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $removed++;
            }
        }

        // Delete stale per-match directories (voice/{matchId}). A directory is
        // stale when its newest clip is older than the cutoff.
        foreach ($disk->directories('voice') as $dir) {
            $files = $disk->files($dir);
            $newest = 0;
            foreach ($files as $file) {
                $newest = max($newest, $disk->lastModified($file));
            }
            // Empty dir or all clips stale -> remove the whole folder.
            if ($newest === 0 || $newest < $cutoff) {
                $disk->deleteDirectory($dir);
                $removed++;
            }
        }

        $this->info("voice:prune removed {$removed} stale clip(s)/folder(s).");

        return self::SUCCESS;
    }
}
