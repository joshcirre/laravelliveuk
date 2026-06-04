<?php

use App\Models\Round;
use App\Services\LaravelCloud;
use App\Services\WakeTracker;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Guess the Cold Start')] class extends Component
{
    public string $playerName = '';

    public ?int $guessMs = null;

    public ?string $selectedEnvId = null;

    public bool $roundActive = false;

    public ?int $roundToken = null;

    /** @var array{player_name: string, target_name: string, guess_ms: int, actual_ms: int, delta_ms: int}|null */
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
            'selectedEnvId' => ['required', 'string'],
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
    public function leaderboard(): Collection
    {
        return Round::closest()->limit(10)->get();
    }

    #[Computed]
    public function recentRounds(): Collection
    {
        return Round::recent()->limit(8)->get();
    }

    public function selectTarget(string $environmentId): void
    {
        if ($this->roundActive) {
            return;
        }

        $this->resetErrorBag('selectedEnvId');
        $this->selectedEnvId = $environmentId;
    }

    public function startRound(): void
    {
        $this->validate();

        $target = $this->findTarget($this->selectedEnvId);

        if ($target === null) {
            $this->addError('selectedEnvId', 'Pick one of the apps on the left.');

            return;
        }

        $status = app(LaravelCloud::class)->statuses()[$target['environment_id']] ?? LaravelCloud::STATUS_UNKNOWN;

        if (in_array($status, ['deploying', 'stopped'])) {
            $this->addError('selectedEnvId', "That app is {$status} right now — pick another one.");

            return;
        }

        $tracker = app(WakeTracker::class);

        if (! $tracker->isReady($target['environment_id'])) {
            $seconds = $tracker->secondsUntilReady($target['environment_id']);
            $this->addError('selectedEnvId', "That app was woken recently — ready again in about {$seconds} seconds.");

            return;
        }

        $tracker->markWoken($target['environment_id']);

        $this->lastResult = null;
        $this->notice = null;
        $this->roundActive = true;
        $this->roundToken = random_int(100000, 99999999);

        $this->dispatch(
            'round-started',
            token: $this->roundToken,
            url: $target['url'],
            timeoutMs: config('game.round_timeout_ms'),
        );
    }

    public function recordResult(int $token, int $actualMs): void
    {
        if (! $this->roundActive || $token !== $this->roundToken) {
            return;
        }

        $target = $this->findTarget($this->selectedEnvId);

        $round = Round::create([
            'player_name' => $this->playerName,
            'target_name' => $target['name'],
            'target_url' => $target['url'],
            'guess_ms' => $this->guessMs,
            'actual_ms' => $actualMs,
            'delta_ms' => abs($this->guessMs - $actualMs),
        ]);

        $this->lastResult = $round->only(['player_name', 'target_name', 'guess_ms', 'actual_ms', 'delta_ms']);
        $this->finishRound();
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
        $this->selectedEnvId = null;
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

<div class="mx-auto flex min-h-screen max-w-screen-2xl flex-col gap-6 p-6">
    <header class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h1 class="text-3xl font-bold tracking-tight">Guess the Cold Start ❄️</h1>
            <p class="mt-1 text-sm text-zinc-400">Pick a hibernating app, guess its wake-up time in milliseconds, then watch it load. Closest guess wins.</p>
        </div>
        <p class="text-xs text-zinc-500">Powered by Laravel Cloud hibernation</p>
    </header>

    <div class="grid flex-1 grid-cols-12 gap-6">
        {{-- Targets --}}
        <section
            class="col-span-12 flex flex-col gap-3 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-4 lg:col-span-3"
            @if (! $roundActive) wire:poll.10s @endif
        >
            <h2 class="text-sm font-semibold tracking-wide text-zinc-400 uppercase">Apps</h2>

            <ul class="flex flex-col gap-2">
                @foreach ($this->targets as $target)
                    @php
                        $isSelected = $selectedEnvId === $target['environment_id'];
                        [$label, $badge] = match (true) {
                            $target['status'] === 'deploying' => ['deploying', 'bg-amber-500/15 text-amber-300 ring-amber-500/40'],
                            $target['status'] === 'stopped' => ['stopped', 'bg-red-500/15 text-red-300 ring-red-500/40'],
                            $target['cooldown'] > 0 => ["cooling {$target['cooldown']}s", 'bg-zinc-500/15 text-zinc-400 ring-zinc-500/40'],
                            default => ['ready', 'bg-sky-500/15 text-sky-300 ring-sky-500/40'],
                        };
                    @endphp

                    <li wire:key="target-{{ $target['environment_id'] }}">
                        <button
                            type="button"
                            wire:click="selectTarget('{{ $target['environment_id'] }}')"
                            @disabled($roundActive || ! $target['playable'])
                            class="flex w-full items-center justify-between gap-2 rounded-xl border px-3 py-3 text-left transition
                                {{ $isSelected ? 'border-sky-400 bg-sky-500/10' : 'border-zinc-800 bg-zinc-900' }}
                                {{ $target['playable'] && ! $roundActive ? 'cursor-pointer hover:border-sky-500/60' : 'opacity-50' }}"
                        >
                            <span class="text-sm font-medium">{{ $target['name'] }}</span>
                            <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold tracking-wide uppercase ring-1 {{ $badge }}">
                                @if ($target['playable'])
                                    <span class="relative flex size-1.5">
                                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-sky-400 opacity-75"></span>
                                        <span class="relative inline-flex size-1.5 rounded-full bg-sky-400"></span>
                                    </span>
                                @endif
                                {{ $label }}
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>

            @error('selectedEnvId')
                <p class="text-sm text-red-400">{{ $message }}</p>
            @enderror

            <p class="mt-auto text-xs text-zinc-600">An app is <span class="text-sky-400">ready</span> once nobody has clicked it for {{ config('game.wake_cooldown') }} seconds — that's how long Laravel Cloud needs to put it back to sleep.</p>
        </section>

        {{-- Stage --}}
        <section class="col-span-12 flex flex-col gap-4 lg:col-span-6">
            <form wire:submit="startRound" class="flex flex-wrap items-end gap-3 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-4">
                <div class="flex min-w-40 flex-1 flex-col gap-1">
                    <label for="player-name" class="text-xs font-semibold tracking-wide text-zinc-400 uppercase">Your name</label>
                    <input
                        id="player-name"
                        type="text"
                        wire:model="playerName"
                        placeholder="Taylor"
                        class="rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm focus:border-sky-500 focus:outline-none"
                    />
                    @error('playerName')
                        <p class="text-xs text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex min-w-40 flex-1 flex-col gap-1">
                    <label for="guess-ms" class="text-xs font-semibold tracking-wide text-zinc-400 uppercase">Your guess (ms)</label>
                    <input
                        id="guess-ms"
                        type="number"
                        min="1"
                        wire:model="guessMs"
                        placeholder="413"
                        class="rounded-lg border border-zinc-700 bg-zinc-950 px-3 py-2 text-sm focus:border-sky-500 focus:outline-none"
                    />
                    @error('guessMs')
                        <p class="text-xs text-red-400">{{ $message }}</p>
                    @enderror
                </div>

                <button
                    type="submit"
                    @disabled($roundActive)
                    class="rounded-lg bg-sky-500 px-6 py-2 text-sm font-bold text-white transition hover:bg-sky-400 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    <span wire:loading.remove wire:target="startRound">GO</span>
                    <span wire:loading wire:target="startRound">Checking…</span>
                </button>
            </form>

            @if ($notice)
                <div wire:key="round-notice" class="rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm text-amber-300">{{ $notice }}</div>
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
                <div wire:key="round-result" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-sky-500/40 bg-sky-500/10 px-4 py-3">
                    <p class="text-sm">
                        <span class="font-bold">{{ $lastResult['player_name'] }}</span> guessed
                        <span class="font-mono font-bold">{{ number_format($lastResult['guess_ms']) }} ms</span> —
                        {{ $lastResult['target_name'] }} woke up in
                        <span class="font-mono font-bold">{{ number_format($lastResult['actual_ms']) }} ms</span>
                    </p>
                    <p class="text-sm font-bold text-sky-300">{{ $verdict }} Off by {{ number_format($lastResult['delta_ms']) }} ms</p>
                </div>
            @endif

            <div wire:ignore wire:key="cold-start-stage" class="flex flex-1 flex-col gap-4 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-4">
                <div class="flex items-baseline justify-center py-2">
                    <span id="cold-start-stopwatch" class="font-mono text-7xl font-bold tracking-tight tabular-nums">0 ms</span>
                </div>

                <iframe
                    id="cold-start-frame"
                    title="Cold start preview"
                    class="min-h-96 w-full flex-1 rounded-xl border border-zinc-800 bg-white"
                ></iframe>
            </div>
        </section>

        {{-- Leaderboard --}}
        <section class="col-span-12 flex flex-col gap-5 rounded-2xl border border-zinc-800 bg-zinc-900/60 p-4 lg:col-span-3">
            <div>
                <h2 class="text-sm font-semibold tracking-wide text-zinc-400 uppercase">Leaderboard</h2>

                @if ($this->leaderboard->isEmpty())
                    <p class="mt-2 text-sm text-zinc-600">No rounds yet — be the first!</p>
                @else
                    <ol class="mt-2 flex flex-col gap-1.5">
                        @foreach ($this->leaderboard as $round)
                            <li wire:key="leader-{{ $round->id }}" class="flex items-center gap-2 rounded-lg bg-zinc-900 px-3 py-2 text-sm">
                                <span class="w-5 font-mono text-zinc-500">{{ $loop->iteration }}</span>
                                <span class="flex-1 truncate font-medium">{{ $round->player_name }}</span>
                                <span class="font-mono text-xs text-sky-300">±{{ number_format($round->delta_ms) }} ms</span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>

            <div>
                <h2 class="text-sm font-semibold tracking-wide text-zinc-400 uppercase">Recent rounds</h2>

                @if ($this->recentRounds->isEmpty())
                    <p class="mt-2 text-sm text-zinc-600">Nothing yet.</p>
                @else
                    <ul class="mt-2 flex flex-col gap-1.5">
                        @foreach ($this->recentRounds as $round)
                            <li wire:key="recent-{{ $round->id }}" class="rounded-lg bg-zinc-900 px-3 py-2 text-xs text-zinc-400">
                                <span class="font-medium text-zinc-200">{{ $round->player_name }}</span>
                                guessed <span class="font-mono">{{ number_format($round->guess_ms) }}</span>,
                                actual <span class="font-mono">{{ number_format($round->actual_ms) }}</span>
                                <span class="font-mono text-sky-300/80">(±{{ number_format($round->delta_ms) }})</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
    </div>
</div>

<script>
    const frame = document.getElementById('cold-start-frame');
    const stopwatch = document.getElementById('cold-start-stopwatch');

    let raf = null;
    let abortActiveRound = null;

    $wire.on('round-started', ({ token, url, timeoutMs }) => {
        if (abortActiveRound) {
            abortActiveRound();
        }

        const start = performance.now();
        let finished = false;

        const render = (ms) => {
            stopwatch.textContent = `${Math.round(ms).toLocaleString()} ms`;
        };

        const tick = () => {
            render(performance.now() - start);
            raf = requestAnimationFrame(tick);
        };

        const teardown = () => {
            finished = true;
            cancelAnimationFrame(raf);
            clearTimeout(timeout);
            frame.removeEventListener('load', onLoad);
            window.removeEventListener('message', onMessage);
            abortActiveRound = null;
        };

        const finish = (ms) => {
            if (finished) return;
            teardown();
            render(ms);
            $wire.recordResult(token, Math.round(ms));
        };

        // Primary stop: the iframe finished loading. Optional earlier stop: the
        // target app posts a first-paint message to its parent window.
        const onLoad = () => finish(performance.now() - start);
        const onMessage = (event) => {
            if (event.data?.type === 'first-paint') {
                finish(performance.now() - start);
            }
        };

        const timeout = setTimeout(() => {
            if (finished) return;
            teardown();
            $wire.voidRound(token);
        }, timeoutMs);

        abortActiveRound = teardown;

        frame.addEventListener('load', onLoad);
        window.addEventListener('message', onMessage);

        render(0);
        frame.src = url + (url.includes('?') ? '&' : '?') + 'cs=' + token;
        raf = requestAnimationFrame(tick);
    });
</script>
