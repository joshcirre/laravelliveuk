<?php

use App\Models\Round;
use App\Services\LaravelCloud;
use App\Services\WakeTracker;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Guess the Scale to Zero')] class extends Component
{
    public string $playerName = '';

    public ?int $guessMs = null;

    public bool $roundActive = false;

    public ?int $roundToken = null;

    public ?string $activeEnvId = null;

    /** @var array{player_name: string, target_name: string, guess_ms: int, actual_ms: int, cold_ms: int|null, latency_ms: int|null, delta_ms: int}|null */
    public ?array $lastResult = null;

    public ?string $notice = null;

    /**
     * Get the validation rules for starting a round.
     *
     * @return array<string, array<int, string>>
     */
    protected function rules(): array
    {
        return [
            'playerName' => ['required', 'string', 'max:50'],
            'guessMs' => ['required', 'integer', 'min:1', 'max:120000'],
        ];
    }

    /**
     * Get every configured target merged with its Cloud status and cooldown state.
     *
     * The Cloud API does not reliably report scale-to-zero sleep, so playability
     * is driven by the wake cooldown that the game tracks itself. The API status
     * is only used to block targets that are deploying or stopped.
     */
    #[Computed]
    public function targets(): Collection
    {
        $statuses = app(LaravelCloud::class)->statuses();
        $tracker = app(WakeTracker::class);

        return collect(config('game.targets'))->map(function (array $target) use ($statuses, $tracker): array {
            $status = $statuses[$target['environment_id']] ?? LaravelCloud::STATUS_UNKNOWN;
            $cooldown = $tracker->secondsUntilReady($target['environment_id']);

            return [
                ...$target,
                'status' => $status,
                'cooldown' => $cooldown,
                'playable' => $cooldown === 0 && ! in_array($status, ['deploying', 'stopped']),
            ];
        });
    }

    #[Computed]
    public function readyCount(): int
    {
        return $this->targets->where('playable', true)->count();
    }

    /**
     * Get the seconds until the next cooling target becomes playable, if any.
     */
    #[Computed]
    public function nextReadySeconds(): ?int
    {
        return $this->targets
            ->reject(fn (array $target): bool => in_array($target['status'], ['deploying', 'stopped']))
            ->min('cooldown');
    }

    #[Computed]
    public function leaderboard(): Collection
    {
        return Round::closest()->limit(10)->get();
    }

    public function startRound(): void
    {
        if ($this->roundActive) {
            return;
        }

        $this->validate();

        $target = $this->targets->firstWhere('playable', true);

        if ($target === null) {
            $seconds = $this->nextReadySeconds;

            $this->addError('round', $seconds !== null && $seconds > 0
                ? "Every app is still recovering — the next one is ready in about {$seconds} seconds."
                : 'No apps are available right now — try again in a moment.');

            return;
        }

        app(WakeTracker::class)->markWoken($target['environment_id']);

        $this->resetErrorBag('round');
        $this->lastResult = null;
        $this->notice = null;
        $this->activeEnvId = $target['environment_id'];
        $this->roundActive = true;
        $this->roundToken = random_int(100000, 99999999);

        $this->dispatch(
            'round-started',
            token: $this->roundToken,
            url: $target['url'],
            timeoutMs: config('game.round_timeout_ms'),
        );
    }

    /**
     * Record a finished round.
     *
     * The browser reports the cold first-response time and a warm baseline
     * measured straight afterwards. Subtracting the baseline strips out the
     * network round-trip, Cloud edge hop, and the app's steady-state request
     * cost, leaving the wake-from-sleep time itself — the number players guess.
     */
    public function recordResult(int $token, int $coldMs, ?int $latencyMs = null): void
    {
        if (! $this->roundActive || $token !== $this->roundToken) {
            return;
        }

        $target = $this->findTarget($this->activeEnvId);

        $baselineMs = $latencyMs !== null ? min(max($latencyMs, 0), $coldMs) : null;
        $wakeMs = $baselineMs !== null ? $coldMs - $baselineMs : $coldMs;

        $round = Round::create([
            'player_name' => $this->playerName,
            'target_name' => $target['name'],
            'target_url' => $target['url'],
            'guess_ms' => $this->guessMs,
            'actual_ms' => $wakeMs,
            'cold_ms' => $coldMs,
            'latency_ms' => $baselineMs,
            'delta_ms' => abs($this->guessMs - $wakeMs),
        ]);

        $this->lastResult = $round->only(['player_name', 'target_name', 'guess_ms', 'actual_ms', 'cold_ms', 'latency_ms', 'delta_ms']);
        $this->finishRound();

        $this->dispatch('round-complete');
    }

    /**
     * Clear the result and form so the next person starts from a blank slate.
     */
    public function newGuess(): void
    {
        $this->reset(['lastResult', 'playerName', 'guessMs', 'notice']);
        $this->resetErrorBag();
    }

    public function voidRound(int $token): void
    {
        if (! $this->roundActive || $token !== $this->roundToken) {
            return;
        }

        $this->notice = 'Round voided — the app never finished loading.';
        $this->finishRound();
    }

    protected function finishRound(): void
    {
        $this->roundActive = false;
        $this->roundToken = null;
        $this->activeEnvId = null;
        $this->guessMs = null;
    }

    /**
     * Find the configured target for the given environment ID.
     *
     * @return array{name: string, url: string, application_id: string, environment_id: string}|null
     */
    protected function findTarget(?string $environmentId): ?array
    {
        return collect(config('game.targets'))->firstWhere('environment_id', $environmentId);
    }
};
?>

