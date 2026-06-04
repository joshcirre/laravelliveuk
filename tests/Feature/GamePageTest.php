<?php

use App\Models\Round;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Cache::flush();

    Http::preventStrayRequests();

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

it('renders the game page with target statuses', function () {
    fakeStatuses('hibernating');

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Guess the Cold Start')
        ->assertSee('App One')
        ->assertSee('App Two')
        ->assertSee('hibernating')
        ->assertSee('running');
});

it('starts a round when the target is hibernating', function () {
    fakeStatuses('hibernating');

    Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('selectTarget', 'env-1')
        ->call('startRound')
        ->assertHasNoErrors()
        ->assertSet('roundActive', true)
        ->assertDispatched('round-started');
});

it('blocks a round when the target is no longer hibernating', function () {
    fakeStatuses('running');

    Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('selectTarget', 'env-1')
        ->call('startRound')
        ->assertHasErrors('selectedEnvId')
        ->assertSet('roundActive', false)
        ->assertNotDispatched('round-started');

    expect(Round::count())->toBe(0);
});

it('validates the player name and guess before starting', function () {
    fakeStatuses('hibernating');

    Livewire::test('pages::game')
        ->call('startRound')
        ->assertHasErrors(['playerName', 'guessMs', 'selectedEnvId'])
        ->assertNotDispatched('round-started');
});

it('records a finished round with the computed delta', function () {
    fakeStatuses('hibernating');

    $component = Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('selectTarget', 'env-1')
        ->call('startRound');

    $token = $component->get('roundToken');

    $component->call('recordResult', $token, 1500)
        ->assertSet('roundActive', false)
        ->assertSet('roundToken', null)
        ->assertSee('Off by 300 ms');

    $round = Round::sole();

    expect($round)
        ->player_name->toBe('Josh')
        ->target_name->toBe('App One')
        ->target_url->toBe('https://app-one.test')
        ->guess_ms->toBe(1200)
        ->actual_ms->toBe(1500)
        ->delta_ms->toBe(300);
});

it('ignores results with a stale token', function () {
    fakeStatuses('hibernating');

    Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('selectTarget', 'env-1')
        ->call('startRound')
        ->call('recordResult', 1, 1500)
        ->assertSet('roundActive', true);

    expect(Round::count())->toBe(0);
});

it('ignores results when no round is active', function () {
    fakeStatuses('hibernating');

    Livewire::test('pages::game')
        ->call('recordResult', 123, 1500);

    expect(Round::count())->toBe(0);
});

it('voids a round on timeout without persisting anything', function () {
    fakeStatuses('hibernating');

    $component = Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('selectTarget', 'env-1')
        ->call('startRound');

    $token = $component->get('roundToken');

    $component->call('voidRound', $token)
        ->assertSet('roundActive', false)
        ->assertSee('Round voided');

    expect(Round::count())->toBe(0);
});

it('shows the closest guesses on the leaderboard', function () {
    fakeStatuses('hibernating');

    Round::factory()->create(['player_name' => 'Closest', 'delta_ms' => 5]);
    Round::factory()->create(['player_name' => 'Middle', 'delta_ms' => 500]);
    Round::factory()->create(['player_name' => 'Furthest', 'delta_ms' => 5000]);

    Livewire::test('pages::game')
        ->assertSeeInOrder(['Closest', 'Middle', 'Furthest']);
});

it('cannot select a target while a round is active', function () {
    fakeStatuses('hibernating', 'hibernating');

    Livewire::test('pages::game')
        ->set('playerName', 'Josh')
        ->set('guessMs', 1200)
        ->call('selectTarget', 'env-1')
        ->call('startRound')
        ->call('selectTarget', 'env-2')
        ->assertSet('selectedEnvId', 'env-1');
});
