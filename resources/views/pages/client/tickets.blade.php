<?php

use App\Enums\QuoteResponse;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Attachment;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\TicketComment;
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

    #[Url(as: 'ticket', except: null)]
    public ?string $openReference = null;

    public string $comment = '';

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
     * Get the ticket shown in the detail panel, if one is open.
     */
    #[Computed]
    public function selected(): ?Ticket
    {
        if ($this->openReference === null) {
            return null;
        }

        return $this->team->tickets()
            ->with(['reporter', 'project', 'comments.author', 'attachments'])
            ->where('reference', $this->openReference)
            ->first();
    }

    /**
     * Get the messages on the open ticket that the client is allowed to read.
     *
     * @return Collection<int, TicketComment>
     */
    #[Computed]
    public function conversation(): Collection
    {
        return $this->selected?->comments->reject->is_internal->values() ?? new Collection;
    }

    /**
     * Get the files on the open ticket that the studio has shared.
     *
     * @return Collection<int, Attachment>
     */
    #[Computed]
    public function sharedFiles(): Collection
    {
        return $this->selected?->attachments->where('shared_with_client', true)->values() ?? new Collection;
    }

    /**
     * Open a ticket in the detail panel.
     */
    public function openTicket(string $reference): void
    {
        $this->openReference = $reference;
        $this->reset('comment');
        $this->resetValidation();

        unset($this->selected, $this->conversation, $this->sharedFiles);
    }

    /**
     * Close the detail panel.
     */
    public function closeTicket(): void
    {
        $this->reset('openReference', 'comment');
        $this->resetValidation();

        unset($this->selected, $this->conversation, $this->sharedFiles);
    }

    /**
     * Add a message to the open ticket.
     */
    public function addComment(): void
    {
        abort_unless(Auth::user()->accessFor()->canRaiseTickets(), 403);

        $ticket = $this->selected;

        abort_unless($ticket, 404);

        $validated = $this->validate(['comment' => ['required', 'string', 'max:5000']]);

        $ticket->comments()->create([
            'user_id' => Auth::id(),
            'body' => $validated['comment'],
            'is_internal' => false,
        ]);

        $ticket->touch();

        $this->reset('comment');

        unset($this->selected, $this->conversation, $this->tickets);
    }

    /**
     * Approve or decline the quote on the open ticket.
     */
    public function respondToQuote(string $response): void
    {
        abort_unless(Auth::user()->accessFor()->canSeeBilling(), 403);

        $ticket = $this->selected;

        abort_unless($ticket && $ticket->hasQuoteAwaitingResponse(), 404);

        $decision = QuoteResponse::from($response);

        $ticket->update([
            'quote_response' => $decision,
            'quote_responded_at' => now(),
            'status' => $decision === QuoteResponse::Approved ? TicketStatus::InProgress : TicketStatus::Open,
        ]);

        $ticket->comments()->create([
            'user_id' => Auth::id(),
            'body' => $decision === QuoteResponse::Approved
                ? __('Quote approved. Please go ahead.')
                : __('Quote declined for now.'),
            'is_internal' => false,
        ]);

        unset($this->selected, $this->conversation, $this->tickets);

        Flux::toast(
            variant: $decision === QuoteResponse::Approved ? 'success' : 'warning',
            text: $decision === QuoteResponse::Approved
                ? __('Quote approved — Ross has been told.')
                : __('Quote declined.'),
        );
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

<div wire:poll.15s x-data="{ panelOpen: @js($this->selected !== null) }">
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
                    <button type="button" wire:click="openTicket('{{ $ticket->reference }}')"
                            x-on:click="panelOpen = true" wire:key="row-{{ $ticket->id }}"
                            class="grid w-full cursor-pointer grid-cols-[96px_1fr_150px_110px_130px] items-center gap-x-4 gap-y-2 border-b border-line px-[22px] py-[18px] text-left transition-colors hover:rsc-tint">
                        <span class="font-mono text-xs text-muted">{{ $ticket->reference }}</span>
                        <span>
                            <span class="block font-display text-base font-bold tracking-[-0.015em]">{{ $ticket->title }}</span>
                            <span class="mt-[3px] block text-xs text-muted">{{ $ticket->updatedLabel() }}</span>
                        </span>
                        <span class="text-[13px] text-muted">{{ $ticket->system }}</span>
                        <span class="text-[13px] {{ $ticket->priority->isPressing() ? 'text-warm' : 'text-muted' }}">{{ $ticket->priority->label() }}</span>
                        <span class="flex items-center gap-2 justify-self-start">
                            <x-rsc.pill :tone="$ticket->status->tone()">{{ str($ticket->status->clientLabel())->lower() }}</x-rsc.pill>
                            @if ($ticket->hasQuoteAwaitingResponse())
                                <span class="font-mono text-[10px] text-warm">{{ __('quote') }}</span>
                            @endif
                        </span>
                    </button>
                @empty
                    <div class="px-[22px] py-8 text-sm text-muted">{{ __('No tickets match that filter.') }}</div>
                @endforelse
            </div>
        </div>

        <div class="px-[22px] py-4 font-mono text-[11px] text-muted">
            {{ __(':shown of :total tickets', ['shown' => $this->tickets->count(), 'total' => $this->totalCount]) }}
        </div>
    </div>

    {{-- Rendered unconditionally so Alpine sets the teleport up on page load
         rather than on a later morph; the contents are what is conditional. --}}
    <x-rsc.slide-over open="panelOpen" close="panelOpen = false; $wire.closeTicket()"
                      :heading="$this->selected?->title ?? __('Ticket')">
        @if ($ticket = $this->selected)
            <div class="sticky top-0 z-1 flex items-start gap-4 border-b border-line bg-panel px-[clamp(20px,3vw,30px)] py-5">
                <div class="min-w-0 flex-1">
                    <div class="mb-2 flex flex-wrap items-center gap-x-2.5 gap-y-1 font-mono text-[11px] text-muted">
                        <span>{{ $ticket->reference }}</span>
                        <span>·</span>
                        <span>{{ $ticket->system }}</span>
                    </div>
                    <h2 class="m-0 font-display text-[clamp(19px,2.2vw,26px)] font-bold tracking-[-0.025em]">{{ $ticket->title }}</h2>
                </div>

                <button type="button" x-on:click="panelOpen = false; $wire.closeTicket()" aria-label="{{ __('Close ticket') }}"
                        class="grid size-9 flex-none cursor-pointer place-items-center rounded-full border border-line text-muted transition-colors hover:border-brand hover:text-brand">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" class="size-[18px]" aria-hidden="true">
                        <path d="M6 6l12 12M18 6L6 18" />
                    </svg>
                </button>
            </div>

            <div class="flex flex-col gap-6 px-[clamp(20px,3vw,30px)] py-6">
                <div class="flex flex-wrap items-center gap-2">
                    <x-rsc.pill :tone="$ticket->status->tone()">{{ str($ticket->status->clientLabel())->lower() }}</x-rsc.pill>
                    <x-rsc.pill :tone="$ticket->priority->isPressing() ? 'warm' : 'muted'">{{ str($ticket->priority->label())->lower() }}</x-rsc.pill>
                    <x-rsc.pill tone="muted">{{ str($ticket->type->label())->lower() }}</x-rsc.pill>
                    <span class="ms-auto font-mono text-[11px] text-muted">{{ __('raised :when', ['when' => $ticket->created_at->format('j M Y')]) }}</span>
                </div>

                <div>
                    <div class="mb-2 font-mono text-[11px] text-muted">what_you_told_us</div>
                    <p class="m-0 text-sm leading-relaxed whitespace-pre-line text-muted text-pretty">{{ $ticket->description }}</p>
                    @if ($ticket->page_url)
                        <p class="m-0 mt-2 font-mono text-[11px] text-muted">{{ $ticket->page_url }}</p>
                    @endif
                </div>

                @if ($ticket->quote_sent_at && $ticket->billing_mode === \App\Enums\BillingMode::Chargeable)
                    <div class="rounded-2xl border border-brand p-[clamp(16px,2vw,22px)]" style="box-shadow: 0 0 40px var(--rsc-glow)">
                        <div class="mb-3 flex items-center justify-between font-mono text-[11px] text-muted">
                            <span>quote</span>
                            <span>{{ __('sent :when', ['when' => $ticket->quote_sent_at->format('j M')]) }}</span>
                        </div>

                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <span class="font-display text-[clamp(26px,3vw,34px)] font-extrabold tracking-[-0.035em]">£{{ number_format($ticket->quoteTotal()) }}</span>
                            <span class="text-[13px] text-muted">
                                {{ __(':hours hours at £:rate', [
                                    'hours' => rtrim(rtrim(number_format((float) $ticket->quoted_hours, 2), '0'), '.'),
                                    'rate' => number_format((int) $ticket->quoted_rate),
                                ]) }}
                            </span>
                            <span class="w-full text-xs text-muted">{{ __('Excluding VAT. Nothing starts until you approve it.') }}</span>
                        </div>

                        @if ($ticket->quote_response)
                            <div class="mt-4 flex items-center gap-2.5">
                                <x-rsc.pill :tone="$ticket->quote_response->tone()">{{ str($ticket->quote_response->label())->lower() }}</x-rsc.pill>
                                <span class="font-mono text-[11px] text-muted">{{ $ticket->quote_responded_at?->diffForHumans() }}</span>
                            </div>
                        @elseif (auth()->user()->accessFor()->canSeeBilling())
                            <div class="mt-4 flex flex-wrap gap-2.5">
                                <x-rsc.button wire:click="respondToQuote('approved')" class="!px-5 !py-3 !text-sm">
                                    {{ __('Approve the quote') }}
                                </x-rsc.button>
                                <x-rsc.button wire:click="respondToQuote('declined')" variant="outline" class="!px-5 !py-3 !text-sm">
                                    {{ __('Not just now') }}
                                </x-rsc.button>
                            </div>
                        @else
                            <p class="mt-4 mb-0 text-[13px] text-muted">
                                {{ __('Someone with billing access needs to approve this one.') }}
                            </p>
                        @endif
                    </div>
                @endif

                @if ($this->sharedFiles->isNotEmpty())
                    <div>
                        <div class="mb-2.5 font-mono text-[11px] text-muted">files</div>
                        <div class="flex flex-col gap-2.5">
                            @foreach ($this->sharedFiles as $file)
                                <div class="grid grid-cols-[44px_1fr] items-center gap-3.5 rounded-[14px] border border-line px-3.5 py-3">
                                    <span class="grid h-[34px] place-items-center rounded-[9px] font-mono text-[10px] text-brand rsc-tint">{{ $file->kind }}</span>
                                    <span class="min-w-0">
                                        <span class="block font-display text-sm font-bold break-words">{{ $file->name }}</span>
                                        <span class="mt-[3px] block font-mono text-[11px] text-muted">{{ $file->metaLabel() }}</span>
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div>
                    <div class="mb-2.5 font-mono text-[11px] text-muted">conversation</div>
                    <x-rsc.ticket-thread :comments="$this->conversation" />
                </div>

                @if (auth()->user()->accessFor()->canRaiseTickets())
                    <form wire:submit="addComment" class="flex flex-col gap-3">
                        <x-rsc.field name="comment">
                            <x-rsc.textarea wire:model="comment" rows="3"
                                            placeholder="{{ __('Add anything that might help, or ask where it is up to.') }}" />
                        </x-rsc.field>

                        <div class="flex flex-wrap items-center gap-3.5">
                            <x-rsc.button type="submit" class="!px-6 !py-3 !text-sm">{{ __('Send') }}</x-rsc.button>
                            <span class="text-xs text-muted">{{ __('Ross gets an email when you reply.') }}</span>
                        </div>
                    </form>
                @endif
            </div>
        @endif
    </x-rsc.slide-over>
</div>
