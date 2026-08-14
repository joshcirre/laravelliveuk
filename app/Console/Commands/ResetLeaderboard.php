<?php

namespace App\Console\Commands;

use App\Models\Attendee;
use App\Models\Round;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('leaderboard:reset')]
#[Description('Delete all rounds and attendees so the game starts fresh')]
class ResetLeaderboard extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        [$roundsDeleted, $attendeesDeleted] = DB::transaction(fn (): array => [
            Round::query()->delete(),
            Attendee::query()->delete(),
        ]);

        $this->info("Leaderboard reset — {$roundsDeleted} ".str('round')->plural($roundsDeleted).' deleted.');
        $this->info("Attendee list reset — {$attendeesDeleted} ".str('attendee')->plural($attendeesDeleted).' deleted.');

        return self::SUCCESS;
    }
}
