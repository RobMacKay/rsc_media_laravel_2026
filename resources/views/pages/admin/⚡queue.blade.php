<?php

use App\Enums\BillingMode;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\StudioSetting;
use App\Models\Ticket;
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
            ->with(['team', 'reporter'])
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
     * Select a ticket for the detail pane.
     */
    public function select(string $reference): void
    {
        $this->selectedReference = $reference;
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

<div>
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
            <div class="flex flex-col gap-[clamp(12px,1.4vw,18px)] lg:sticky lg:top-[86px]"
                 x-data="{
                     hours: {{ $ticket->quoted_hours ?? 1 }},
                     rate: {{ $ticket->quoted_rate ?? $ticket->team->effectiveHourRate($this->settings) }},
                     billing: '{{ $ticket->billing_mode->value }}',
                     priority: '{{ $ticket->priority->value }}',
                     target: '{{ $ticket->target_on?->format('Y-m-d') }}',
                     get total() { return '£' + Math.round(this.hours * this.rate).toLocaleString('en-GB'); },
                 }">
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
            </div>
        @endif
    </div>
</div>
