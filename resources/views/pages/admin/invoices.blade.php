<?php

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Project;
use App\Models\StudioSetting;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Carbon\CarbonInterface;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('layouts::rsc.admin')]
#[Title('Invoices')]
class extends Component {
    #[Url(as: 'filter', except: 'all')]
    public string $filter = 'all';

    public bool $formOpen = false;

    public ?string $raisedNumber = null;

    public string $type = 'deposit';

    public ?int $teamId = null;

    public ?int $projectId = null;

    public int $amount = 1000;

    public string $note = '';

    /**
     * Get the studio's defaults, which supply VAT and payment terms.
     */
    #[Computed]
    public function settings(): StudioSetting
    {
        return StudioSetting::current();
    }

    /**
     * Get every invoice raised by the studio.
     *
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function allInvoices(): Collection
    {
        return Invoice::query()->with(['team', 'project'])->latest('issued_on')->get();
    }

    /**
     * Get the invoices matching the selected status filter.
     *
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function invoices(): Collection
    {
        return $this->filter === 'all'
            ? $this->allInvoices
            : $this->allInvoices->where('status', InvoiceStatus::from($this->filter))->values();
    }

    /**
     * Get the money tiles across the top of the screen.
     *
     * @return array<int, array{label: string, note: string, value: string, sub: string, colour: string, noteColour: string, line: string}>
     */
    #[Computed]
    public function money(): array
    {
        $overdue = $this->allInvoices->where('status', InvoiceStatus::Overdue);
        $sent = $this->allInvoices->where('status', InvoiceStatus::Sent);
        $draft = $this->allInvoices->where('status', InvoiceStatus::Draft);
        $paid = $this->allInvoices->where('status', InvoiceStatus::Paid);

        $sum = fn (Collection $rows) => '£'.number_format(round($rows->sum(fn (Invoice $i) => $i->total())));

        return [
            [
                'label' => 'overdue', 'note' => $overdue->isNotEmpty() ? 'chase' : 'clear',
                'value' => $sum($overdue), 'sub' => trans_choice('{0}Nothing late|{1}1 invoice past its date|[2,*]:count invoices past their date', $overdue->count(), ['count' => $overdue->count()]),
                'colour' => 'var(--rsc-warm)', 'noteColour' => 'var(--rsc-warm)',
                'line' => $overdue->isNotEmpty() ? 'color-mix(in srgb, var(--rsc-warm) 45%, transparent)' : 'var(--rsc-line)',
            ],
            [
                'label' => 'awaiting_payment', 'note' => 'sent', 'value' => $sum($sent),
                'sub' => trans_choice('{0}Nothing outstanding|{1}1 invoice with the client|[2,*]:count invoices with clients', $sent->count(), ['count' => $sent->count()]),
                'colour' => 'var(--rsc-text)', 'noteColour' => 'var(--rsc-muted)', 'line' => 'var(--rsc-line)',
            ],
            [
                'label' => 'in_draft', 'note' => 'unsent', 'value' => $sum($draft),
                'sub' => trans_choice('{0}Nothing waiting|{1}1 invoice to send|[2,*]:count invoices to send', $draft->count(), ['count' => $draft->count()]),
                'colour' => 'var(--rsc-text)', 'noteColour' => 'var(--rsc-muted)', 'line' => 'var(--rsc-line)',
            ],
            [
                'label' => 'paid_this_year', 'note' => now()->format('Y'), 'value' => $sum($paid->filter(fn (Invoice $i) => $i->issued_on->isCurrentYear())),
                'sub' => __('Settled and banked'), 'colour' => 'var(--rsc-accent)', 'noteColour' => 'var(--rsc-muted)', 'line' => 'var(--rsc-line)',
            ],
        ];
    }

    /**
     * Get what has been collected this month against what was invoiced.
     *
     * @return array{taken: float, expected: float, percent: float}
     */
    #[Computed]
    public function collected(): array
    {
        $thisMonth = $this->allInvoices->filter(fn (Invoice $i) => $i->issued_on->isSameMonth(now()));

        $expected = $thisMonth->sum(fn (Invoice $i) => $i->total());
        $taken = $thisMonth->where('status', InvoiceStatus::Paid)->sum(fn (Invoice $i) => $i->total());

        return [
            'taken' => $taken,
            'expected' => $expected,
            'percent' => $expected > 0 ? min(100, $taken / $expected * 100) : 0,
        ];
    }

