<?php

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Team;
use App\Models\Ticket;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('layouts::rsc.client')]
#[Title('Tickets')]
class extends Component {
    #[Url(as: 'filter', except: 'all')]
    public string $filter = 'all';

    #[Url(as: 'raise', except: false)]
    public bool $formOpen = false;

    public ?string $raisedReference = null;

    public string $subject = '';

    public string $type = 'bug';

    public string $priority = 'normal';

    public string $system = '';

    public string $pageUrl = '';

    public string $description = '';

    /**
     * Get the business the signed-in person is looking at.
     */
    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Get the tickets matching the selected status filter.
     *
     * @return Collection<int, Ticket>
     */
    #[Computed]
    public function tickets(): Collection
    {
        return $this->team->tickets()
            ->when($this->filter !== 'all', fn ($query) => $query->where('status', $this->filter))
            ->latest('updated_at')
            ->get();
    }

    /**
     * Get the total number of tickets, so the footer can show "3 of 6".
     */
    #[Computed]
    public function totalCount(): int
    {
        return $this->team->tickets()->count();
    }

    /**
     * Get the systems the client can raise a ticket against.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function systems(): array
    {
        // reorder() drops the relations' default "latest first" ordering: MySQL
        // rejects an ORDER BY on a column that DISTINCT does not select.
        return $this->team->tickets()
            ->whereNotNull('system')
            ->reorder()
            ->distinct()
            ->pluck('system')
            ->merge($this->team->projects()->reorder()->pluck('title'))
            ->unique()
            ->sortBy(fn (string $system) => mb_strtolower($system))
            ->values()
            ->all();
    }

    /**
     * Open or close the new ticket form.
     */
    public function toggleForm(): void
    {
        $this->formOpen = ! $this->formOpen;
        $this->raisedReference = null;
    }

    /**
     * Close the form and clear the confirmation.
     */
    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->raisedReference = null;
    }

    /**
     * Raise a new ticket against this client's account.
     */
    public function save(): void
    {
        abort_unless(Auth::user()->accessFor()->canRaiseTickets(), 403);

        $validated = $this->validate([
            'subject' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::enum(TicketType::class)],
            'priority' => ['required', 'string', Rule::enum(TicketPriority::class)],
            'system' => ['nullable', 'string', 'max:255'],
            'pageUrl' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string'],
        ]);

        $ticket = $this->team->tickets()->create([
            'reference' => Ticket::nextReference(),
            'reported_by' => Auth::id(),
            'title' => $validated['subject'],
            'description' => $validated['description'],
            'system' => $validated['system'] ?: null,
            'page_url' => $validated['pageUrl'] ?: null,
            'type' => $validated['type'],
            'priority' => $validated['priority'],
            'status' => TicketStatus::Open,
        ]);

        $this->reset('subject', 'system', 'pageUrl', 'description');
        $this->raisedReference = $ticket->reference;

        unset($this->tickets, $this->totalCount);

        Flux::toast(variant: 'success', text: __('Ticket :reference raised.', ['reference' => $ticket->reference]));
    }
}; ?>

@php
    $filters = collect([['all', 'All']])
        ->merge(collect(TicketStatus::cases())->map(fn ($status) => [$status->value, $status->clientLabel()]));
@endphp

