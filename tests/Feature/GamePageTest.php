<?php

use App\Models\Round;
use App\Services\WakeTracker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();

    Http::preventStrayRequests();

    config()->set('game.cloud_status_enabled', true);
    config()->set('game.cloud_api_token', 'test-token');
    config()->set('game.targets', [
        [
            'name' => 'App One',
            'url' => 'https://app-one.test',
            'application_id' => 'app-1',
            'environment_id' => 'env-1',
        ],
        [
            'name' => 'App Two',
            'url' => 'https://app-two.test',
            'application_id' => 'app-2',
            'environment_id' => 'env-2',
        ],
    ]);
});

function fakeStatuses(string $appOneStatus, string $appTwoStatus = 'running'): void
{
    Http::fake([
        'cloud.laravel.com/api/applications/app-1/environments' => Http::response([
            'data' => [['id' => 'env-1', 'type' => 'environments', 'attributes' => ['status' => $appOneStatus]]],
        ]),
        'cloud.laravel.com/api/applications/app-2/environments' => Http::response([
            'data' => [['id' => 'env-2', 'type' => 'environments', 'attributes' => ['status' => $appTwoStatus]]],
        ]),
    ]);
}

it('renders the game page with app readiness', function () {
    fakeStatuses('running');

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Guess the')
        ->assertSee('cold start')
        ->assertSee('ready to wake');
});

it('skips the Cloud API entirely when status checks are disabled', function () {
    config()->set('game.cloud_status_enabled', false);

    // No Http::fake() here — preventStrayRequests() fails the test if any request fires.
    Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('startRound')
        ->assertHasNoErrors()
        ->assertSet('activeEnvId', 'env-1')
        ->assertDispatched('round-started');
});

it('starts a round with the first available app', function () {
    fakeStatuses('running');

    Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('startRound')
        ->assertHasNoErrors()
        ->assertSet('roundActive', true)
        ->assertSet('activeEnvId', 'env-1')
        ->assertDispatched('round-started');
});

it('auto-selects the next app when the first is cooling down', function () {
    fakeStatuses('running');

    app(WakeTracker::class)->markWoken('env-1');

    Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('startRound')
        ->assertHasNoErrors()
        ->assertSet('activeEnvId', 'env-2')
        ->assertDispatched('round-started');
});

it('auto-selects around an app that is deploying or stopped', function (string $status) {
    fakeStatuses($status);

    Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('startRound')
        ->assertHasNoErrors()
        ->assertSet('activeEnvId', 'env-2')
        ->assertDispatched('round-started');
})->with(['deploying', 'stopped']);

it('blocks a round when every app is cooling down', function () {
    fakeStatuses('running');

    app(WakeTracker::class)->markWoken('env-1');
    app(WakeTracker::class)->markWoken('env-2');

    Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('startRound')
        ->assertHasErrors('round')
        ->assertSet('roundActive', false)
        ->assertNotDispatched('round-started');

    expect(Round::count())->toBe(0);
});

it('blocks a round when no app is playable', function () {
    fakeStatuses('deploying', 'stopped');

    Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('startRound')
        ->assertHasErrors('round')
        ->assertNotDispatched('round-started');

    expect(Round::count())->toBe(0);
});

it('puts the chosen app on cooldown when a round starts', function () {
    fakeStatuses('running');

    Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('startRound')
        ->assertHasNoErrors();

    expect(app(WakeTracker::class)->isReady('env-1'))->toBeFalse();
});

it('validates the player name and guess before starting', function () {
    fakeStatuses('running');

    Livewire::test('pages::game')
        ->call('startRound')
        ->assertHasErrors(['playerName', 'guessMs'])
        ->assertNotDispatched('round-started');
});

it('ignores a second start while a round is active', function () {
    fakeStatuses('running');

    $component = Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('startRound');

    $token = $component->get('roundToken');

    $component->call('startRound')
        ->assertSet('roundToken', $token)
        ->assertSet('activeEnvId', 'env-1');
});

it('records a finished round with the computed delta', function () {
    fakeStatuses('running');

    $component = Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('startRound');

    $token = $component->get('roundToken');

    $component->call('recordResult', $token, 1500, 95)
        ->assertSet('roundActive', false)
        ->assertSet('roundToken', null)
        ->assertSee('scaled from zero in 1,500 ms')
        ->assertSee('off by 300 ms')
        ->assertSee('Includes ~95 ms of round-trip latency');

    $round = Round::sole();

    expect($round)
        ->player_name->toBe('Josh')
        ->target_name->toBe('App One')
        ->target_url->toBe('https://app-one.test')
        ->guess_ms->toBe(1200)
        ->actual_ms->toBe(1500)
        ->latency_ms->toBe(95)
        ->delta_ms->toBe(300);
});

it('records a round without latency when the measurement fails', function () {
    fakeStatuses('running');

    $component = Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('startRound');

    $component->call('recordResult', $component->get('roundToken'), 1500)
        ->assertDontSee('round-trip latency');

    expect(Round::sole()->latency_ms)->toBeNull();
});

it('caps a reported latency at the actual wake time', function () {
    fakeStatuses('running');

    $component = Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('startRound');

    $component->call('recordResult', $component->get('roundToken'), 1500, 9999);

    expect(Round::sole()->latency_ms)->toBe(1500);
});

it('does not reveal which app was used in the result', function () {
    fakeStatuses('running');

    $component = Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('startRound');

    $component->call('recordResult', $component->get('roundToken'), 1500)
        ->assertDontSee('App One');
});

it('ignores results with a stale token', function () {
    fakeStatuses('running');

    Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('startRound')
        ->call('recordResult', 1, 1500)
        ->assertSet('roundActive', true);

    expect(Round::count())->toBe(0);
});

it('ignores results when no round is active', function () {
    fakeStatuses('running');

    Livewire::test('pages::game')
        ->call('recordResult', 123, 1500);

    expect(Round::count())->toBe(0);
});

it('voids a round on timeout without persisting anything', function () {
    fakeStatuses('running');

    $component = Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('startRound');

    $token = $component->get('roundToken');

    $component->call('voidRound', $token)
        ->assertSet('roundActive', false)
        ->assertSee('Round voided');

    expect(Round::count())->toBe(0);
});

it('shows the closest guesses on the leaderboard', function () {
    fakeStatuses('running');

    Round::factory()->create(['player_name' => 'Closest', 'delta_ms' => 5]);
    Round::factory()->create(['player_name' => 'Middle', 'delta_ms' => 500]);
    Round::factory()->create(['player_name' => 'Furthest', 'delta_ms' => 5000]);

    Livewire::test('pages::game')
        ->assertSeeInOrder(['Closest', 'Middle', 'Furthest']);
});
