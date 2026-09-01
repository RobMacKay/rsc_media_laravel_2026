<?php

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\StudioSetting;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('layouts::rsc.client')]
#[Title('Invoices')]
class extends Component {
    #[Url(as: 'show', except: 'all')]
    public string $filter = 'all';

    /**
     * Get the business the signed-in person is looking at.
     */
    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Get the payment terms that actually apply to this client, rather than
     * assuming the studio default.
     */
    #[Computed]
    public function paymentTerms(): int
    {
        return $this->team->effectivePaymentTerms(StudioSetting::current());
    }

    /**
     * Get every invoice raised against this client, newest first.
     *
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function allInvoices(): Collection
    {
        return $this->team->invoices()->get();
    }

    /**
     * Get the invoices matching the selected filter.
     *
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function invoices(): Collection
    {
        return match ($this->filter) {
            'due' => $this->allInvoices->filter(fn (Invoice $invoice) => $invoice->status->isOutstanding())->values(),
            'paid' => $this->allInvoices->filter(fn (Invoice $invoice) => ! $invoice->status->isOutstanding())->values(),
            default => $this->allInvoices,
        };
    }

    /**
     * Get the invoices still owed, soonest due first.
     *
     * @return Collection<int, Invoice>
     */
    #[Computed]
    public function outstanding(): Collection
    {
        return $this->allInvoices
            ->filter(fn (Invoice $invoice) => $invoice->status->isOutstanding())
            ->sortBy('due_on')
            ->values();
    }

    /**
     * Get the total still owed, including VAT.
     */
    #[Computed]
    public function outstandingTotal(): float
    {
        return $this->outstanding->sum(fn (Invoice $invoice) => $invoice->total());
    }

    /**
     * Get the total settled this year, including VAT.
     */
    #[Computed]
    public function paidThisYear(): float
    {
        return $this->allInvoices
            ->filter(fn (Invoice $invoice) => ! $invoice->status->isOutstanding() && $invoice->issued_on->isCurrentYear())
            ->sum(fn (Invoice $invoice) => $invoice->total());
    }
}; ?>

@php
    $money = fn (float $value) => $this->team->money($value);
@endphp

