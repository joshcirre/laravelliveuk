<?php

use App\Models\Round;

it('deletes every round so the leaderboard starts fresh', function () {
    Round::factory()->count(3)->create();

    $this->artisan('leaderboard:reset')
        ->expectsOutputToContain('3 rounds deleted')
        ->assertSuccessful();

    expect(Round::count())->toBe(0);
});

it('runs cleanly when there are no rounds to delete', function () {
    $this->artisan('leaderboard:reset')
        ->expectsOutputToContain('0 rounds deleted')
        ->assertSuccessful();
});
