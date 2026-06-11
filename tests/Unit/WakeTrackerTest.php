<?php

use App\Services\WakeTracker;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Cache::flush();

    config()->set('game.wake_cooldown', 90);

    // Freeze the clock so a second ticking mid-test can't skew the countdown.
    $this->freezeTime();
});

it('treats an untouched environment as ready', function () {
    $tracker = app(WakeTracker::class);

    expect($tracker->isReady('env-1'))->toBeTrue()
        ->and($tracker->secondsUntilReady('env-1'))->toBe(0);
});

it('starts a cooldown when an environment is woken', function () {
    $tracker = app(WakeTracker::class);

    $tracker->markWoken('env-1');

    expect($tracker->isReady('env-1'))->toBeFalse()
        ->and($tracker->secondsUntilReady('env-1'))->toBe(90)
        ->and($tracker->isReady('env-2'))->toBeTrue();
});

it('becomes ready again once the cooldown elapses', function () {
    $tracker = app(WakeTracker::class);

    $tracker->markWoken('env-1');

    $this->travel(45)->seconds();
    expect($tracker->isReady('env-1'))->toBeFalse()
        ->and($tracker->secondsUntilReady('env-1'))->toBe(45);

    $this->travel(46)->seconds();
    expect($tracker->isReady('env-1'))->toBeTrue()
        ->and($tracker->secondsUntilReady('env-1'))->toBe(0);
});
