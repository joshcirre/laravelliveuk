<?php

use App\Services\LaravelCloud;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

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

function fakeEnvironmentsResponse(array $statusesById): array
{
    return [
        'data' => collect($statusesById)
            ->map(fn (string $status, string $id): array => [
                'id' => $id,
                'type' => 'environments',
                'attributes' => ['status' => $status],
            ])
            ->values()
            ->all(),
    ];
}

it('resolves the status of the configured environment', function () {
    Http::fake([
        'cloud.laravel.com/api/applications/app-1/environments' => Http::response(
            fakeEnvironmentsResponse(['env-other' => 'running', 'env-1' => 'hibernating'])
        ),
        'cloud.laravel.com/api/applications/app-2/environments' => Http::response(
            fakeEnvironmentsResponse(['env-2' => 'running'])
        ),
    ]);

    $statuses = app(LaravelCloud::class)->statuses();

    expect($statuses)->toBe([
        'env-1' => 'hibernating',
        'env-2' => 'running',
    ]);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
});

it('caches statuses between calls', function () {
    Http::fake([
        'cloud.laravel.com/*' => Http::response(
            fakeEnvironmentsResponse(['env-1' => 'hibernating', 'env-2' => 'running'])
        ),
    ]);

    $cloud = app(LaravelCloud::class);

    $cloud->statuses();
    $cloud->statuses();

    Http::assertSentCount(2);
});

it('bypasses the cache when fetching a fresh status', function () {
    Http::fake([
        'cloud.laravel.com/*' => Http::sequence()
            ->push(fakeEnvironmentsResponse(['env-1' => 'hibernating', 'env-2' => 'running']))
            ->push(fakeEnvironmentsResponse(['env-1' => 'hibernating', 'env-2' => 'running']))
            ->push(fakeEnvironmentsResponse(['env-1' => 'running'])),
    ]);

    $cloud = app(LaravelCloud::class);
    $target = config('game.targets.0');

    expect($cloud->statuses()['env-1'])->toBe('hibernating')
        ->and($cloud->freshStatus($target))->toBe('running')
        ->and($cloud->statuses()['env-1'])->toBe('running');

    Http::assertSentCount(3);
});

it('reports unknown without calling the API when status checks are disabled', function () {
    config()->set('game.cloud_status_enabled', false);

    // No Http::fake() here — preventStrayRequests() fails the test if any request fires.
    expect(app(LaravelCloud::class)->statuses())->toBe([
        'env-1' => 'unknown',
        'env-2' => 'unknown',
    ]);
});

it('reports unknown when the request fails', function () {
    Http::fake([
        'cloud.laravel.com/*' => Http::response([], 500),
    ]);

    expect(app(LaravelCloud::class)->statuses())->toBe([
        'env-1' => 'unknown',
        'env-2' => 'unknown',
    ]);
});

it('reports unknown when the environment is missing from the response', function () {
    Http::fake([
        'cloud.laravel.com/*' => Http::response(
            fakeEnvironmentsResponse(['env-other' => 'running'])
        ),
    ]);

    expect(app(LaravelCloud::class)->statuses())->toBe([
        'env-1' => 'unknown',
        'env-2' => 'unknown',
    ]);
});