<div>
    <div class="mb-[clamp(22px,2.6vw,34px)] flex flex-wrap items-end justify-between gap-5">
        <div>
            <x-rsc.kicker class="mb-2.5">support</x-rsc.kicker>
            <x-rsc.heading>{{ __('Tickets') }}</x-rsc.heading>
        </div>

        @if (auth()->user()->accessFor()->canRaiseTickets())
            <x-rsc.button wire:click="toggleForm" class="px-[26px] py-3.5">
                {{ $formOpen ? __('Close form') : __('Raise a ticket') }}
            </x-rsc.button>
        @endif
    </div>

    @if ($formOpen && auth()->user()->accessFor()->canRaiseTickets())
        <x-rsc.panel accent class="mb-[clamp(16px,2vw,24px)] animate-rsc-fade !p-[clamp(22px,2.6vw,34px)]">
            @if ($raisedReference)
                <div class="flex flex-col gap-3.5 py-3.5">
                    <x-rsc.kicker>ticket_raised</x-rsc.kicker>
                    <div class="font-display text-[clamp(22px,2.6vw,32px)] font-extrabold tracking-[-0.03em]">
                        {{ __('Logged as :reference.', ['reference' => $raisedReference]) }}
                    </div>
                    <p class="m-0 max-w-[52ch] text-[15px] text-muted">
                        {{ __('You\'ll get an email when Ross picks it up, and it\'ll show at the top of the list below.') }}
                    </p>
                    <x-rsc.button variant="outline" wire:click="closeForm" class="self-start !px-[22px] !py-2.5 !text-sm">
                        {{ __('Back to tickets') }}
                    </x-rsc.button>
                </div>
            @else
                <form wire:submit="save" class="flex flex-col gap-5">
                    <x-rsc.kicker tone="muted">new_ticket</x-rsc.kicker>

                    <x-rsc.field label="title" name="subject">
                        <x-rsc.input wire:model="subject" placeholder="{{ __('Short summary') }}" />
                    </x-rsc.field>

                    <div>
                        <span class="mb-2 block font-mono text-[11px] text-muted">type</span>
                        <div class="flex flex-wrap gap-2">
                            @foreach (TicketType::cases() as $option)
                                <x-rsc.chip wire:click="$set('type', '{{ $option->value }}')" :active="$type === $option->value" class="!text-[13px] !font-sans">
                                    {{ $option->label() }}
                                </x-rsc.chip>
                            @endforeach
                        </div>
                    </div>

                    <div>
                        <span class="mb-2 block font-mono text-[11px] text-muted">priority</span>
                        <div class="flex flex-wrap gap-2">
                            @foreach (TicketPriority::cases() as $option)
                                <x-rsc.chip wire:click="$set('priority', '{{ $option->value }}')" :active="$priority === $option->value" class="!text-[13px] !font-sans">
                                    {{ $option->label() }}
                                </x-rsc.chip>
                            @endforeach
                        </div>
                        <p class="mt-2.5 text-xs text-muted">{{ TicketPriority::from($priority)->hint() }}</p>
                    </div>

                    <div class="grid gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                        <x-rsc.field label="system" name="system">
                            <x-rsc.select wire:model="system">
                                <option value="">{{ __('Not sure') }}</option>
                                @foreach ($this->systems as $system)
                                    <option value="{{ $system }}">{{ $system }}</option>
                                @endforeach
                            </x-rsc.select>
                        </x-rsc.field>

                        <x-rsc.field label="page_url" name="pageUrl">
                            <x-rsc.input wire:model="pageUrl" placeholder="{{ __('Optional') }}" />
                        </x-rsc.field>
                    </div>

                    <x-rsc.field label="description" name="description">
                        <x-rsc.textarea wire:model="description" rows="5"
                                        placeholder="{{ __('What happened, and what you expected instead') }}" />
                    </x-rsc.field>

                    <div class="flex flex-wrap items-center gap-4">
                        <x-rsc.button type="submit" class="px-[30px] py-3.5">{{ __('Send ticket') }}</x-rsc.button>
                        <span class="text-[13px] text-muted">{{ __('Goes straight to Ross. Response within 1 working day.') }}</span>
                    </div>
                </form>
            @endif
        </x-rsc.panel>
    @endif

    <div class="mb-[18px] flex flex-wrap gap-2">
        @foreach ($filters as [$value, $label])
            <x-rsc.chip wire:click="$set('filter', '{{ $value }}')" :active="$filter === $value">{{ $label }}</x-rsc.chip>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-[20px] border border-line bg-panel">
        <div class="overflow-x-auto [overscroll-behavior-x:contain]">
            <div class="min-w-[700px]">
                <div class="grid grid-cols-[96px_1fr_150px_110px_130px] gap-4 border-b border-line px-[22px] py-3.5 font-mono text-[11px] tracking-[0.08em] text-muted">
                    <span>ref</span><span>ticket</span><span>system</span><span>priority</span><span>status</span>
                </div>

                @forelse ($this->tickets as $ticket)
                    <div class="grid grid-cols-[96px_1fr_150px_110px_130px] items-center gap-x-4 gap-y-2 border-b border-line px-[22px] py-[18px] transition-colors hover:rsc-tint">
                        <span class="font-mono text-xs text-muted">{{ $ticket->reference }}</span>
                        <span>
                            <span class="block font-display text-base font-bold tracking-[-0.015em]">{{ $ticket->title }}</span>
                            <span class="mt-[3px] block text-xs text-muted">{{ $ticket->updatedLabel() }}</span>
                        </span>
                        <span class="text-[13px] text-muted">{{ $ticket->system }}</span>
                        <span class="text-[13px] {{ $ticket->priority->isPressing() ? 'text-warm' : 'text-muted' }}">{{ $ticket->priority->label() }}</span>
                        <x-rsc.pill :tone="$ticket->status->tone()" class="justify-self-start">{{ str($ticket->status->clientLabel())->lower() }}</x-rsc.pill>
                    </div>
                @empty
                    <div class="px-[22px] py-8 text-sm text-muted">{{ __('No tickets match that filter.') }}</div>
                @endforelse
            </div>
        </div>

        <div class="px-[22px] py-4 font-mono text-[11px] text-muted">
            {{ __(':shown of :total tickets', ['shown' => $this->tickets->count(), 'total' => $this->totalCount]) }}
        </div>
    </div>
</div>