<div class="relative isolate h-dvh overflow-hidden">
    {{-- Stage: full-bleed dot grid, the waking app, and the stopwatch --}}
    <div wire:ignore wire:key="wake-stage" class="absolute inset-0">
        <div class="absolute inset-0 bg-[radial-gradient(var(--color-slate-200)_1px,transparent_1px)] [background-size:8px_8px]"></div>

        <iframe
            id="wake-frame"
            title="Scale to zero preview"
            class="absolute inset-0 size-full bg-white opacity-0 transition-opacity duration-300"
        ></iframe>

        <div class="pointer-events-none absolute top-5 left-1/2 z-10 -translate-x-1/2 md:top-8">
            <div id="wake-stopwatch" class="rounded-full bg-white px-6 py-2.5 font-mono text-4xl font-semibold tracking-tight tabular-nums shadow-md ring-1 ring-black/5 md:px-8 md:py-3 md:text-5xl xl:text-6xl">0 ms</div>
        </div>

        <div class="pointer-events-none absolute bottom-6 left-6 z-10 max-lg:hidden">
            <p class="rounded-full bg-white px-3 py-1.5 font-mono text-xs tracking-wide text-slate-500 uppercase shadow-xs ring-1 ring-black/5">Powered by Laravel Cloud</p>
        </div>
    </div>

    {{-- Start / result overlay: fades away while the run is live --}}
    <div class="absolute inset-0 z-20 grid place-items-center p-4 pt-24 transition-opacity duration-500 sm:p-6 md:pt-32 lg:pr-[23rem] xl:pr-[28rem] {{ $roundActive ? 'pointer-events-none opacity-0' : '' }}">
        <div class="w-full max-w-md rounded-3xl bg-white/60 p-1.5 shadow-2xl ring-1 ring-black/5 backdrop-blur-sm sm:max-w-lg xl:max-w-2xl">
            <div class="flex flex-col gap-5 rounded-2xl bg-white p-6 shadow-xs ring-1 ring-black/5 md:gap-6 md:p-8 xl:p-10">
                <div class="flex flex-col gap-2.5">
                    <h1 class="text-2xl font-semibold tracking-tight text-balance md:text-3xl xl:text-5xl">Guess the <span class="font-serif italic">scale to zero</span> 💤</h1>
                    @unless ($lastResult)
                        <p class="text-sm text-pretty text-slate-500 md:text-base xl:text-lg">A Laravel Cloud app has scaled to zero. Guess how many milliseconds it takes to wake — we subtract network and steady-state latency so it's purely the cold start. Closest guess wins.</p>
                    @endunless
                </div>

                @if ($notice)
                    <div wire:key="round-notice" class="rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-700 ring-1 ring-amber-600/20 md:text-base">{{ $notice }}</div>
                @endif

                @if ($lastResult)
                    @php
                        $verdict = match (true) {
                            $lastResult['delta_ms'] <= 50 => '🎯 Unbelievable!',
                            $lastResult['delta_ms'] <= 250 => '🔥 So close!',
                            $lastResult['delta_ms'] <= 1000 => '👏 Not bad!',
                            default => '🐢 Better luck next time!',
                        };
                    @endphp
                    <div wire:key="round-result" class="flex flex-col gap-2 rounded-xl bg-cloud/4 px-4 py-3.5 ring-1 ring-cloud/15 md:px-5 md:py-4">
                        <p class="text-sm font-medium text-cloud md:text-base">{{ $verdict }}</p>
                        <p class="font-semibold tracking-tight text-balance md:text-lg xl:text-xl">Laravel Cloud woke from sleep in {{ number_format($lastResult['actual_ms']) }} ms</p>
                        <p class="text-sm text-slate-500 md:text-base">{{ $lastResult['player_name'] }} guessed {{ number_format($lastResult['guess_ms']) }} ms — off by {{ number_format($lastResult['delta_ms']) }} ms.</p>
                        @if (($lastResult['cold_ms'] ?? null) !== null && ($lastResult['latency_ms'] ?? null) !== null)
                            <p class="text-sm text-slate-400">Full cold response was {{ number_format($lastResult['cold_ms']) }} ms — we stripped ~{{ number_format($lastResult['latency_ms']) }} ms of round-trip and steady-state server time so this is just the wake.</p>
                        @endif
                    </div>

                    <button
                        type="button"
                        wire:click="newGuess"
                        x-on:click="window.cancelGuessReset?.(); window.resetWakeStage?.()"
                        class="h-11 rounded-md bg-cloud px-4 text-sm font-medium text-white transition-colors hover:bg-cloud/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cloud md:h-12 md:text-base xl:h-14 xl:text-lg"
                    >
                        New guess
                    </button>
                    <p class="text-center text-xs text-slate-400 md:text-sm">Resetting for the next player in 10 seconds…</p>
                @else
                    <form wire:submit="startRound" class="grid gap-4 xl:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <label for="player-name" class="text-sm font-medium text-slate-700 xl:text-base">Your name</label>
                            <input
                                id="player-name"
                                name="player_name"
                                type="text"
                                wire:model="playerName"
                                placeholder="Taylor"
                                class="h-11 w-full rounded-md border border-black/10 bg-white px-3 text-sm placeholder:text-slate-400 hover:border-black/20 focus:border-cloud focus:ring-[3px] focus:ring-cloud/15 focus:outline-hidden max-sm:text-base md:h-12 md:text-base xl:h-14 xl:px-4 xl:text-lg"
                            />
                            @error('playerName')
                                <p class="text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label for="guess-ms" class="text-sm font-medium text-slate-700 xl:text-base">Your guess (ms)</label>
                            <input
                                id="guess-ms"
                                name="guess_ms"
                                type="number"
                                min="1"
                                wire:model="guessMs"
                                placeholder="413"
                                class="h-11 w-full rounded-md border border-black/10 bg-white px-3 font-mono text-sm tabular-nums placeholder:text-slate-400 hover:border-black/20 focus:border-cloud focus:ring-[3px] focus:ring-cloud/15 focus:outline-hidden max-sm:text-base md:h-12 md:text-base xl:h-14 xl:px-4 xl:text-lg"
                            />
                            @error('guessMs')
                                <p class="text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <button
                            type="submit"
                            @disabled($roundActive)
                            class="h-11 rounded-md bg-cloud px-4 text-sm font-medium text-white transition-colors hover:bg-cloud/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cloud disabled:cursor-not-allowed disabled:opacity-50 md:h-12 md:text-base xl:col-span-2 xl:h-14 xl:text-lg"
                        >
                            <span wire:loading.remove wire:target="startRound">Wake an app</span>
                            <span wire:loading wire:target="startRound">Waking…</span>
                        </button>

                        @error('round')
                            <p class="text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </form>
                @endif

                <div wire:poll.5s class="flex items-center gap-2 border-t border-black/5 pt-4 text-sm text-slate-500 md:text-base">
                    @if ($this->readyCount > 0)
                        <span class="relative flex size-2">
                            <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-75"></span>
                            <span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span>
                        </span>
                        An app is scaled to zero and ready to wake
                    @elseif ($this->nextReadySeconds !== null && $this->nextReadySeconds > 0)
                        <span class="inline-flex size-2 rounded-full bg-amber-400"></span>
                        Every app is recovering — next one ready in ~{{ $this->nextReadySeconds }}s
                    @else
                        <span class="inline-flex size-2 rounded-full bg-slate-300"></span>
                        Waiting for an app to become available…
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Leaderboard overlay: always pinned to the right --}}
    <aside class="absolute top-6 right-6 z-10 hidden max-h-[calc(100%-3rem)] w-80 flex-col gap-6 overflow-y-auto rounded-2xl bg-white/85 p-6 shadow-lg ring-1 ring-black/5 backdrop-blur-md lg:flex xl:top-8 xl:right-8 xl:max-h-[calc(100%-4rem)] xl:w-96 xl:gap-8 xl:p-7">
        <div>
            <h2 class="font-mono text-xs font-medium tracking-wide text-slate-400 uppercase xl:text-sm">Leaderboard</h2>

            @if ($this->leaderboard->isEmpty())
                <p class="mt-3 text-sm text-slate-400 xl:text-base">No rounds yet — be the first!</p>
            @else
                <ol role="list" class="mt-3 flex flex-col gap-2.5 xl:gap-3">
                    @foreach ($this->leaderboard as $round)
                        <li wire:key="leader-{{ $round->id }}" class="flex items-baseline gap-3 text-sm xl:text-base">
                            <div class="w-6 font-mono text-xs text-slate-400 tabular-nums xl:text-sm">{{ $loop->iteration }}</div>
                            <div class="flex-1 truncate font-medium text-slate-700">{{ $round->player_name }}</div>
                            <div class="font-mono text-xs text-cloud tabular-nums xl:text-sm">±{{ number_format($round->delta_ms) }} ms</div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </aside>