<div>
    <div class="mb-[clamp(22px,2.6vw,34px)] flex flex-wrap items-end justify-between gap-5">
        <div>
            <x-rsc.kicker class="mb-2.5">{{ str($this->team->name)->snake()->lower() }}</x-rsc.kicker>
            <x-rsc.heading>{{ __('Invoices') }}</x-rsc.heading>
            <p class="mt-3 text-[15px] text-muted">
                {{ $this->outstanding->isEmpty()
                    ? __('Nothing outstanding. Everything is settled.')
                    : __(':total outstanding across :count.', [
                        'total' => $money($this->outstandingTotal),
                        'count' => trans_choice('{1}one invoice|[2,*]:value invoices', $this->outstanding->count(), ['value' => $this->outstanding->count()]),
                    ]) }}
            </p>
        </div>

        <div class="flex flex-wrap gap-x-[26px] gap-y-2.5 font-mono text-[11px] text-muted">
            @foreach ([
                ['outstanding', $money($this->outstandingTotal), $this->outstanding->isNotEmpty() ? 'var(--rsc-warm)' : 'var(--rsc-text)'],
                ['next_due', $this->outstanding->first()?->due_on->format('j M') ?? '—', 'var(--rsc-text)'],
                ['paid_this_year', $money($this->paidThisYear), 'var(--rsc-accent)'],
            ] as [$label, $value, $colour])
                <span class="flex flex-col gap-1.5">
                    <span>{{ $label }}</span>
                    <span class="font-display text-[22px] font-bold tracking-[-0.02em]" style="color: {{ $colour }}">{{ $value }}</span>
                </span>
            @endforeach
        </div>
    </div>

    <div class="mb-[18px] flex flex-wrap gap-2">
        @foreach ([['all', 'All'], ['due', 'Due'], ['paid', 'Paid']] as [$value, $label])
            <x-rsc.chip wire:click="$set('filter', '{{ $value }}')" :active="$filter === $value">{{ $label }}</x-rsc.chip>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-[20px] border border-line bg-panel">
        <div class="overflow-x-auto [overscroll-behavior-x:contain]">
            <div class="min-w-[760px]">
                <div class="grid grid-cols-[110px_1fr_130px_110px_120px_110px] gap-4 border-b border-line px-[22px] py-3.5 font-mono text-[11px] tracking-[0.08em] text-muted">
                    <span>number</span><span>what for</span><span>issued</span><span>due</span><span>total</span><span>status</span>
                </div>

                @forelse ($this->invoices as $invoice)
                    @php $settled = ! $invoice->status->isOutstanding(); @endphp
                    <a href="{{ route('client.invoices.show', $invoice) }}" wire:navigate wire:key="invoice-{{ $invoice->id }}"
                       class="grid grid-cols-[110px_1fr_130px_110px_120px_110px] items-center gap-x-4 gap-y-2 border-b border-line px-[22px] py-4 text-body no-underline transition-colors hover:rsc-tint">
                        <span class="font-mono text-xs text-muted">{{ $invoice->number }}</span>
                        <span>
                            <span class="block font-display text-[15px] font-bold tracking-[-0.015em]">{{ $invoice->note }}</span>
                            <span class="mt-[3px] block text-xs text-muted">{{ $invoice->type->label() }}</span>
                        </span>
                        <span class="text-[13px] text-muted">{{ $invoice->issued_on->format('j M') }}</span>
                        <span class="text-[13px] {{ $settled ? 'text-muted' : 'text-warm' }}">{{ $settled ? '—' : $invoice->due_on->format('j M') }}</span>
                        <span class="font-display text-[15px] font-bold">{{ $invoice->money($invoice->total()) }}</span>
                        <span class="flex flex-col items-start gap-[7px]">
                            <x-rsc.pill :tone="$invoice->status->tone()">{{ str($invoice->status->label())->lower() }}</x-rsc.pill>
                        </span>
                    </a>
                @empty
                    <div class="px-[22px] py-8 text-sm text-muted">{{ __('No invoices match that filter.') }}</div>
                @endforelse
            </div>
        </div>

        <div class="flex flex-wrap gap-x-6 gap-y-2.5 px-[22px] py-4 font-mono text-[11px] text-muted">
            <span>{{ __(':shown of :total invoices', ['shown' => $this->invoices->count(), 'total' => $this->allInvoices->count()]) }}</span>
            <span class="ms-auto">{{ __(':total shown, inc VAT', ['total' => \App\Support\Money::total($this->invoices, fn (Invoice $invoice) => $invoice->total(), fn (Invoice $invoice) => $invoice->currency)]) }}</span>
        </div>
    </div>

    <div class="mt-[clamp(16px,2vw,24px)] grid gap-[clamp(12px,1.4vw,18px)] [grid-template-columns:repeat(auto-fit,minmax(260px,1fr))]">
        <div class="rounded-[18px] border border-line bg-panel px-[22px] py-5">
            <div class="mb-2.5 font-mono text-[11px] text-muted">how_to_pay</div>
            <div class="font-display text-base font-bold">{{ __('Bank transfer') }}</div>
            <div class="mt-1.5 text-[13px] text-muted">{{ __('Details are on every invoice. Use the reference shown.') }}</div>
        </div>
        <div class="rounded-[18px] border border-line bg-panel px-[22px] py-5">
            <div class="mb-2.5 font-mono text-[11px] text-muted">terms</div>
            <div class="font-display text-base font-bold">{{ __(':days days from issue', ['days' => $this->paymentTerms]) }}</div>
            <div class="mt-1.5 text-[13px] text-muted">{{ __('Plan invoices go out on the 1st of the month.') }}</div>
        </div>
        <div class="rounded-[18px] border border-line bg-panel px-[22px] py-5">
            <div class="mb-2.5 font-mono text-[11px] text-muted">query_an_invoice</div>
            <div class="font-display text-base font-bold">{{ __('Raise a ticket') }}</div>
            <a href="{{ route('client.tickets', ['raise' => 1]) }}" wire:navigate class="mt-1.5 block text-[13px] no-underline">
                {{ __('Question something on a bill →') }}
            </a>
        </div>
    </div>
</div>