    /**
     * Get the last six months of settled invoices, for the bar chart.
     *
     * @return array<int, array{label: string, height: string, fill: string}>
     */
    #[Computed]
    public function months(): array
    {
        $months = collect(range(5, 0))->map(fn (int $back) => now()->subMonths($back)->startOfMonth());

        $totals = $months->map(fn (CarbonInterface $month) => $this->allInvoices
            ->filter(fn (Invoice $i) => $i->status === InvoiceStatus::Paid && $i->issued_on->isSameMonth($month))
            ->sum(fn (Invoice $i) => $i->total()));

        $peak = max($totals->max() ?: 1, 1);

        return $months->map(fn (CarbonInterface $month, int $index) => [
            'label' => str($month->format('M'))->lower()->toString(),
            'height' => max(4, round($totals[$index] / $peak * 100)).'%',
            'fill' => $month->isSameMonth(now()) ? 'var(--rsc-accent)' : 'color-mix(in srgb, var(--rsc-accent) 32%, transparent)',
        ])->all();
    }

    /**
     * Get the recurring monthly revenue from clients on a plan.
     *
     * @return array{total: int, clients: int, mix: array<int, array{name: string, count: int, total: string}>}
     */
    #[Computed]
    public function recurring(): array
    {
        $plans = Plan::query()->offered()->withCount('teams')->get();

        return [
            'total' => (int) $plans->sum(fn (Plan $plan) => $plan->price * $plan->teams_count),
            'clients' => (int) $plans->sum('teams_count'),
            'mix' => $plans->map(fn (Plan $plan) => [
                'name' => $plan->name,
                'count' => $plan->teams_count,
                'total' => '£'.number_format($plan->price * $plan->teams_count),
            ])->all(),
        ];
    }

    /**
     * Get the clients an invoice can be raised against.
     *
     * @return Collection<int, Team>
     */
    #[Computed]
    public function clients(): Collection
    {
        return Team::query()->where('is_personal', false)->orderBy('name')->get();
    }

    /**
     * Get the jobs belonging to the client selected in the form.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function jobs(): Collection
    {
        return $this->teamId
            ? Project::query()->where('team_id', $this->teamId)->orderBy('title')->get()
            : new Collection;
    }

    /**
     * Reset the job selection whenever the client changes.
     */
    public function updatedTeamId(): void
    {
        $this->projectId = null;
        unset($this->jobs);
    }

    /**
     * Open or close the new invoice form.
     */
    public function toggleForm(): void
    {
        $this->formOpen = ! $this->formOpen;
        $this->raisedNumber = null;
        $this->teamId ??= $this->clients->first()?->id;
    }

    /**
     * Close the form and clear the confirmation.
     */
    public function closeForm(): void
    {
        $this->formOpen = false;
        $this->raisedNumber = null;
    }

    /**
     * Mark an invoice as settled.
     */
    public function markPaid(int $invoiceId): void
    {
        Invoice::findOrFail($invoiceId)->update([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now(),
        ]);

        unset($this->allInvoices, $this->invoices, $this->money, $this->collected, $this->months);

        Flux::toast(variant: 'success', text: __('Marked as paid.'));
    }

    /**
     * Raise a one-off invoice against a client.
     */
    public function save(): void
    {
        $validated = $this->validate([
            'type' => ['required', Rule::enum(InvoiceType::class)],
            'teamId' => ['required', 'exists:teams,id'],
            'projectId' => ['nullable', 'exists:projects,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:255'],
        ]);

        $team = Team::findOrFail($validated['teamId']);
        $settings = $this->settings;

        $invoice = Invoice::create([
            'number' => Invoice::nextNumber(),
            'team_id' => $team->id,
            'project_id' => $validated['projectId'],
            'type' => $validated['type'],
            'note' => $validated['note'],
            'amount' => $validated['amount'],
            'vat_rate' => $settings->effectiveVatRate(),
            'issued_on' => now(),
            'due_on' => now()->addDays($team->effectivePaymentTerms($settings)),
            'status' => InvoiceStatus::Sent,
        ]);

        $this->reset('note');
        $this->raisedNumber = $invoice->number;

        unset($this->allInvoices, $this->invoices, $this->money, $this->collected, $this->months);

        Flux::toast(variant: 'success', text: __('Invoice :number raised.', ['number' => $invoice->number]));
    }
}; ?>