</div>

<script>
    const frame = document.getElementById('wake-frame');
    const stopwatch = document.getElementById('wake-stopwatch');

    let raf = null;
    let abortActiveRound = null;
    let resetTimer = null;

    // Cancel a pending auto-reset so it can't wipe the next player's input.
    window.cancelGuessReset = () => {
        clearTimeout(resetTimer);
        resetTimer = null;
    };

    // Blank the stopwatch and stage so the next player starts clean.
    window.resetWakeStage = () => {
        stopwatch.textContent = '0 ms';
        frame.classList.add('opacity-0');
        frame.src = 'about:blank';
    };

    // After a result is shown, reset to a blank form for the next player.
    $wire.on('round-complete', () => {
        window.cancelGuessReset();
        resetTimer = setTimeout(() => {
            resetTimer = null;
            window.resetWakeStage();
            $wire.newGuess();
        }, 10000);
    });

    $wire.on('round-started', ({ token, url, timeoutMs }) => {
        window.cancelGuessReset();

        if (abortActiveRound) {
            abortActiveRound();
        }

        // Blank the stage immediately so the previous round's page disappears.
        // The frame is revealed again once the page finishes rendering.
        frame.classList.add('opacity-0');

        const cacheBust = url.includes('?') ? '&' : '?';
        const previewUrl = url + cacheBust + 'cs=' + token + '-preview';
        const wakeProbeUrl = new URL('/wake', url);

        wakeProbeUrl.search = 'cs=' + token + '-probe';

        let start = null;
        let stopped = false;
        let previewRequested = false;

        const render = (ms) => {
            stopwatch.textContent = `${Math.round(ms).toLocaleString()} ms`;
        };

        const tick = () => {
            if (start !== null) {
                render(performance.now() - start);
            }

            raf = requestAnimationFrame(tick);
        };

        // Resource Timing gives us the browser's actual request/first-response
        // boundary when the target exposes Timing-Allow-Origin. Fall back to
        // the fetch promise timing for older deployed targets.
        const responseHeaderMs = (resourceUrl, fallbackStart) => {
            const entries = performance.getEntriesByName(resourceUrl);
            const entry = entries[entries.length - 1];

            if (entry?.responseStart > 0) {
                const requestStart = entry.requestStart > 0 ? entry.requestStart : entry.startTime;

                return entry.responseStart - requestStart;
            }

            return performance.now() - fallbackStart;
        };

        const loadPreview = () => {
            if (previewRequested) {
                return;
            }

            previewRequested = true;
            frame.src = previewUrl;
        };

        // Stop the clock when the wake probe receives response headers. The
        // iframe is only loaded after this point so it cannot be the request
        // that wakes the app.
        const stopClock = (ms) => {
            if (stopped) return null;
            stopped = true;
            cancelAnimationFrame(raf);
            clearTimeout(timeout);
            window.removeEventListener('message', onMessage);
            render(ms);
            return Math.round(ms);
        };

        // The app is awake once it has responded, so time a few warm probes
        // and report the fastest as the round-trip share of the wake.
        const measureLatency = async () => {
            const pingUrl = new URL('/wake', url);
            let best = null;

            for (let i = 0; i < 3; i++) {
                pingUrl.search = 'ping=' + token + '-' + i;
                const t0 = performance.now();

                try {
                    await fetch(pingUrl, { method: 'HEAD', mode: 'no-cors', cache: 'no-store' });
                } catch (error) {
                    return null;
                }

                const ms = responseHeaderMs(pingUrl.href, t0);
                best = best === null ? ms : Math.min(best, ms);
            }

            return Math.round(best);
        };

        const record = async (ms) => {
            const coldMs = stopClock(ms);

            if (coldMs === null) {
                return;
            }

            loadPreview();

            // The cold response includes the network round-trip and the app's
            // steady-state request cost. Measuring a warm baseline straight
            // after lets the server subtract it, leaving just the wake time.
            const latency = await measureLatency();

            if (latency !== null) {
                // Snap the stopwatch to the wake time so it matches the result.
                render(Math.max(0, coldMs - Math.min(latency, coldMs)));
            }

            $wire.recordResult(token, coldMs, latency);
        };

        const teardown = () => {
            stopped = true;
            cancelAnimationFrame(raf);
            clearTimeout(timeout);
            frame.removeEventListener('load', onLoad);
            window.removeEventListener('message', onMessage);
            abortActiveRound = null;
        };

        // The iframe load only reveals the rendered page — and acts as the
        // fallback clock stop if the probe request failed.
        const onLoad = () => {
            frame.removeEventListener('load', onLoad);
            frame.classList.remove('opacity-0');

            if (! stopped && start !== null) {
                record(performance.now() - start);
            }
        };

        // Optional earlier stop: the app posts first-paint to its parent.
        const onMessage = (event) => {
            if (event.data?.type === 'first-paint') {
                record(performance.now() - start);
            }
        };

        const timeout = setTimeout(() => {
            if (stopped) return;
            teardown();
            frame.src = 'about:blank';
            $wire.voidRound(token);
        }, timeoutMs);

        abortActiveRound = teardown;

        frame.addEventListener('load', onLoad);
        window.addEventListener('message', onMessage);

        render(0);
        start = performance.now();

        raf = requestAnimationFrame(tick);

        // Primary clock stop: this HEAD probe is the first request to the
        // sleeping app and resolves when response headers arrive.
        fetch(wakeProbeUrl.href, { method: 'HEAD', mode: 'no-cors', cache: 'no-store' })
            .then(() => record(responseHeaderMs(wakeProbeUrl.href, start)))
            .catch(() => {
                if (! stopped) {
                    loadPreview();
                }
            });
    });
</script>
