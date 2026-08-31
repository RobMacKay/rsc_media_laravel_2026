<?php

use App\Enums\ProposalStatus;
use App\Models\Proposal;
use App\Models\StudioSetting;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('layouts::rsc.admin')]
#[Title('Proposals')]
class extends Component {
    #[Url(as: 'proposal', except: null)]
    public ?string $selectedReference = null;

    /** Whether the write-up panel is showing; only meaningful below `lg`. */
    public bool $detailOpen = false;

    public string $scope = '';

    public string $phases = '';

    public string $excluded = '';

    public int $price = 0;

    public int $depositPercent = 40;

    public int $weeks = 4;

    /**
     * Load the first proposal's draft so the panel is never empty on desktop.
     */
    public function mount(): void
    {
        $this->fillDraftFrom($this->current);
    }

    /**
     * Get every proposal from every client, the ones needing writing up first.
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function proposals(): Collection
    {
        return Proposal::query()
            ->with(['team', 'requester'])
            ->whereIn('status', [ProposalStatus::Submitted, ProposalStatus::Sent])
            ->orderByRaw("CASE status WHEN 'submitted' THEN 0 ELSE 1 END")
            ->latest()
            ->get();
    }

    /**
     * Get the proposal being written up.
     */
    #[Computed]
    public function current(): ?Proposal
    {
        $proposals = $this->proposals;

        return $proposals->firstWhere('reference', $this->selectedReference) ?? $proposals->first();
    }

    /**
     * Get the studio's defaults, which supply VAT and payment terms.
     */
    #[Computed]
    public function settings(): StudioSetting
    {
        return StudioSetting::current();
    }

    /**
     * Get how many are still waiting to be written up.
     */
    #[Computed]
    public function waitingLabel(): string
    {
        $waiting = $this->proposals->where('status', ProposalStatus::Submitted)->count();

        return trans_choice(
            '{0}nothing waiting to be written up|{1}one waiting to be written up|[2,*]:count waiting to be written up',
            $waiting,
            ['count' => $waiting],
        );
    }

    /**
     * Get the money the current draft works out at.
     *
     * @return array{deposit: float, balance: float, total: float, terms: int}
     */
    #[Computed]
    public function totals(): array
    {
        $deposit = $this->price * $this->depositPercent / 100;
        $vat = $this->settings->effectiveVatRate();

        return [
            'deposit' => $deposit,
            'balance' => $this->price - $deposit,
            'total' => $this->price * (1 + $vat / 100),
            'terms' => $this->current?->team->effectivePaymentTerms($this->settings) ?? $this->settings->payment_terms_days,
        ];
    }

    /**
     * Determine whether the draft has enough in it to go out.
     */
    #[Computed]
    public function readyToSend(): bool
    {
        return $this->price > 0 && trim($this->scope) !== '';
    }

    /**
     * Switch to a different proposal, sliding the panel in on small screens.
     */
    public function select(string $reference): void
    {
        $this->selectedReference = $reference;
        $this->detailOpen = true;

        unset($this->current, $this->totals);

        $this->fillDraftFrom($this->current);
    }

    /**
     * Dismiss the write-up panel on small screens.
     */
    public function closeDetail(): void
    {
        $this->detailOpen = false;
    }

    /**
     * Save the draft without sending it.
     */
    public function saveDraft(): void
    {
        $this->persist();

        Flux::toast(variant: 'success', text: __('Draft saved.'));
    }

    /**
     * Send the written proposal to the client for sign-off.
     */
    public function send(): void
    {
        $proposal = $this->current;

        abort_unless($proposal, 404);

        $this->validate([
            'scope' => ['required', 'string'],
            'price' => ['required', 'integer', 'min:1'],
        ], attributes: ['scope' => __('scope'), 'price' => __('price')]);

        $this->persist([
            'status' => ProposalStatus::Sent,
            'sent_at' => now(),
        ]);

        Flux::toast(variant: 'success', text: __('Sent to :contact for sign-off.', [
            'contact' => $proposal->contact ?? $proposal->team->name,
        ]));
    }

    /**
     * Write the draft back to the selected proposal.
     *
     * @param  array<string, mixed>  $extra
     */
    private function persist(array $extra = []): void
    {
        $proposal = $this->current;

        abort_unless($proposal, 404);

        // Pin the selection to what is being written. Sending changes the sort
        // order, and without this `current` would fall back to whatever floats
        // to the top next, leaving the draft pointed at somebody else's work.
        $this->selectedReference = $proposal->reference;

        $validated = $this->validate([
            'scope' => ['nullable', 'string'],
            'phases' => ['nullable', 'string'],
            'excluded' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'integer', 'min:0'],
            'depositPercent' => ['required', 'integer', 'min:0', 'max:100'],
            'weeks' => ['required', 'integer', 'min:1', 'max:104'],
        ]);

        $proposal->update([
            'scope' => $validated['scope'],
            'phases' => $validated['phases'],
            'excluded' => $validated['excluded'],
            'price' => $validated['price'],
            'deposit_percent' => $validated['depositPercent'],
            'weeks' => $validated['weeks'],
            ...$extra,
        ]);