@php
    $money = fn (float $value) => '£'.number_format(round($value));
@endphp

<div>
    <div class="mb-[clamp(20px,2.4vw,30px)] flex flex-wrap items-end justify-between gap-5">
        <div>
            <x-rsc.kicker class="mb-2.5">{{ str(now()->format('F_Y'))->lower() }}</x-rsc.kicker>
            <x-rsc.heading class="!text-[clamp(28px,4vw,46px)]">{{ __('Invoices') }}</x-rsc.heading>
        </div>

        <x-rsc.button wire:click="toggleForm" class="px-[26px] py-3.5">
            {{ $formOpen ? __('Close form') : __('New invoice') }}
        </x-rsc.button>
    </div>

    <div class="mb-[clamp(12px,1.4vw,18px)] grid gap-[clamp(12px,1.4vw,18px)] [grid-template-columns:repeat(auto-fit,minmax(210px,1fr))]">
        @foreach ($this->money as $tile)
            <div class="rounded-[18px] border bg-panel px-[22px] py-5" style="border-color: {{ $tile['line'] }}">
                <div class="mb-3 flex justify-between gap-2.5 font-mono text-[11px] tracking-[0.08em] text-muted">
                    <span>{{ $tile['label'] }}</span>
                    <span style="color: {{ $tile['noteColour'] }}">{{ $tile['note'] }}</span>
                </div>
                <div class="font-display text-[clamp(24px,2.8vw,34px)] font-extrabold leading-none tracking-[-0.035em]" style="color: {{ $tile['colour'] }}">{{ $tile['value'] }}</div>
                <div class="mt-1.5 text-[13px] text-muted">{{ $tile['sub'] }}</div>
            </div>
        @endforeach
    </div>

    <div class="mb-[clamp(12px,1.4vw,18px)] grid gap-[clamp(12px,1.4vw,18px)] [grid-template-columns:repeat(auto-fit,minmax(300px,1fr))]">
        <x-rsc.panel>
            <div class="mb-[18px] flex items-center justify-between font-mono text-[11px] tracking-[0.08em] text-muted">
                <span>collected_this_month</span>
                <span>{{ round($this->collected['percent']) }}%</span>
            </div>
            <div class="flex items-baseline gap-2.5">
                <span class="font-display text-[clamp(28px,3.2vw,40px)] font-extrabold leading-none tracking-[-0.035em]">{{ $money($this->collected['taken']) }}</span>
                <span class="text-[13px] text-muted">{{ __('of :total expected', ['total' => $money($this->collected['expected'])]) }}</span>
            </div>
            <x-rsc.meter :percent="$this->collected['percent']" class="mt-4" />

            {{-- The bar row keeps a fixed height so each bar's percentage has something to resolve against. --}}
            <div class="mt-6">
                <div class="flex h-[70px] items-end gap-2">
                    @foreach ($this->months as $month)
                        <span class="flex-1 rounded-t-[5px]" style="height: {{ $month['height'] }}; background: {{ $month['fill'] }}"></span>
                    @endforeach
                </div>
                <div class="mt-2 flex gap-2">
                    @foreach ($this->months as $month)
                        <span class="flex-1 text-center font-mono text-[10px] text-muted">{{ $month['label'] }}</span>
                    @endforeach
                </div>
            </div>
        </x-rsc.panel>

        <x-rsc.panel>
            <div class="mb-[18px] font-mono text-[11px] tracking-[0.08em] text-muted">recurring_from_plans</div>
            <div class="flex items-baseline gap-2.5">
                <span class="font-display text-[clamp(28px,3.2vw,40px)] font-extrabold leading-none tracking-[-0.035em]">£{{ number_format($this->recurring['total']) }}</span>
                <span class="text-[13px] text-muted">{{ __('a month across :count clients', ['count' => $this->recurring['clients']]) }}</span>
            </div>

            <div class="mt-[18px] flex flex-col gap-0.5">
                @foreach ($this->recurring['mix'] as $row)
                    <div class="grid grid-cols-[1fr_auto_auto] items-center gap-3.5 border-t border-line py-3">
                        <span class="font-display text-[15px] font-bold">{{ $row['name'] }}</span>
                        <span class="font-mono text-[11px] text-muted">{{ trans_choice('{0}no clients|{1}1 client|[2,*]:count clients', $row['count'], ['count' => $row['count']]) }}</span>
                        <span class="font-mono text-xs">{{ $row['total'] }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 border-t border-line pt-3.5 font-mono text-[11px] text-muted">
                {{ __('// plan invoices go out automatically on the 1st') }}
            </div>
        </x-rsc.panel>
    </div>

    @if ($formOpen)
        <x-rsc.panel accent class="mb-[clamp(12px,1.4vw,18px)] animate-rsc-fade !p-[clamp(20px,2.6vw,32px)]">
            @if ($raisedNumber)
                <div class="flex flex-col gap-3.5 py-3.5">
                    <x-rsc.kicker>invoice_sent</x-rsc.kicker>
                    <div class="font-display text-[clamp(22px,2.6vw,32px)] font-extrabold tracking-[-0.03em]">
                        {{ __(':number is with the client.', ['number' => $raisedNumber]) }}
                    </div>
                    <p class="m-0 max-w-[56ch] text-[15px] text-muted text-pretty">
                        {{ __('Terms, VAT and bank details came from your settings. It shows in their portal straight away.') }}
                    </p>
                    <x-rsc.button variant="outline" wire:click="closeForm" class="self-start !px-[22px] !py-2.5 !text-sm">
                        {{ __('Back to invoices') }}
                    </x-rsc.button>
                </div>
            @else
                <form wire:submit="save" class="flex flex-col gap-5">
                    <x-rsc.kicker tone="muted">one_off_invoice</x-rsc.kicker>

                    <div>
                        <span class="mb-2 block font-mono text-[11px] text-muted">type</span>
                        <div class="flex flex-wrap gap-2">
                            @foreach (\App\Enums\InvoiceType::cases() as $option)
                                <x-rsc.chip wire:click="$set('type', '{{ $option->value }}')" :active="$type === $option->value" class="!text-[13px] !font-sans">
                                    {{ $option->label() }}
                                </x-rsc.chip>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(190px,1fr))]">
                        <x-rsc.field label="client" name="teamId">
                            <x-rsc.select wire:model.live="teamId" class="!py-3">
                                @foreach ($this->clients as $client)
                                    <option value="{{ $client->id }}">{{ $client->name }}</option>
                                @endforeach
                            </x-rsc.select>
                        </x-rsc.field>

                        <x-rsc.field label="against_job" name="projectId">
                            <x-rsc.select wire:model="projectId" class="!py-3">
                                <option value="">{{ __('Not against a job') }}</option>
                                @foreach ($this->jobs as $job)
                                    <option value="{{ $job->id }}">{{ $job->title }}</option>
                                @endforeach
                            </x-rsc.select>
                        </x-rsc.field>

                        <x-rsc.field label="amount_ex_vat_£" name="amount">
                            <x-rsc.input type="number" min="0" step="10" wire:model="amount" class="!py-3" />
                        </x-rsc.field>
                    </div>

                    <x-rsc.field label="description_on_invoice" name="note">
                        <x-rsc.input wire:model="note" placeholder="{{ __('Deposit — 40% of agreed fee') }}" class="!py-3" />
                    </x-rsc.field>

                    <div class="flex flex-wrap gap-x-7 gap-y-2.5 rounded-[14px] border border-line px-[18px] py-4">
                        @php
                            $client = $this->clients->firstWhere('id', $teamId);
                            $vat = $this->settings->effectiveVatRate();
                            $terms = $client?->effectivePaymentTerms($this->settings) ?? $this->settings->payment_terms_days;
                        @endphp
                        @foreach ([
                            ['ex_vat', '£'.number_format($amount)],
                            ['vat_at_'.rtrim(rtrim(number_format($vat, 1), '0'), '.').'%', '£'.number_format(round($amount * $vat / 100))],
                            ['total', '£'.number_format(round($amount * (1 + $vat / 100)))],
                            ['due', now()->addDays($terms)->format('j M')],
                        ] as [$label, $value])
                            <span class="flex flex-col gap-1.5">
                                <span class="font-mono text-[11px] text-muted">{{ $label }}</span>
                                <span class="font-display text-base font-bold">{{ $value }}</span>
                            </span>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap items-center gap-4">
                        <x-rsc.button type="submit" class="px-[30px] py-3.5">{{ __('Generate and send') }}</x-rsc.button>
                        <span class="text-[13px] text-muted">{{ __('Terms, VAT and bank details come from settings.') }}</span>
                    </div>
                </form>
            @endif
        </x-rsc.panel>
    @endif

    <div class="mb-[18px] flex flex-wrap gap-2">
        <x-rsc.chip wire:click="$set('filter', 'all')" :active="$filter === 'all'">All</x-rsc.chip>
        @foreach (\App\Enums\InvoiceStatus::cases() as $status)
            <x-rsc.chip wire:click="$set('filter', '{{ $status->value }}')" :active="$filter === $status->value">{{ $status->label() }}</x-rsc.chip>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-[20px] border border-line bg-panel">
        <div class="overflow-x-auto [overscroll-behavior-x:contain]">
            <div class="min-w-[820px]">
                <div class="grid grid-cols-[110px_1fr_150px_120px_110px_120px] gap-4 border-b border-line px-[22px] py-3.5 font-mono text-[11px] tracking-[0.08em] text-muted">
                    <span>number</span><span>client</span><span>type</span><span>due</span><span>total</span><span>status</span>
                </div>

                @forelse ($this->invoices as $invoice)
                    <div class="grid grid-cols-[110px_1fr_150px_120px_110px_120px] items-center gap-x-4 gap-y-2 border-b border-line px-[22px] py-4" wire:key="invoice-{{ $invoice->id }}">
                        <span class="font-mono text-xs text-muted">{{ $invoice->number }}</span>
                        <span>
                            <span class="block font-display text-[15px] font-bold tracking-[-0.015em]">{{ $invoice->team->name }}</span>
                            <span class="mt-[3px] block text-xs text-muted">{{ $invoice->note }}</span>
                        </span>
                        <span class="font-mono text-[11px] {{ $invoice->type === \App\Enums\InvoiceType::Plan ? 'text-brand' : 'text-muted' }}">{{ $invoice->type->label() }}</span>
                        <span class="text-[13px] {{ $invoice->status === \App\Enums\InvoiceStatus::Overdue ? 'text-warm' : 'text-muted' }}">{{ $invoice->due_on->format('j M') }}</span>
                        <span class="font-display text-[15px] font-bold">{{ $money($invoice->total()) }}</span>
                        <span class="flex items-center gap-2.5">
                            <x-rsc.pill :tone="$invoice->status->tone()">{{ str($invoice->status->label())->lower() }}</x-rsc.pill>
                            @if ($invoice->status->isOutstanding())
                                <button type="button" wire:click="markPaid({{ $invoice->id }})" class="cursor-pointer font-mono text-[10px] text-brand">{{ __('mark paid') }}</button>
                            @endif
                        </span>
                    </div>
                @empty
                    <div class="px-[22px] py-8 text-sm text-muted">{{ __('No invoices match that filter.') }}</div>
                @endforelse
            </div>
        </div>

        <div class="flex flex-wrap gap-x-6 gap-y-2.5 px-[22px] py-4 font-mono text-[11px] text-muted">
            <span>{{ __(':shown of :total invoices', ['shown' => $this->invoices->count(), 'total' => $this->allInvoices->count()]) }}</span>
            <span class="ms-auto">{{ __(':total shown, inc VAT', ['total' => $money($this->invoices->sum(fn ($invoice) => $invoice->total()))]) }}</span>
        </div>
    </div>
</div>
