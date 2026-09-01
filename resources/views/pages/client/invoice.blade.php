<?php

use App\Models\Invoice;
use App\Models\StudioSetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new
#[Layout('layouts::rsc.client')]
class extends Component {
    public Invoice $invoice;

    /**
     * Load the invoice, refusing anything that is not this client's.
     */
    public function mount(Invoice $invoice): void
    {
        abort_unless($invoice->team_id === Auth::user()->current_team_id, 404);

        $this->invoice = $invoice->load(['team', 'project']);
    }

    /**
     * Get the studio's details, which supply the letterhead and bank details.
     */
    #[Computed]
    public function settings(): StudioSetting
    {
        return StudioSetting::current();
    }

    /**
     * Get the payment terms that apply to this client.
     */
    #[Computed]
    public function terms(): int
    {
        return $this->invoice->team->effectivePaymentTerms($this->settings);
    }

    /**
     * Get the page title, so the tab names the invoice.
     */
    public function title(): string
    {
        return $this->invoice->number;
    }
}; ?>

@php $money = fn (float $value) => $this->invoice->money($value, 2); @endphp

<div>
    <div class="mb-[clamp(20px,2.4vw,30px)] flex flex-wrap items-center justify-between gap-4">
        <a href="{{ route('client.invoices') }}" wire:navigate
           class="flex items-center gap-2 font-mono text-[11px] text-muted no-underline transition-colors hover:text-brand">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" class="size-4" aria-hidden="true">
                <path d="M15 6l-6 6 6 6" />
            </svg>
            {{ __('all invoices') }}
        </a>

        <x-rsc.button as="a" href="{{ route('client.invoices.pdf', $invoice) }}" class="!px-6 !py-3 !text-sm">
            {{ __('Download PDF') }}
        </x-rsc.button>
    </div>

    {{-- Mirrors the PDF in pdf/invoice, so what is on screen is what downloads. --}}
    <article class="rounded-[20px] border border-line bg-panel p-[clamp(22px,3.4vw,48px)]">
        <header class="flex flex-wrap items-start justify-between gap-6">
            <div>
                <x-rsc.logo class="h-[21px] w-auto" />
                <div class="mt-3 text-[13px] leading-relaxed text-muted">
                    @foreach ($this->settings->addressLines() as $line)
                        {{ $line }}<br>
                    @endforeach
                </div>
                <div class="mt-2.5 text-[13px] leading-relaxed text-muted">
                    @if ($this->settings->email){{ $this->settings->email }}<br>@endif
                    @if ($this->settings->phone){{ $this->settings->phone }}<br>@endif
                    @if ($this->settings->website){{ $this->settings->website }}@endif
                </div>
            </div>

            <div class="text-end">
                <div class="font-display text-[clamp(26px,3.4vw,34px)] font-extrabold tracking-[-0.03em]">{{ __('Invoice') }}</div>
                <div class="mt-0.5 font-display text-[15px] font-bold">{{ $invoice->number }}</div>
                <div class="mt-2.5">
                    <x-rsc.pill :tone="$invoice->status->isOutstanding() ? ($invoice->isOverdue() ? 'warm' : 'soft') : 'brand'">
                        {{ $invoice->status->isOutstanding() ? ($invoice->isOverdue() ? __('overdue') : __('due')) : __('paid') }}
                    </x-rsc.pill>
                </div>
            </div>
        </header>

        <div class="my-6 border-b-2 border-line"></div>

        <div class="grid gap-6 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
            <div>
                <div class="mb-1.5 font-mono text-[10px] tracking-[0.14em] text-muted uppercase">{{ __('Billed to') }}</div>
                <div class="font-display text-[15px] font-bold">{{ $invoice->team->name }}</div>
                @if ($invoice->team->billing_email)
                    <div class="text-[13px] text-muted">{{ $invoice->team->billing_email }}</div>
                @endif
                @if ($invoice->team->purchase_order_ref)
                    <div class="mt-1.5 text-[13px] text-muted">{{ __('PO :ref', ['ref' => $invoice->team->purchase_order_ref]) }}</div>
                @endif
            </div>

            <div>
                <div class="mb-1.5 font-mono text-[10px] tracking-[0.14em] text-muted uppercase">{{ __('Issued') }}</div>
                <div class="text-[15px]">{{ $invoice->issued_on->format('j F Y') }}</div>
                <div class="mt-3 mb-1.5 font-mono text-[10px] tracking-[0.14em] text-muted uppercase">{{ __('Due') }}</div>
                <div @class(['text-[15px]', 'font-bold text-warm' => $invoice->isOverdue()])>{{ $invoice->due_on->format('j F Y') }}</div>
            </div>

            <div>
                <div class="mb-1.5 font-mono text-[10px] tracking-[0.14em] text-muted uppercase">{{ __('Payment reference') }}</div>
                <div class="font-display text-[15px] font-bold">{{ $invoice->paymentReference($this->settings) }}</div>
                @if ($invoice->project)
                    <div class="mt-3 mb-1.5 font-mono text-[10px] tracking-[0.14em] text-muted uppercase">{{ __('Project') }}</div>
                    <div class="text-[15px]">{{ $invoice->project->reference ?? $invoice->project->title }}</div>
                @endif
            </div>
        </div>

        <div class="mt-8 overflow-x-auto">
            <table class="w-full min-w-[420px] border-collapse text-[15px]">
                <thead>
                    <tr class="border-b-2 border-line">
                        @foreach ([__('Description'), __('Qty'), __('Unit'), __('Amount')] as $index => $heading)
                            <th @class([
                                'pb-2 font-mono text-[10px] font-normal tracking-[0.14em] text-muted uppercase',
                                'text-left' => $index === 0,
                                'text-right' => $index > 0,
                            ])>{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b border-line">
                        <td class="py-3.5">
                            <span class="block font-display font-bold">{{ $invoice->note }}</span>
                            <span class="block text-[13px] text-muted">{{ $invoice->type->label() }}</span>
                        </td>
                        <td class="py-3.5 text-right">1</td>
                        <td class="py-3.5 text-right">{{ $money($invoice->amount) }}</td>
                        <td class="py-3.5 text-right">{{ $money($invoice->amount) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-5 flex justify-end">
            <table class="w-full max-w-[320px] border-collapse text-[15px]">
                <tr>
                    <td class="py-1.5 text-muted">{{ __('Subtotal') }}</td>
                    <td class="py-1.5 text-right">{{ $money($invoice->amount) }}</td>
                </tr>
                <tr>
                    <td class="py-1.5 text-muted">
                        {{ __('VAT') }}
                        @if ($invoice->vat_rate > 0)
                            {{ __('at :rate%', ['rate' => rtrim(rtrim(number_format($invoice->vat_rate, 1), '0'), '.')]) }}
                        @endif
                    </td>
                    <td class="py-1.5 text-right">{{ $money($invoice->vatAmount()) }}</td>
                </tr>
                <tr class="border-t-2 border-line">
                    <td class="pt-2.5 font-display text-[18px] font-bold">{{ __('Total due') }}</td>
                    <td class="pt-2.5 text-right font-display text-[18px] font-bold">{{ $money($invoice->total()) }}</td>
                </tr>
            </table>
        </div>

        <div class="mt-8 grid gap-6 [grid-template-columns:repeat(auto-fit,minmax(260px,1fr))]">
            <div class="rounded-2xl border border-line bg-ink px-[18px] py-4">
                <div class="mb-1.5 font-mono text-[10px] tracking-[0.14em] text-muted uppercase">{{ __('How to pay') }}</div>
                <div class="font-display text-[15px] font-bold">{{ __('Bank transfer') }}</div>
                <table class="mt-2.5 w-full text-[13px]">
                    @foreach ([
                        __('Account name') => $this->settings->account_name,
                        __('Bank') => $this->settings->bank_name,
                        __('Sort code') => $this->settings->sort_code,
                        __('Account number') => $this->settings->account_number,
                    ] as $label => $value)
                        <tr>
                            <td class="py-0.5 text-muted">{{ $label }}</td>
                            <td class="py-0.5 font-mono">{{ $value }}</td>
                        </tr>
                    @endforeach
                    <tr>
                        <td class="py-0.5 text-muted">{{ __('Reference') }}</td>
                        <td class="py-0.5 font-mono font-bold">{{ $invoice->paymentReference($this->settings) }}</td>
                    </tr>
                </table>
            </div>

            <div>
                <div class="mb-1.5 font-mono text-[10px] tracking-[0.14em] text-muted uppercase">{{ __('Terms') }}</div>
                <p class="m-0 text-[15px] leading-relaxed text-pretty">
                    {{ __('Payment due within :days days of issue.', ['days' => $this->terms]) }}
                    @if ($this->settings->late_fee_percent > 0)
                        {{ __('Late payments may carry interest at :rate% per month.', [
                            'rate' => rtrim(rtrim(number_format($this->settings->late_fee_percent, 1), '0'), '.'),
                        ]) }}
                    @endif
                </p>
                @unless ($this->settings->vat_registered)
                    <p class="mt-2.5 mb-0 text-[13px] text-muted">{{ __('No VAT is charged on this invoice.') }}</p>
                @endunless
                @if (! $invoice->status->isOutstanding() && $invoice->paid_at)
                    <p class="mt-2.5 mb-0 text-[15px]">{{ __('Paid :when — thank you.', ['when' => $invoice->paid_at->format('j F Y')]) }}</p>
                @endif
            </div>
        </div>

        <footer class="mt-8 flex flex-wrap justify-between gap-3 border-t border-line pt-4 font-mono text-[11px] text-muted">
            <span>
                {{ $this->settings->company_name }}
                @if ($this->settings->company_number) · {{ __('Registered in Scotland :number', ['number' => $this->settings->company_number]) }}@endif
                @if ($this->settings->vat_registered && $this->settings->vat_number) · {{ __('VAT :number', ['number' => $this->settings->vat_number]) }}@endif
            </span>
            <span>{{ $invoice->number }}</span>
        </footer>
    </article>
</div>