        unset($this->proposals, $this->current, $this->totals, $this->waitingLabel);

        $this->fillDraftFrom($this->current);
    }

    /**
     * Copy a proposal's stored write-up into the editable draft.
     */
    private function fillDraftFrom(?Proposal $proposal): void
    {
        $this->scope = $proposal->scope ?? '';
        $this->phases = $proposal->phases ?? '';
        $this->excluded = $proposal->excluded ?? '';
        $this->price = $proposal?->price ?? 0;
        $this->depositPercent = $proposal?->deposit_percent ?? 40;
        $this->weeks = $proposal?->weeks ?? 4;
    }
}; ?>

@php $money = fn (float $value) => '£'.number_format(round($value)); @endphp

<div wire:poll.15s>
    <div class="mb-[clamp(20px,2.4vw,30px)] flex flex-wrap items-end justify-between gap-5">
        <div>
            <x-rsc.kicker class="mb-2.5">from_clients</x-rsc.kicker>
            <x-rsc.heading class="!text-[clamp(28px,4vw,46px)]">{{ __('Proposals') }}</x-rsc.heading>
        </div>
        <div class="font-mono text-[11px] text-muted">{{ $this->waitingLabel }}</div>
    </div>

    <div class="grid items-start gap-[clamp(12px,1.4vw,18px)] lg:grid-cols-[minmax(280px,340px)_1fr]">
        <div class="flex flex-col gap-2.5">
            @forelse ($this->proposals as $proposal)
                @php $selected = $this->current?->is($proposal); @endphp
                <button type="button" wire:click="select('{{ $proposal->reference }}')" wire:key="proposal-{{ $proposal->id }}"
                        @class([
                            'w-full cursor-pointer rounded-2xl border px-5 py-[18px] text-left transition-all duration-200',
                            'border-brand rsc-tint' => $selected,
                            'border-line hover:border-brand' => ! $selected,
                        ])>
                    <div class="mb-2 flex justify-between gap-3 font-mono text-[11px] text-muted">
                        <span>{{ $proposal->reference }}</span>
                        <span style="color: var(--rsc-{{ $proposal->status === \App\Enums\ProposalStatus::Sent ? 'accent' : 'warm' }})">
                            {{ $proposal->status === \App\Enums\ProposalStatus::Sent ? __('sent') : __('new') }}
                        </span>
                    </div>
                    <div class="font-display text-base font-bold tracking-[-0.02em]">{{ $proposal->title }}</div>
                    <div class="mt-1 text-[13px] text-muted">
                        {{ $proposal->team->name }} · {{ $proposal->created_at->format('j F') }}
                    </div>
                </button>
            @empty
                <x-rsc.panel>
                    <p class="m-0 text-sm text-muted">{{ __('Nothing waiting. All caught up.') }}</p>
                </x-rsc.panel>
            @endforelse
        </div>

        @if ($proposal = $this->current)
            <div x-cloak wire:click="closeDetail"
                 x-bind:class="$wire.detailOpen ? 'opacity-100' : 'pointer-events-none opacity-0'"
                 class="fixed inset-0 z-40 bg-black/60 backdrop-blur-[2px] transition-opacity duration-200 lg:hidden"
                 aria-hidden="true"></div>

            <section x-bind:class="$wire.detailOpen ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'"
                     class="fixed inset-y-0 end-0 z-50 flex w-[min(560px,94vw)] flex-col gap-[clamp(22px,2.6vw,38px)] overflow-y-auto border-s border-line bg-panel p-5 transition-transform duration-300 ease-out lg:static lg:z-auto lg:grid lg:w-auto lg:overflow-visible lg:rounded-[20px] lg:border lg:p-[clamp(22px,2.4vw,32px)] lg:transition-none lg:[grid-template-columns:repeat(auto-fit,minmax(300px,1fr))]">

                <button type="button" wire:click="closeDetail"
                        class="flex cursor-pointer items-center gap-2 self-start font-mono text-[11px] text-muted transition-colors hover:text-brand lg:hidden">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" class="size-4" aria-hidden="true">
                        <path d="M15 6l-6 6 6 6" />
                    </svg>
                    {{ __('back to the list') }}
                </button>

                <div>
                    <div class="mb-3 font-mono text-[11px] tracking-[0.1em] text-muted">what_they_asked_for</div>
                    <x-rsc.heading :level="2" class="!text-[clamp(20px,2.2vw,27px)]">{{ $proposal->title }}</x-rsc.heading>
                    <div class="mt-1.5 text-[13px] text-muted">
                        {{ $proposal->team->name }} · {{ $proposal->contact ?? $proposal->requester?->name }} ·
                        {{ __('received :when', ['when' => $proposal->created_at->format('j F')]) }}
                    </div>

                    <p class="m-0 mt-[18px] text-[15px] leading-relaxed text-pretty">{{ $proposal->brief }}</p>
                    @if ($proposal->goal)
                        <p class="m-0 mt-3.5 text-sm leading-relaxed text-muted text-pretty">{{ $proposal->goal }}</p>
                    @endif

                    <div class="mt-5 flex flex-wrap gap-x-[22px] gap-y-2.5 border-t border-line pt-[18px] font-mono text-[11px] text-muted">
                        <span>{{ __('budget :guide', ['guide' => $proposal->budget_guide ?? '—']) }}</span>
                        <span>{{ __('wanted :when', ['when' => $proposal->needed_by ?? '—']) }}</span>
                    </div>

                    <div class="mt-5 rounded-[14px] border border-line bg-ink px-[18px] py-4">
                        <div class="mb-2 font-mono text-[11px] text-muted">client_sees</div>
                        <div class="text-sm leading-relaxed text-muted text-pretty">
                            {{ $proposal->status === \App\Enums\ProposalStatus::Sent
                                ? __('Sitting in their projects list as "Needs your sign-off". Approving it starts the job and raises the :deposit deposit invoice.', ['deposit' => $money($this->totals['deposit'])])
                                : __('Nothing yet. It shows as "With RSC" until you send this.') }}
                        </div>
                    </div>
                </div>

                <div class="flex flex-col gap-4">
                    <div class="font-mono text-[11px] tracking-[0.1em] text-muted">write_the_proposal</div>

                    <x-rsc.field label="scope — one line each" name="scope">
                        <x-rsc.textarea wire:model.live.debounce.500ms="scope" rows="6" class="!py-3 !text-sm">{{ $scope }}</x-rsc.textarea>
                    </x-rsc.field>

                    <x-rsc.field label="phases — name | date | note" name="phases">
                        <x-rsc.textarea wire:model="phases" rows="4" class="!py-3 !text-sm font-mono">{{ $phases }}</x-rsc.textarea>
                    </x-rsc.field>

                    <x-rsc.field label="not_included" name="excluded">
                        <x-rsc.input wire:model="excluded" class="!py-[11px] !text-sm" />
                    </x-rsc.field>

                    <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(120px,1fr))]">
                        <x-rsc.field label="price_ex_vat" name="price">
                            <x-rsc.input type="number" min="0" step="50" wire:model.live.debounce.500ms="price" class="!py-[11px] !text-sm" />
                        </x-rsc.field>
                        <x-rsc.field label="deposit_%" name="depositPercent">
                            <x-rsc.input type="number" min="0" max="100" step="5" wire:model.live.debounce.500ms="depositPercent" class="!py-[11px] !text-sm" />
                        </x-rsc.field>
                        <x-rsc.field label="weeks" name="weeks">
                            <x-rsc.input type="number" min="1" step="1" wire:model="weeks" class="!py-[11px] !text-sm" />
                        </x-rsc.field>
                    </div>

                    <div class="flex flex-col gap-2.5 border-y border-line py-4 text-[13px] text-muted">
                        <div class="flex justify-between gap-3.5">
                            <span>{{ __('Deposit on sign-off') }}</span>
                            <span class="text-body">{{ $money($this->totals['deposit']) }} ({{ $depositPercent }}%)</span>
                        </div>
                        <div class="flex justify-between gap-3.5">
                            <span>{{ __('Balance on go live') }}</span>
                            <span class="text-body">{{ $money($this->totals['balance']) }}</span>
                        </div>
                        <div class="flex justify-between gap-3.5">
                            <span>{{ __('Total inc VAT') }}</span>
                            <span class="text-body">{{ $money($this->totals['total']) }}</span>
                        </div>
                        <div class="flex justify-between gap-3.5">
                            <span>{{ __('Payment terms') }}</span>
                            <span class="text-body">{{ __(':days days from invoice', ['days' => $this->totals['terms']]) }}</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" wire:click="send" @disabled(! $this->readyToSend)
                                @class([
                                    'rounded-full px-[26px] py-3 font-display text-[15px] font-bold transition-transform duration-200',
                                    'bg-brand text-accent-ink cursor-pointer hover:-translate-y-0.5' => $this->readyToSend,
                                    'cursor-not-allowed text-muted' => ! $this->readyToSend,
                                ])
                                @style(['background: var(--rsc-line)' => ! $this->readyToSend])>
                            {{ $proposal->status === \App\Enums\ProposalStatus::Sent ? __('Resend to client') : __('Send for sign-off') }}
                        </button>

                        <x-rsc.button variant="outline" wire:click="saveDraft" class="!px-[22px] !py-3 !text-sm">
                            {{ __('Save draft') }}
                        </x-rsc.button>
                    </div>

                    <div class="text-xs text-muted text-pretty">
                        {{ $this->readyToSend
                            ? __('Goes to :contact as a link into their portal. :weeks weeks quoted.', [
                                'contact' => $proposal->contact ?? $proposal->team->name,
                                'weeks' => $weeks,
                            ])
                            : __('Add scope and a price before this can go out.') }}
                    </div>
                </div>
            </section>
        @endif
    </div>
</div>
