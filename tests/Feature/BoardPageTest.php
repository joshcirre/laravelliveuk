<?php

use App\Models\Round;
use Livewire\Livewire;

it('renders the board without the guess form', function () {
    $this->get('/board')
        ->assertOk()
        ->assertSee('Scan to play')
        ->assertDontSee('Wake an app');
});

it('shows the closest guesses on the big leaderboard', function () {
    Round::factory()->create(['player_name' => 'Closest', 'delta_ms' => 5]);
    Round::factory()->create(['player_name' => 'Furthest', 'delta_ms' => 5000]);

    Livewire::test('pages::board')
        ->assertSeeInOrder(['Closest', 'Furthest']);
});

it('only feeds fresh rounds into the recent guesses', function () {
    $stale = Round::factory()->create(['created_at' => now()->subMinutes(5)]);
    $fresh = Round::factory()->create(['created_at' => now()]);

    Livewire::test('pages::board')
        ->assertSeeHtml("recent-guess-{$fresh->id}")
        ->assertDontSeeHtml("recent-guess-{$stale->id}");
});
