<?php

use App\Enums\ProposalStatus;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\StudioSetting;
use App\Models\Team;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new
#[Layout('layouts::rsc.client')]
#[Title('Projects')]
class extends Component {
    #[Url(as: 'propose', except: false)]
    public bool $proposeOpen = false;

    public ?string $proposedReference = null;

    public string $title = '';

    public string $brief = '';

    public string $goal = '';

    public string $budget = 'No idea yet';

    public string $neededBy = '';

    public string $contact = '';

    /**
     * Get the business the signed-in person is looking at.
     */
    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Get everything on the client's plate, newest first: proposals still in
     * flight, then the projects they turned into.
     *
     * @return Collection<int, array<string, mixed>>
     */
    #[Computed]
    public function items(): Collection
    {
        // Whatever needs the client first, then live work, then what is done.
        $proposals = $this->team->proposals()
            ->live()
            ->get()
            ->map(fn (Proposal $proposal) => [
                'kind' => 'proposal',
                'rank' => $proposal->status === ProposalStatus::Sent ? 0 : 1,
                'sort' => $proposal->created_at,
                'model' => $proposal,
            ]);

        $projects = $this->team->projects()
            ->with('proposal')
            ->get()
            ->map(fn (Project $project) => [
                'kind' => 'project',
                'rank' => ($project->completed_on !== null || $project->percent >= 100) ? 3 : 2,
                'sort' => $project->due_on ?? $project->created_at,
                'model' => $project,
            ]);

        return $proposals->concat($projects)
            ->sortBy([['rank', 'asc'], ['sort', 'desc']])
            ->values();
    }

    /**
     * Pick a budget bracket.
     *
     * Passed by index rather than by value: the labels carry a pound sign and
     * an en dash, and Blade does not compile @js() inside a component's
     * attribute, so the literal string ends up in the wire:click expression.
     */
    public function chooseBudget(int $index): void
    {
        $this->budget = Proposal::BUDGETS[$index] ?? $this->budget;
    }

    /**
     * Open or close the proposal form.
     */
    public function togglePropose(): void
    {
        $this->proposeOpen = ! $this->proposeOpen;
        $this->proposedReference = null;
        $this->resetValidation();
    }

    /**
     * Close the form and clear the confirmation.
     */
    public function closePropose(): void
    {
        $this->proposeOpen = false;
        $this->proposedReference = null;
    }

    /**
     * Send a new project idea to the studio.
     */
    public function propose(): void
    {
        abort_unless(Auth::user()->accessFor()->canRaiseTickets(), 403);

        $validated = $this->validate([
            'title' => ['required', 'string', 'max:255'],
            'brief' => ['required', 'string', 'max:5000'],
            'goal' => ['nullable', 'string', 'max:2000'],
            'budget' => ['required', Rule::in(Proposal::BUDGETS)],
            'neededBy' => ['nullable', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:255'],
        ]);

        $proposal = $this->team->proposals()->create([
            'reference' => Proposal::nextReference(),
            'requested_by' => Auth::id(),
            'title' => $validated['title'],
            'brief' => $validated['brief'],
            'goal' => $validated['goal'] ?: null,
            'budget_guide' => $validated['budget'],
            'needed_by' => $validated['neededBy'] ?: null,
            'contact' => $validated['contact'] ?: Auth::user()->name,
            'status' => ProposalStatus::Submitted,
        ]);

        $this->reset('title', 'brief', 'goal', 'neededBy', 'contact');
        $this->proposedReference = $proposal->reference;

        unset($this->items);
    }

    /**
     * Sign a proposal off, which opens the project and raises the deposit.
     */
    public function approve(int $proposalId): void
    {
        abort_unless(Auth::user()->accessFor()->canSeeBilling(), 403);

        $proposal = $this->team->proposals()
            ->where('status', ProposalStatus::Sent)
            ->findOr($proposalId, fn () => abort(404));

        $proposal->approve(StudioSetting::current());

        unset($this->items);

        Flux::toast(variant: 'success', text: __('Signed off — Ross will be in touch to book the kick-off.'));
    }
}; ?>

<div wire:poll.15s>
    <div class="mb-[clamp(22px,2.6vw,34px)] flex flex-wrap items-end justify-between gap-5">
        <div>
            <x-rsc.kicker class="mb-2.5">work</x-rsc.kicker>
            <x-rsc.heading>{{ __('Projects') }}</x-rsc.heading>
            <p class="mt-3 max-w-[56ch] text-[15px] text-muted text-pretty">
                {{ __('Anything bigger than a ticket starts here. Tell us roughly what you need, we write it up properly, you sign it off.') }}
            </p>
        </div>

