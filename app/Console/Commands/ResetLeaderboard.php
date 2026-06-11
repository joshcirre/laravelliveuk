<?php

namespace App\Console\Commands;

use App\Models\Round;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leaderboard:reset')]
#[Description('Delete every recorded round so the leaderboard starts fresh')]
class ResetLeaderboard extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $deleted = Round::query()->delete();

        $this->info("Leaderboard reset — {$deleted} ".str('round')->plural($deleted).' deleted.');

        return self::SUCCESS;
    }
}
