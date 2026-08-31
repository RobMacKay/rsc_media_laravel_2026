<?php

use App\Enums\BillingMode;
use App\Enums\QuoteResponse;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\StudioSetting;
use App\Models\Ticket;
use Illuminate\Support\Facades\Auth;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('layouts::rsc.admin')]
#[Title('Queue')]
class extends Component {
    #[Url(as: 'filter', except: 'all')]
    public string $filter = 'all';

    #[Url(as: 'ticket', except: null)]
    public ?string $selectedReference = null;

    public string $replyMode = 'client';

    public string $reply = '';

    /** Whether the detail panel is showing; only meaningful below `lg`. */
    public bool $detailOpen = false;

    public bool $shareUploads = true;

    /**
     * Get every ticket across all clients, most pressing first.
     *
     * @return Collection<int, Ticket>
     */
    #[Computed]
    public function tickets(): Collection
    {
        return Ticket::query()
            ->with(['team', 'reporter', 'comments.author'])
            ->when($this->filter !== 'all', fn ($query) => $query->where('status', $this->filter))
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->latest('updated_at')
            ->get();
    }

    /**
     * Get the total ticket count, so the footer can show "4 of 7".
     */
    #[Computed]
    public function totalCount(): int
    {
        return Ticket::query()->count();
    }

    /**
     * Get the ticket shown in the detail pane.
     */
    #[Computed]
    public function current(): ?Ticket
    {
        $tickets = $this->tickets;

        return $tickets->firstWhere('reference', $this->selectedReference) ?? $tickets->first();
    }

    /**
     * Get the headline counts across the whole queue.
     *
     * @return array<int, array{label: string, value: int|string, colour: string}>
     */
    #[Computed]
    public function stats(): array
    {
        $all = Ticket::query()->get();

        return [
            ['label' => 'open', 'value' => $all->filter(fn (Ticket $t) => $t->status->isOpen())->count(), 'colour' => 'var(--rsc-text)'],
            ['label' => 'urgent', 'value' => $all->where('priority', TicketPriority::Urgent)->count(), 'colour' => 'var(--rsc-warm)'],
            ['label' => 'quotes_out', 'value' => $all->where('status', TicketStatus::QuoteSent)->count(), 'colour' => 'var(--rsc-accent-soft)'],
            ['label' => 'clients', 'value' => $all->pluck('team_id')->unique()->count(), 'colour' => 'var(--rsc-accent)'],
        ];
    }

    /**
     * Get the studio's defaults, used to pre-fill the quote rate.
     */
    #[Computed]
    public function settings(): StudioSetting
    {
        return StudioSetting::current();
    }

    /**
     * Select a ticket for the detail pane, sliding it in on small screens.
     */
    public function select(string $reference): void
    {
        $this->selectedReference = $reference;
        $this->detailOpen = true;
        $this->reset('reply');
        $this->resetValidation();

        unset($this->current);
    }

    /**
     * Dismiss the detail pane on small screens.
     */
    public function closeDetail(): void
    {
        $this->detailOpen = false;
    }

    /**
     * Post a reply to the client, or a note only the studio sees.
     */
    public function postReply(): void
    {
        $ticket = $this->current;

        abort_unless($ticket, 404);

        $validated = $this->validate(['reply' => ['required', 'string', 'max:5000']]);

        $ticket->comments()->create([
            'user_id' => Auth::id(),
            'body' => $validated['reply'],
            'is_internal' => $this->replyMode === 'internal',
        ]);

        $ticket->touch();

        $this->reset('reply');

        unset($this->tickets, $this->current);

        Flux::toast(variant: 'success', text: $this->replyMode === 'internal'
            ? __('Note saved.')
            : __('Reply sent to :client.', ['client' => $ticket->team->name]));
    }