        @if (auth()->user()->accessFor()->canRaiseTickets())
            <x-rsc.button wire:click="togglePropose" class="px-[26px] py-3.5">
                {{ $proposeOpen ? __('Close') : __('Propose a project') }}
            </x-rsc.button>
        @endif
    </div>

    @if ($proposeOpen && auth()->user()->accessFor()->canRaiseTickets())
        <x-rsc.panel accent class="mb-[clamp(16px,2vw,24px)] animate-rsc-fade !p-[clamp(22px,2.6vw,34px)]">
            @if ($proposedReference)
                <div class="flex flex-col gap-3.5">
                    <x-rsc.kicker>{{ $proposedReference }}</x-rsc.kicker>
                    <div class="font-display text-[clamp(22px,2.6vw,32px)] font-extrabold tracking-[-0.03em]">{{ __('With us now.') }}</div>
                    <p class="m-0 max-w-[52ch] text-[15px] text-muted">
                        {{ __('Ross will read it and write up scope, phases and a price. You\'ll get an email when it\'s ready to sign off.') }}
                    </p>
                    <x-rsc.button variant="outline" wire:click="closePropose" class="self-start !px-[22px] !py-2.5 !text-sm">
                        {{ __('Back to projects') }}
                    </x-rsc.button>
                </div>
            @else
                <form wire:submit="propose" class="flex flex-col gap-5">
                    <x-rsc.kicker tone="muted">new_project_proposition</x-rsc.kicker>

                    <x-rsc.field label="working_title" name="title">
                        <x-rsc.input wire:model="title" placeholder="{{ __('What would you call it?') }}" />
                    </x-rsc.field>

                    <x-rsc.field label="what_you_want_it_to_do" name="brief">
                        <x-rsc.textarea wire:model="brief" rows="4"
                                        placeholder="{{ __('Plain words are fine. What should it do, and for who?') }}" />
                    </x-rsc.field>

                    <x-rsc.field label="what_it_would_fix" name="goal">
                        <x-rsc.textarea wire:model="goal" rows="2"
                                        placeholder="{{ __('The problem it solves, or the time it saves.') }}" />
                    </x-rsc.field>

                    <div>
                        <span class="mb-2 block font-mono text-[11px] text-muted">rough_budget</span>
                        <div class="flex flex-wrap gap-2">
                            @foreach (\App\Models\Proposal::BUDGETS as $index => $option)
                                <x-rsc.chip wire:click="chooseBudget({{ $index }})" :active="$budget === $option" class="!text-[13px] !font-sans">
                                    {{ $option }}
                                </x-rsc.chip>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
                        <x-rsc.field label="when_you_need_it" name="neededBy">
                            <x-rsc.input wire:model="neededBy" placeholder="{{ __('e.g. before the October rush') }}" />
                        </x-rsc.field>
                        <x-rsc.field label="who_we_should_speak_to" name="contact">
                            <x-rsc.input wire:model="contact" placeholder="{{ auth()->user()->name }}" />
                        </x-rsc.field>
                    </div>

                    <div class="flex flex-wrap items-center gap-3.5 border-t border-line pt-4">
                        <x-rsc.button type="submit" class="px-[26px] py-3.5">{{ __('Send it over') }}</x-rsc.button>
                        <span class="text-[13px] text-muted">
                            {{ __('No commitment. We come back with scope, price and dates within two working days.') }}
                        </span>
                    </div>
                </form>
            @endif
        </x-rsc.panel>
    @endif

    <div class="flex flex-col gap-[clamp(12px,1.4vw,18px)]">
        @forelse ($this->items as $item)
            @if ($item['kind'] === 'proposal')
                @include('pages.client.partials.proposal-card', ['proposal' => $item['model']])
            @else
                @include('pages.client.partials.project-card', ['project' => $item['model']])
            @endif
        @empty
            <x-rsc.panel>
                <p class="m-0 text-[15px] text-muted">
                    {{ __('Nothing on the go. Propose a project and we\'ll write it up.') }}
                </p>
            </x-rsc.panel>
        @endforelse
    </div>
</div>
