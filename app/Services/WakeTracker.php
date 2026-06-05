<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class WakeTracker
{
    /**
     * Record that the given environment was just woken by a round.
     */
    public function markWoken(string $environmentId): void
    {
        Cache::forever($this->cacheKey($environmentId), now()->getTimestamp());
    }

    /**
     * Determine whether the given environment has cooled down enough to play.
     */
    public function isReady(string $environmentId): bool
    {
        return $this->secondsUntilReady($environmentId) === 0;
    }

    /**
     * Get the seconds remaining until the given environment is playable again.
     */
    public function secondsUntilReady(string $environmentId): int
    {
        $lastWokenAt = Cache::get($this->cacheKey($environmentId));

        if ($lastWokenAt === null) {
            return 0;
        }

        $elapsed = now()->getTimestamp() - $lastWokenAt;

        return max(0, (int) config('game.wake_cooldown') - $elapsed);
    }

    /**
     * Get the cache key for the given environment's last wake timestamp.
     */
    protected function cacheKey(string $environmentId): string
    {
        return "scale-to-zero:last-woken:{$environmentId}";
    }
}
