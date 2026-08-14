<?php

use App\Models\Attendee;
use App\Models\Round;

it('deletes every round and attendee so the game starts fresh', function () {
    $player = Attendee::factory()->create();

    Round::factory()->count(3)->for($player)->create();
    Attendee::factory()->create();

    $this->artisan('leaderboard:reset')
        ->expectsOutputToContain('3 rounds deleted')
        ->expectsOutputToContain('2 attendees deleted')
        ->assertSuccessful();

    expect(Round::count())->toBe(0)
        ->and(Attendee::count())->toBe(0);
});

it('runs cleanly when there are no rounds to delete', function () {
    $this->artisan('leaderboard:reset')
        ->expectsOutputToContain('0 rounds deleted')
        ->expectsOutputToContain('0 attendees deleted')
        ->assertSuccessful();
});
