@php
    $awaitingSignOff = $proposal->status === \App\Enums\ProposalStatus::Sent;
@endphp

<section class="rounded-[20px] border bg-panel p-[clamp(22px,2.4vw,30px)] {{ $awaitingSignOff ? 'border-warm' : 'border-line' }}"
         wire:key="proposal-{{ $proposal->id }}">
    <div class="flex flex-wrap items-start justify-between gap-x-5 gap-y-3">
        <div>
            <div class="mb-2 font-mono text-[11px] tracking-[0.08em] text-muted">{{ $proposal->reference }}</div>
            <x-rsc.heading :level="2" class="!text-[clamp(20px,2.3vw,28px)]">{{ $proposal->title }}</x-rsc.heading>
            <p class="m-0 mt-2 max-w-[60ch] text-sm text-muted text-pretty">{{ $proposal->brief }}</p>
        </div>
        <x-rsc.pill :tone="$proposal->status->tone()" class="!px-3 !py-[5px]">{{ $proposal->status->clientLabel() }}</x-rsc.pill>
    </div>

    @if (! $awaitingSignOff)
        <div class="mt-[22px] flex flex-wrap gap-x-6 gap-y-2 border-t border-line pt-5 text-sm text-muted">
            <span>{{ __('Submitted :when', ['when' => $proposal->created_at->format('j F')]) }}</span>
            @if ($proposal->budget_guide)
                <span>{{ __('Budget guide :budget', ['budget' => $proposal->budget_guide]) }}</span>
            @endif
            @if ($proposal->needed_by)
                <span>{{ __('Wanted :when', ['when' => $proposal->needed_by]) }}</span>
            @endif
        </div>
    @else
        <div class="mt-6 grid gap-[clamp(20px,2.4vw,34px)] border-t border-line pt-[22px] [grid-template-columns:repeat(auto-fit,minmax(260px,1fr))]">
            <div>
                <div class="mb-3.5 font-mono text-[11px] tracking-[0.1em] text-muted">scope</div>
                @foreach ($proposal->scopeLines() as $line)
                    <div class="grid grid-cols-[auto_1fr] gap-2.5 py-[7px] text-sm leading-normal">
                        <span class="text-brand">—</span>
                        <span>{{ $line }}</span>
                    </div>
                @endforeach
                @if ($proposal->excluded)
                    <div class="mt-4 text-[13px] text-muted text-pretty">
                        {{ __('Not included: :excluded', ['excluded' => $proposal->excluded]) }}
                    </div>
                @endif
            </div>

            <div>
                <div class="mb-3.5 font-mono text-[11px] tracking-[0.1em] text-muted">phases</div>
                @foreach ($proposal->phaseRows() as $phase)
                    <div class="grid grid-cols-[1fr_auto] gap-x-3.5 gap-y-1 border-b border-line py-2.5">
                        <span class="font-display text-[15px] font-bold">{{ $phase['name'] }}</span>
                        <span class="font-mono text-[11px] whitespace-nowrap text-muted">{{ $phase['date'] }}</span>
                        <span class="col-span-full text-[13px] text-muted">{{ $phase['note'] }}</span>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-col gap-3.5">
                <div class="font-mono text-[11px] tracking-[0.1em] text-muted">price</div>
                <div class="font-display text-[clamp(30px,3.6vw,44px)] font-extrabold leading-none tracking-[-0.04em]">
                    £{{ number_format($proposal->price) }} + VAT
                </div>
                <div class="text-[13px] text-muted">
                    {{ __('Fixed price. :weeks weeks from sign-off.', ['weeks' => $proposal->weeks]) }}
                </div>

                <div class="flex flex-col gap-2.5 border-t border-line pt-3.5 text-[13px] text-muted">
                    <div class="flex justify-between gap-3.5">
                        <span>{{ __('Deposit on sign-off') }}</span>
                        <span class="text-body">£{{ number_format($proposal->deposit()) }} ({{ $proposal->deposit_percent }}%)</span>
                    </div>
                    <div class="flex justify-between gap-3.5">
                        <span>{{ __('Balance on go live') }}</span>
                        <span class="text-body">£{{ number_format($proposal->balance()) }}</span>
                    </div>
                    <div class="flex justify-between gap-3.5">
                        <span>{{ __('Payment terms') }}</span>
                        <span class="text-body">{{ __(':days days from invoice', ['days' => $paymentTerms]) }}</span>
                    </div>
                </div>

                @if (auth()->user()->accessFor()->canSeeBilling())
                    <div class="mt-1 flex flex-wrap gap-2.5">
                        <x-rsc.button wire:click="approve({{ $proposal->id }})"
                                      wire:confirm="{{ __('Sign this off? It starts the work and raises the deposit invoice.') }}"
                                      class="!px-6 !py-3 !text-[15px]">
                            {{ __('Approve and sign off') }}
                        </x-rsc.button>
                    </div>
                    <div class="text-xs text-muted">
                        {{ __('Signing off starts the work and raises the deposit invoice. Sent :when.', [
                            'when' => $proposal->sent_at?->format('j F'),
                        ]) }}
                    </div>
                @else
                    <div class="text-xs text-muted">
                        {{ __('Someone with billing access needs to sign this one off.') }}
                    </div>
                @endif
            </div>
        </div>
    @endif
</section>
