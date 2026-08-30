<?php

use App\Models\Plan;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::rsc.client')]
#[Title('Your plan')]
class extends Component {
    /**
     * Get the business the signed-in person is looking at.
     */
    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Get the plans on offer, in display order.
     *
     * @return Collection<int, Plan>
     */
    #[Computed]
    public function plans(): Collection
    {
        return Plan::query()->offered()->get();
    }

    /**
     * Ask the studio to move this client onto a different plan.
     *
     * Nothing is charged here: the request is recorded and Ross confirms it by email.
     */
    public function request(int $planId): void
    {
        abort_unless(Auth::user()->accessFor()->canSeeBilling(), 403);

        $plan = Plan::query()->offered()->findOrFail($planId);

        abort_if($plan->id === $this->team->plan_id, 422);

        $this->team->update(['requested_plan' => $plan->name]);

        unset($this->team);

        Flux::toast(variant: 'success', text: __(':plan requested.', ['plan' => $plan->name]));
    }
}; ?>

<div>
    @php $current = $this->team->plan; @endphp

    <div class="mb-[clamp(22px,2.6vw,34px)]">
        <x-rsc.kicker class="mb-2.5">{{ $current ? 'current_plan_'.$current->slug : 'no_plan_yet' }}</x-rsc.kicker>
        <x-rsc.heading>{{ $current ? __('Your plan') : __('Choose a plan') }}</x-rsc.heading>
        <p class="mt-3 max-w-[56ch] text-[15px] text-muted text-pretty">
            {{ $current
                ? __('You are on :plan. Change it whenever you like — I will confirm before anything is invoiced differently.', ['plan' => $current->name])
                : __('Your account is free and stays free. A plan is only needed if you want ongoing hosting, updates and support hours.') }}
        </p>
    </div>

    @if ($requested = $this->team->requested_plan)
        <x-rsc.panel accent class="mb-[clamp(16px,2vw,24px)] animate-rsc-fade !p-[clamp(22px,2.6vw,32px)]">
            <x-rsc.kicker class="mb-3">plan_request_sent</x-rsc.kicker>
            <div class="font-display text-[clamp(21px,2.4vw,30px)] font-extrabold tracking-[-0.03em]">
                {{ $current ? __('Moving you to :plan.', ['plan' => $requested]) : __(':plan requested.', ['plan' => $requested]) }}
            </div>
            <p class="mt-2.5 max-w-[56ch] text-[15px] text-muted text-pretty">
                {{ __('Nothing is charged yet. Ross will confirm the change by email, and it starts on the first of next month.') }}
            </p>
        </x-rsc.panel>
    @endif

    <div class="grid items-start gap-[clamp(12px,1.4vw,18px)] [grid-template-columns:repeat(auto-fit,minmax(280px,1fr))]">
        @foreach ($this->plans as $plan)
            @php
                $isCurrent = $current?->id === $plan->id;
                $isRequested = $requested === $plan->name;
                $accented = $isCurrent || $isRequested || $plan->is_featured;
                $tag = match (true) {
                    $isCurrent => 'current',
                    $isRequested => 'requested',
                    $plan->is_featured => 'most chosen',
                    default => 'available',
                };
            @endphp

            <section class="flex flex-col gap-[18px] rounded-[20px] border bg-panel p-[clamp(22px,2.6vw,34px)] transition-colors duration-200 {{ $accented ? 'border-brand' : 'border-line' }}"
                     @style(['box-shadow: 0 0 60px var(--rsc-glow)' => $isCurrent])>
                <div class="flex items-center justify-between gap-3">
                    <span class="font-mono text-[11px] tracking-[0.1em] text-muted">{{ $plan->slug }}</span>
                    <x-rsc.pill :tone="$isCurrent || $isRequested ? 'brand' : 'muted'" class="!text-[10px] !tracking-[0.08em]">{{ $tag }}</x-rsc.pill>
                </div>

                <div>
                    <h2 class="m-0 mb-1.5 font-display text-[28px] font-extrabold tracking-[-0.03em]">{{ $plan->name }}</h2>
                    <p class="m-0 text-sm text-muted">{{ $plan->blurb }}</p>
                </div>

                <div class="flex items-baseline gap-2">
                    <span class="font-display text-[clamp(30px,3.4vw,42px)] font-extrabold leading-none tracking-[-0.035em]">£{{ number_format($plan->price) }}</span>
                    <span class="text-[13px] text-muted">{{ __('/ month') }}</span>
                </div>

                <div class="flex flex-wrap gap-x-[18px] gap-y-2 font-mono text-[11px] text-muted">
                    <span>{{ $plan->hoursLabel() }}</span>
                    <span>{{ $plan->response_time }}</span>
                </div>

                <div class="flex flex-col gap-2.5 border-t border-line pt-4 text-sm">
                    @foreach ($plan->features as $feature)
                        <div class="flex gap-[11px]"><span class="text-brand">→</span><span>{{ $feature }}</span></div>
                    @endforeach
                </div>

                <button type="button"
                        @disabled($isCurrent || ! auth()->user()->accessFor()->canSeeBilling())
                        wire:click="request({{ $plan->id }})"
                        class="mt-auto rounded-full border px-[22px] py-3.5 font-display text-[15px] font-bold transition-transform duration-200 disabled:cursor-default enabled:cursor-pointer enabled:hover:-translate-y-0.5 {{ $isCurrent ? 'border-line text-muted' : ($accented ? 'border-transparent bg-brand text-accent-ink' : 'border-line text-body') }}">
                    {{ $isCurrent
                        ? __('Your current plan')
                        : ($isRequested ? __('Requested') : ($current ? __('Switch to :plan', ['plan' => $plan->name]) : __('Choose :plan', ['plan' => $plan->name]))) }}
                </button>
            </section>
        @endforeach
    </div>

    <div class="mt-[clamp(16px,2vw,24px)] grid gap-[clamp(12px,1.4vw,18px)] [grid-template-columns:repeat(auto-fit,minmax(260px,1fr))]">
        @foreach ([
            ['No tie-in', 'Move up, down or off a plan with a month\'s notice.'],
            ['Hours don\'t roll over', 'Unused time resets on the first of the month.'],
            ['Bigger jobs quoted separately', 'Plans cover support, not new builds.'],
        ] as [$title, $body])
            <div class="rounded-[18px] border border-line bg-panel px-[22px] py-5">
                <div class="font-display text-base font-bold">{{ $title }}</div>
                <div class="mt-1.5 text-[13px] text-muted">{{ $body }}</div>
            </div>
        @endforeach
    </div>
</div>