    /**
     * Move the selected ticket to a different status.
     */
    public function setStatus(string $status): void
    {
        $ticket = $this->current;

        abort_unless($ticket, 404);

        $status = TicketStatus::from($status);

        $ticket->update([
            'status' => $status,
            'resolved_at' => $status === TicketStatus::Resolved ? now() : null,
        ]);

        unset($this->tickets, $this->current, $this->stats);
    }

    /**
     * Save the quote worked up in the detail pane and mark it as sent.
     */
    public function sendQuote(array $payload): void
    {
        $ticket = $this->current;

        abort_unless($ticket, 404);

        $validated = validator($payload, [
            'hours' => ['required', 'numeric', 'min:0'],
            'rate' => ['required', 'numeric', 'min:0'],
            'billing_mode' => ['required', Rule::enum(BillingMode::class)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'target_on' => ['nullable', 'date'],
        ])->validate();

        $chargeable = BillingMode::from($validated['billing_mode']) === BillingMode::Chargeable;

        $ticket->update([
            'quoted_hours' => $validated['hours'],
            'quoted_rate' => $validated['rate'],
            'billing_mode' => $validated['billing_mode'],
            'priority' => $validated['priority'],
            'target_on' => $validated['target_on'],
            'quote_sent_at' => now(),
            'status' => $chargeable ? TicketStatus::QuoteSent : $ticket->status,
        ]);

        unset($this->tickets, $this->current, $this->stats);

        Flux::toast(variant: 'success', text: $chargeable
            ? __('Quote sent to :client.', ['client' => $ticket->team->name])
            : __('Time logged against :reference.', ['reference' => $ticket->reference]));
    }
}; ?>

<div wire:poll.15s>
    <div class="mb-[clamp(20px,2.4vw,30px)] flex flex-wrap items-end justify-between gap-5">
        <div>
            <x-rsc.kicker class="mb-2.5">{{ str(now()->format('l_j_F'))->lower() }}</x-rsc.kicker>
            <x-rsc.heading class="!text-[clamp(28px,4vw,46px)]">{{ __('Queue') }}</x-rsc.heading>
        </div>

        <div class="flex flex-wrap gap-x-[26px] gap-y-2.5 font-mono text-[11px] text-muted">
            @foreach ($this->stats as $stat)
                <span class="flex flex-col gap-1.5">
                    <span>{{ $stat['label'] }}</span>
                    <span class="font-display text-[22px] font-bold tracking-[-0.02em]" style="color: {{ $stat['colour'] }}">{{ $stat['value'] }}</span>
                </span>
            @endforeach
        </div>
    </div>

    <div class="mb-[18px] flex flex-wrap gap-2">
        <x-rsc.chip wire:click="$set('filter', 'all')" :active="$filter === 'all'">All</x-rsc.chip>
        @foreach (\App\Enums\TicketStatus::cases() as $status)
            <x-rsc.chip wire:click="$set('filter', '{{ $status->value }}')" :active="$filter === $status->value">{{ $status->label() }}</x-rsc.chip>
        @endforeach
    </div>

    <div class="grid items-start gap-[clamp(12px,1.4vw,18px)] lg:grid-cols-[minmax(320px,1fr)_minmax(340px,1.15fr)]">
        <div class="overflow-hidden rounded-[20px] border border-line bg-panel">
            @forelse ($this->tickets as $ticket)
                @php $selected = $this->current?->is($ticket); @endphp
                <button type="button" wire:click="select('{{ $ticket->reference }}')"
                        class="grid w-full cursor-pointer grid-cols-[1fr_auto] gap-x-3.5 gap-y-2 border-b border-line border-s-[3px] px-5 py-4 text-left transition-colors duration-200"
                        @style([
                            'background: color-mix(in srgb, var(--rsc-accent) 8%, transparent); border-left-color: var(--rsc-accent)' => $selected,
                            'background: transparent; border-left-color: transparent' => ! $selected,
                        ])>
                    <span class="font-mono text-[11px] text-muted">{{ $ticket->reference }} · {{ $ticket->team->name }}</span>
                    <x-rsc.pill :tone="$ticket->status->tone()" class="justify-self-end">{{ str($ticket->status->label())->lower() }}</x-rsc.pill>
                    <span class="col-span-full font-display text-base font-bold tracking-[-0.015em]">{{ $ticket->title }}</span>
                    <span class="text-xs text-muted">{{ $ticket->system }}</span>
                    <span class="justify-self-end font-mono text-[11px] {{ $ticket->priority->isPressing() ? 'text-warm' : 'text-muted' }}">{{ str($ticket->priority->label())->lower() }}</span>
                </button>
            @empty
                <div class="px-5 py-8 text-sm text-muted">{{ __('Nothing in the queue matches that filter.') }}</div>
            @endforelse

