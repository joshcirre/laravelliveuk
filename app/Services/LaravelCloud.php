<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class LaravelCloud
{
    /**
     * The status reported when an environment cannot be resolved.
     */
    public const string STATUS_UNKNOWN = 'unknown';

    /**
     * The status an environment must report for a round to begin.
     */
    public const string STATUS_HIBERNATING = 'hibernating';

    /**
     * Get the cached status of every configured target, keyed by environment ID.
     *
     * @return array<string, string>
     */
    public function statuses(): array
    {
        return collect(config('game.targets'))
            ->mapWithKeys(fn (array $target): array => [
                $target['environment_id'] => Cache::remember(
                    $this->cacheKey($target),
                    config('game.status_cache_ttl'),
                    fn (): string => $this->fetchStatus($target),
                ),
            ])
            ->all();
    }

    /**
     * Get the status of the given target directly from the API, bypassing the cache.
     *
     * @param  array{name: string, url: string, application_id: string, environment_id: string}  $target
     */
    public function freshStatus(array $target): string
    {
        $status = $this->fetchStatus($target);

        Cache::put($this->cacheKey($target), $status, config('game.status_cache_ttl'));

        return $status;
    }

    /**
     * Fetch the environment status for the given target from the Laravel Cloud API.
     *
     * @param  array{name: string, url: string, application_id: string, environment_id: string}  $target
     */
    protected function fetchStatus(array $target): string
    {
        try {
            $response = Http::withToken(config('game.cloud_api_token'))
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(5)
                ->get(config('game.cloud_base_url')."/api/applications/{$target['application_id']}/environments");
        } catch (ConnectionException) {
            return static::STATUS_UNKNOWN;
        }

        if ($response->failed()) {
            return static::STATUS_UNKNOWN;
        }

        return collect($response->json('data'))
            ->first(fn (array $environment): bool => (string) ($environment['id'] ?? '') === $target['environment_id'])['attributes']['status']
            ?? static::STATUS_UNKNOWN;
    }

    /**
     * Get the cache key for the given target's status.
     *
     * @param  array{environment_id: string}  $target
     */
    protected function cacheKey(array $target): string
    {
        return "cloud-status:{$target['environment_id']}";
    }
}
