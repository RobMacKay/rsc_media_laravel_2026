<?php

use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectUpdate;
use App\Models\Team;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::rsc.client')]
#[Title('Client area')]
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
     * Get the project the client most wants progress on: the one still being built.
     */
    #[Computed]
    public function currentProject(): ?Project
    {
        return $this->team->projects()
            ->orderByRaw("CASE phase WHEN 'build' THEN 0 WHEN 'testing' THEN 1 WHEN 'scoping' THEN 2 ELSE 3 END")
            ->first();
    }

    /**
     * Get the three most recently touched tickets.
     *
     * @return Collection<int, Ticket>
     */
    #[Computed]
    public function recentTickets(): Collection
    {
        return $this->team->tickets()->latest('updated_at')->limit(3)->get();
    }

    /**
     * Get the updates feed shown down the right of the dashboard.
     *
     * @return Collection<int, ProjectUpdate>
     */
    #[Computed]
    public function updates(): Collection
    {
        return $this->team->updates()->limit(4)->get();
    }

    /**
     * Get the next invoice the client owes, if there is one.
     */
    #[Computed]
    public function nextInvoice(): ?Invoice
    {
        return $this->team->invoices()->outstanding()->reorder('due_on')->first();
    }

    /**
     * Get the greeting line summarising what needs attention.
     */
    #[Computed]
    public function ticketSummary(): string
    {
        $open = $this->team->tickets()->unresolved()->count();
        $waiting = $this->team->tickets()->where('status', 'waiting_on_client')->count();

        if ($open === 0) {
            return 'Nothing open. All quiet.';
        }

        return trans_choice('{1}One ticket open|[2,*]:count tickets open', $open, ['count' => $open])
            .($waiting > 0 ? ', '.trans_choice('{1}one waiting on you|[2,*]:count waiting on you', $waiting, ['count' => $waiting]).'.' : '.');
    }

    /**
     * Get the support hours used against this month's allowance.
     *
     * @return array{used: float, allowance: float, percent: float}
     */
    #[Computed]
    public function supportHours(): array
    {
        $allowance = $this->team->monthlySupportHours();

        $used = (float) $this->team->tickets()
            ->where('billing_mode', 'support_hours')
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('quoted_hours');

        return [
            'used' => $used,
            'allowance' => $allowance,
            'percent' => $allowance > 0 ? min(100, $used / $allowance * 100) : 0,
        ];
    }
}; ?>