            <div class="px-5 py-3.5 font-mono text-[11px] text-muted">
                {{ __(':shown of :total tickets', ['shown' => $this->tickets->count(), 'total' => $this->totalCount]) }}
            </div>
        </div>

        @if ($ticket = $this->current)
            <div x-cloak wire:click="closeDetail"
                 x-bind:class="$wire.detailOpen ? 'opacity-100' : 'pointer-events-none opacity-0'"
                 class="fixed inset-0 z-40 bg-black/60 backdrop-blur-[2px] transition-opacity duration-200 lg:hidden"
                 aria-hidden="true"></div>

            <div x-bind:class="$wire.detailOpen ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'"
                 class="fixed inset-y-0 end-0 z-50 flex w-[min(460px,92vw)] flex-col gap-[clamp(12px,1.4vw,18px)] overflow-y-auto border-s border-line bg-panel p-5 transition-transform duration-300 ease-out lg:sticky lg:top-[86px] lg:z-auto lg:w-auto lg:overflow-visible lg:border-0 lg:bg-transparent lg:p-0 lg:transition-none"
                 x-data="{
                     hours: {{ $ticket->quoted_hours ?? 1 }},
                     rate: {{ $ticket->quoted_rate ?? $ticket->team->effectiveHourRate($this->settings) }},
                     billing: '{{ $ticket->billing_mode->value }}',
                     priority: '{{ $ticket->priority->value }}',
                     target: '{{ $ticket->target_on?->format('Y-m-d') }}',
                     get total() { return '£' + Math.round(this.hours * this.rate).toLocaleString('en-GB'); },
                 }">
                <button type="button" wire:click="closeDetail"
                        class="flex cursor-pointer items-center gap-2 self-start font-mono text-[11px] text-muted transition-colors hover:text-brand lg:hidden">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" class="size-4" aria-hidden="true">
                        <path d="M15 6l-6 6 6 6" />
                    </svg>
                    {{ __('back to the queue') }}
                </button>

                <x-rsc.panel>
                    <div class="mb-3.5 flex flex-wrap items-center gap-2.5 font-mono text-[11px] tracking-[0.08em] text-muted">
                        <span>{{ $ticket->reference }}</span>
                        <span>·</span>
                        <span>{{ $ticket->team->name }}</span>
                        <span class="ms-auto">{{ __('raised :when', ['when' => $ticket->created_at->format('j M, H:i')]) }}</span>
                    </div>

                    <x-rsc.heading :level="2" class="!text-[clamp(20px,2.2vw,28px)]">{{ $ticket->title }}</x-rsc.heading>
                    <p class="mt-3 text-sm leading-relaxed text-muted text-pretty">{{ $ticket->description }}</p>

                    <div class="mt-4 flex flex-wrap gap-x-[18px] gap-y-2 font-mono text-[11px] text-muted">
                        <span>{{ $ticket->system }}</span>
                        <span>{{ $ticket->page_url ?? '—' }}</span>
                        <span>{{ __('reported by :who', ['who' => $ticket->reporter?->name ?? __('unknown')]) }}</span>
                    </div>

                    <div class="mt-6 border-t border-line pt-[22px]">
                        <div class="mb-2.5 font-mono text-[11px] text-muted">status</div>
                        <div class="flex flex-wrap gap-2">
                            @foreach (\App\Enums\TicketStatus::cases() as $status)
                                <x-rsc.chip wire:click="setStatus('{{ $status->value }}')" :active="$ticket->status === $status" class="!text-[13px] !font-sans">
                                    {{ $status->label() }}
                                </x-rsc.chip>
                            @endforeach
                        </div>
                    </div>

                    <div class="mt-[22px] grid gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
                        <x-rsc.field label="target_date">
                            <x-rsc.input type="date" x-model="target" class="!py-3" />
                        </x-rsc.field>
                        <x-rsc.field label="priority">
                            <x-rsc.select x-model="priority" class="!py-3">
                                @foreach (\App\Enums\TicketPriority::cases() as $option)
                                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                                @endforeach
                            </x-rsc.select>
                        </x-rsc.field>
                    </div>
                </x-rsc.panel>

                <x-rsc.panel>
                    <div class="mb-[18px] flex items-center justify-between font-mono text-[11px] tracking-[0.08em] text-muted">
                        <span>time_quote</span>
                        <span>{{ $ticket->quote_sent_at ? __('sent :when', ['when' => $ticket->quote_sent_at->format('j M')]) : __('not sent') }}</span>
                    </div>

                    <div class="grid items-end gap-4 [grid-template-columns:repeat(auto-fit,minmax(120px,1fr))]">
                        <x-rsc.field label="hours">
                            <x-rsc.input type="number" min="0" step="0.25" x-model.number="hours" class="!py-3" />
                        </x-rsc.field>
                        <x-rsc.field label="rate_per_hour">
                            <x-rsc.input type="number" min="0" step="5" x-model.number="rate" class="!py-3" />
                        </x-rsc.field>
                        <div>
                            <div class="mb-2 font-mono text-[11px] text-muted">total_ex_vat</div>
                            <div class="font-display text-[clamp(24px,2.6vw,32px)] font-extrabold leading-tight tracking-[-0.03em]" x-text="total"></div>
                        </div>
                    </div>

                    <div class="mt-[18px] flex flex-wrap gap-2">
                        @foreach (\App\Enums\BillingMode::cases() as $mode)
                            <button type="button" x-on:click="billing = '{{ $mode->value }}'"
                                    class="cursor-pointer rounded-full border px-[15px] py-2.5 text-[13px] transition-all duration-200"
                                    x-bind:class="billing === '{{ $mode->value }}'
                                        ? 'border-brand text-brand rsc-tint'
                                        : 'border-line text-muted'">{{ $mode->label() }}</button>
                        @endforeach
                    </div>

                    <p class="mt-3.5 text-[13px] text-muted text-pretty">
                        @foreach (\App\Enums\BillingMode::cases() as $mode)
                            <span x-show="billing === '{{ $mode->value }}'" x-cloak>{{ $mode->hint() }}</span>
                        @endforeach
                    </p>

                    <div class="mt-5 flex flex-wrap gap-3">
                        <x-rsc.button
                            x-on:click="$wire.sendQuote({ hours, rate, billing_mode: billing, priority, target_on: target || null })"
                            class="!px-6 !py-3 !text-sm">
                            <span x-show="billing === 'chargeable'" x-cloak>{{ __('Send quote to client') }}</span>
                            <span x-show="billing !== 'chargeable'">{{ __('Log against the job') }}</span>
                        </x-rsc.button>
                    </div>
                </x-rsc.panel>

                <x-rsc.panel>
                    <div class="mb-4 font-mono text-[11px] tracking-[0.08em] text-muted">files</div>
                    <div class="flex flex-col gap-2.5">
                        @forelse ($ticket->attachments as $file)
                            <div class="grid grid-cols-[44px_1fr_auto] items-center gap-3.5 rounded-[14px] border border-line px-3.5 py-3">
                                <span class="grid h-[34px] place-items-center rounded-[9px] font-mono text-[10px] tracking-[0.04em]"
                                      @style([
                                          'color: var(--rsc-accent); background: color-mix(in srgb, var(--rsc-accent) 14%, transparent)' => $file->shared_with_client,
                                          'color: var(--rsc-muted); background: color-mix(in srgb, var(--rsc-text) 8%, transparent)' => ! $file->shared_with_client,
                                      ])>{{ $file->kind }}</span>
                                <span class="min-w-0">
                                    <span class="block font-display text-sm font-bold break-words">{{ $file->name }}</span>
                                    <span class="mt-[3px] block font-mono text-[11px] text-muted">{{ $file->metaLabel() }}</span>
                                </span>
                                <x-rsc.pill :tone="$file->shared_with_client ? 'brand' : 'muted'" class="!text-[10px] !tracking-[0.06em]">
                                    {{ $file->shared_with_client ? 'client' : 'internal' }}
                                </x-rsc.pill>
                            </div>
                        @empty
                            <p class="text-[13px] text-muted">{{ __('Nothing attached yet.') }}</p>
                        @endforelse
                    </div>

                    <label class="mt-3.5 flex cursor-pointer items-center gap-2.5 text-[13px] text-muted">
                        <input type="checkbox" wire:model="shareUploads" class="size-4" style="accent-color: var(--rsc-accent)">
                        <span>{{ __('Visible to the client as soon as it uploads') }}</span>
                    </label>
                </x-rsc.panel>

                <x-rsc.panel>
                    <div class="mb-4 flex items-center justify-between font-mono text-[11px] tracking-[0.08em] text-muted">
                        <span>conversation</span>
                        @if ($ticket->quote_response)
                            <span style="color: var(--rsc-{{ $ticket->quote_response->tone() === 'warm' ? 'warm' : 'accent' }})">
                                {{ __('quote :response', ['response' => str($ticket->quote_response->label())->lower()]) }}
                            </span>
                        @elseif ($ticket->hasQuoteAwaitingResponse())
                            <span class="text-warm">{{ __('awaiting approval') }}</span>
                        @endif
                    </div>

                    <x-rsc.ticket-thread :comments="$ticket->comments" show-internal />

                    <form wire:submit="postReply" class="mt-5">
                        <div class="mb-4 flex gap-1.5 rounded-full border border-line p-1">
                            @foreach ([['client', __('Reply to client')], ['internal', __('Internal note')]] as [$mode, $label])
                                <button type="button" wire:click="$set('replyMode', '{{ $mode }}')" @class([
                                    'flex-1 cursor-pointer rounded-full px-3.5 py-2.5 font-mono text-xs transition-colors duration-200',
                                    'bg-brand text-accent-ink' => $replyMode === $mode,
                                    'text-muted hover:text-body' => $replyMode !== $mode,
                                ])>{{ $label }}</button>
                            @endforeach
                        </div>

                        <x-rsc.field name="reply">
                            <x-rsc.textarea wire:model="reply" rows="4"
                                            placeholder="{{ $replyMode === 'internal'
                                                ? __('Only you will see this.')
                                                : __('This goes to the client and shows in their portal.') }}"
                                            @class(['!border-warm' => $replyMode === 'internal']) />
                        </x-rsc.field>

                        <div class="mt-3.5 flex flex-wrap items-center gap-3.5">
                            <x-rsc.button type="submit" class="!px-6 !py-3 !text-sm">
                                {{ $replyMode === 'internal' ? __('Save note') : __('Send reply') }}
                            </x-rsc.button>
                            <span class="text-xs text-muted">
                                {{ $replyMode === 'internal'
                                    ? __('Kept against the ticket, never shown to the client.')
                                    : __('They get an email as well as seeing it in the portal.') }}
                            </span>
                        </div>
                    </form>
                </x-rsc.panel>
            </div>
        @endif
    </div>
</div>
