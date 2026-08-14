<?php

use App\Models\Attendee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Attendees — Guess the Scale to Zero')] class extends Component
{
    use WithPagination;

    #[Computed]
    public function attendees(): LengthAwarePaginator
    {
        return Attendee::query()
            ->select(['id', 'name', 'email', 'created_at'])
            ->latest('id')
            ->paginate(50);
    }

    #[Computed]
    public function downloadUrl(): string
    {
        return URL::signedRoute('attendees.csv');
    }
};
?>

<div class="min-h-dvh bg-slate-50 px-4 py-8 sm:px-6 lg:px-8">
    <main class="mx-auto flex max-w-6xl flex-col gap-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex flex-col gap-1.5">
                <p class="font-mono text-xs font-medium tracking-wide text-cloud uppercase">Laravel Live</p>
                <h1 class="text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl">Attendees</h1>
                <p class="text-sm text-slate-500 sm:text-base">{{ number_format($this->attendees->total()) }} {{ Str::plural('registration', $this->attendees->total()) }}</p>
            </div>

            <a
                href="{{ $this->downloadUrl }}"
                class="inline-flex h-11 items-center justify-center rounded-md bg-cloud px-5 text-sm font-medium text-white transition-colors hover:bg-cloud/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-cloud sm:h-12 sm:text-base"
            >
                Download CSV
            </a>
        </div>

        <div class="overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-black/5">
            @if ($this->attendees->isEmpty())
                <p class="px-6 py-16 text-center text-slate-500">No one has registered yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm sm:text-base">
                        <thead class="border-b border-slate-200 bg-slate-50 text-xs tracking-wide text-slate-500 uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3 font-medium sm:px-6">Name</th>
                                <th scope="col" class="px-5 py-3 font-medium sm:px-6">Email</th>
                                <th scope="col" class="px-5 py-3 font-medium whitespace-nowrap sm:px-6">Registered</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($this->attendees as $attendee)
                                <tr wire:key="attendee-{{ $attendee->id }}">
                                    <td class="px-5 py-4 font-medium whitespace-nowrap text-slate-800 sm:px-6">{{ $attendee->name }}</td>
                                    <td class="px-5 py-4 text-slate-600 sm:px-6">
                                        <a href="mailto:{{ $attendee->email }}" class="hover:text-cloud">{{ $attendee->email }}</a>
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap text-slate-500 sm:px-6">{{ $attendee->created_at->format('M j, Y g:i A') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @if ($this->attendees->hasPages())
            <div>{{ $this->attendees->links() }}</div>
        @endif
    </main>
</div>