<div>
    <div class="mb-[clamp(24px,3vw,38px)] flex flex-wrap items-end justify-between gap-5">
        <div>
            <x-rsc.kicker class="mb-2.5">{{ str(now()->format('l_j_F'))->lower() }}</x-rsc.kicker>
            <x-rsc.heading>{{ __('Morning, :name.', ['name' => str($this->team->members->first()?->name ?? auth()->user()->name)->before(' ')]) }}</x-rsc.heading>
            <p class="mt-3 text-[15px] text-muted">{{ $this->ticketSummary }}</p>
        </div>

        @if (auth()->user()->accessFor()->canRaiseTickets())
            <x-rsc.button as="a" href="{{ route('client.tickets', ['raise' => 1]) }}" wire:navigate class="px-[26px] py-3.5">
                {{ __('Raise a ticket') }}
            </x-rsc.button>
        @endif
    </div>

    @if (auth()->user()->accessFor()->canSeeBilling())
        <div class="mb-[clamp(16px,2vw,24px)] grid gap-[clamp(12px,1.4vw,18px)] [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
            <x-rsc.panel class="!rounded-2xl !px-[22px] !py-5">
                <x-rsc.kicker tone="muted" class="mb-3">plan</x-rsc.kicker>
                <div class="font-display text-[21px] font-bold tracking-[-0.02em]">{{ $this->team->plan?->name ?? __('No plan') }}</div>
                <a href="{{ route('client.plan') }}" wire:navigate class="mt-1.5 block text-[13px] no-underline {{ $this->team->plan ? 'text-muted' : 'text-brand' }}">
                    {{ $this->team->plan
                        ? __('Response :time — change plan →', ['time' => $this->team->plan->response_time])
                        : __('Choose a plan →') }}
                </a>
            </x-rsc.panel>

            <x-rsc.panel class="!rounded-2xl !px-[22px] !py-5">
                <div class="mb-3 flex justify-between font-mono text-[11px] tracking-[0.1em] text-muted">
                    <span>support_hours</span>
                    <span>{{ str(now()->format('F'))->lower() }}</span>
                </div>
                <div class="flex items-baseline gap-1.5">
                    <span class="font-display text-[21px] font-bold tracking-[-0.02em]">{{ rtrim(rtrim(number_format($this->supportHours['used'], 1), '0'), '.') }}</span>
                    <span class="text-[13px] text-muted">{{ __('of :n hours used', ['n' => rtrim(rtrim(number_format($this->supportHours['allowance'], 1), '0'), '.')]) }}</span>
                </div>
                <x-rsc.meter :percent="$this->supportHours['percent']" height="h-[5px]" :gradient="false" class="mt-3.5" />
            </x-rsc.panel>

            <x-rsc.panel class="!rounded-2xl !px-[22px] !py-5">
                <x-rsc.kicker tone="muted" class="mb-3">next_invoice</x-rsc.kicker>
                <div class="font-display text-[21px] font-bold tracking-[-0.02em]">
                    {{ $this->nextInvoice?->due_on->format('j F') ?? __('Nothing due') }}
                </div>
                <div class="mt-1.5 text-[13px] text-muted">
                    {{ $this->nextInvoice
                        ? '£'.number_format($this->nextInvoice->amount).' + VAT, '.$this->nextInvoice->type->label()
                        : __('Everything is settled') }}
                </div>
            </x-rsc.panel>
        </div>
    @endif

    <div class="grid items-start gap-[clamp(12px,1.4vw,18px)] [grid-template-columns:repeat(auto-fit,minmax(320px,1fr))]">
        <div class="flex flex-col gap-[clamp(12px,1.4vw,18px)]">
            @if ($project = $this->currentProject)
                <x-rsc.panel class="!p-[clamp(22px,2.4vw,30px)]">
                    <div class="mb-5 flex items-center justify-between font-mono text-[11px] tracking-[0.1em] text-muted">
                        <span>current_project</span>
                        <span class="text-brand">{{ str($project->phase->label())->lower() }}</span>
                    </div>
                    <x-rsc.heading :level="2">{{ $project->title }}</x-rsc.heading>
                    <p class="mt-2 text-sm text-muted">{{ $project->summary }}</p>

                    <div class="mt-[26px] flex items-baseline justify-between font-display">
                        <span class="text-[clamp(34px,4.2vw,52px)] font-extrabold leading-none tracking-[-0.04em]">{{ $project->percent }}%</span>
                        <span class="font-sans text-[13px] text-muted">{{ __('complete') }}</span>
                    </div>
                    <x-rsc.meter :percent="$project->percent" class="mt-3.5" />

                    <div class="mt-[26px] grid gap-[18px] border-t border-line pt-[22px] [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
                        <div>
                            <div class="mb-1.5 font-mono text-[11px] text-muted">next_milestone</div>
                            <div class="font-display text-base font-bold">{{ $project->milestone }}</div>
                            <div class="mt-[3px] text-[13px] text-muted">{{ $project->due_on?->format('l j F') }}</div>
                        </div>
                        @if ($project->waiting_on_client)
                            <div>
                                <div class="mb-1.5 font-mono text-[11px] text-warm">waiting_on_you</div>
                                <div class="font-display text-base font-bold">{{ $project->waiting_on_client }}</div>
                            </div>
                        @endif
                    </div>
                </x-rsc.panel>
            @endif

            <x-rsc.panel class="!p-[clamp(22px,2.4vw,30px)]">
                <div class="mb-1.5 flex items-center justify-between">
                    <span class="font-mono text-[11px] tracking-[0.1em] text-muted">recent_tickets</span>
                    <a href="{{ route('client.tickets') }}" wire:navigate class="font-mono text-[11px] no-underline">view all →</a>
                </div>

                @forelse ($this->recentTickets as $ticket)
                    <a href="{{ route('client.tickets') }}" wire:navigate
                       class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 border-t border-line py-[18px] no-underline transition-opacity hover:opacity-70">
                        <div class="font-display text-base font-bold tracking-[-0.015em] text-body">{{ $ticket->title }}</div>
                        <x-rsc.pill :tone="$ticket->status->tone()" class="justify-self-end">{{ str($ticket->status->clientLabel())->lower() }}</x-rsc.pill>
                        <div class="font-mono text-[11px] text-muted">{{ $ticket->reference }} · {{ $ticket->system }}</div>
                        <div class="justify-self-end text-xs text-muted">{{ $ticket->updatedLabel() }}</div>
                    </a>
                @empty
                    <p class="border-t border-line py-[18px] text-sm text-muted">{{ __('No tickets yet.') }}</p>
                @endforelse
            </x-rsc.panel>
        </div>

        <x-rsc.panel class="!p-[clamp(22px,2.4vw,30px)]">
            <div class="mb-1 font-mono text-[11px] tracking-[0.1em] text-muted">updates</div>
            @forelse ($this->updates as $update)
                <div class="border-t border-line py-[18px]">
                    <div class="mb-2 flex items-center gap-2.5">
                        <span class="rounded-full px-2.5 py-[3px] font-mono text-[10px] tracking-[0.08em]"
                              style="color: var(--rsc-{{ $update->kind->tone() === 'warm' ? 'warm' : 'accent' }}); background: color-mix(in srgb, var(--rsc-{{ $update->kind->tone() === 'warm' ? 'warm' : 'accent' }}) 15%, transparent)">{{ $update->tag }}</span>
                        <span class="font-mono text-[11px] text-muted">{{ $update->published_at->format('j M') }}</span>
                    </div>
                    <div class="mb-[5px] font-display text-base font-bold tracking-[-0.015em]">{{ $update->title }}</div>
                    <p class="m-0 text-sm leading-[1.55] text-muted text-pretty">{{ $update->body }}</p>
                </div>
            @empty
                <p class="border-t border-line py-[18px] text-sm text-muted">{{ __('Nothing posted yet.') }}</p>
            @endforelse
        </x-rsc.panel>
    </div>
</div>
