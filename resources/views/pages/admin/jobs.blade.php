<?php

use App\Enums\ProjectPhase;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::rsc.admin')]
#[Title('Jobs')]
class extends Component {
    /**
     * Get every live job across all clients.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function jobs(): Collection
    {
        return Project::query()
            ->with(['team', 'attachments'])
            ->withCount('attachments')
            ->orderBy('due_on')
            ->get();
    }

    /**
     * Move a job into a different phase.
     */
    public function setPhase(int $projectId, string $phase): void
    {
        Project::findOrFail($projectId)->update([
            'phase' => ProjectPhase::from($phase),
        ]);

        unset($this->jobs);
    }

    /**
     * Save the inline edits made against one job row.
     */
    public function saveJob(int $projectId, array $payload): void
    {
        $validated = validator($payload, [
            'percent' => ['required', 'integer', 'min:0', 'max:100'],
            'milestone' => ['nullable', 'string', 'max:255'],
            'due_on' => ['nullable', 'date'],
            'waiting_on_client' => ['nullable', 'string', 'max:255'],
        ])->validate();

        Project::findOrFail($projectId)->update([
            ...$validated,
            'waiting_on_client' => $validated['waiting_on_client'] ?: null,
        ]);

        unset($this->jobs);

        Flux::toast(variant: 'success', text: __('Job updated.'));
    }
}; ?>

<div>
    <div class="mb-[clamp(20px,2.4vw,30px)] flex flex-wrap items-end justify-between gap-5">
        <div>
            <x-rsc.kicker class="mb-2.5">all_clients</x-rsc.kicker>
            <x-rsc.heading class="!text-[clamp(28px,4vw,46px)]">{{ __('Jobs') }}</x-rsc.heading>
        </div>
    </div>

    <div class="flex flex-col gap-[clamp(12px,1.4vw,18px)]">
        @foreach ($this->jobs as $job)
            <section class="grid gap-[clamp(18px,2.4vw,34px)] rounded-[20px] border border-line bg-panel p-[clamp(20px,2.4vw,30px)] [grid-template-columns:repeat(auto-fit,minmax(260px,1fr))]"
                     wire:key="job-{{ $job->id }}"
                     x-data="{
                         percent: {{ $job->percent }},
                         milestone: @js($job->milestone),
                         due: '{{ $job->due_on?->format('Y-m-d') }}',
                         waiting: @js($job->waiting_on_client ?? ''),
                     }">
                <div>
                    <div class="mb-2.5 font-mono text-[11px] tracking-[0.08em] text-muted">{{ $job->team->name }}</div>
                    <x-rsc.heading :level="2" class="!text-[clamp(19px,2vw,25px)]">{{ $job->title }}</x-rsc.heading>

                    <div class="mt-3.5 flex flex-wrap gap-2">
                        @foreach (ProjectPhase::cases() as $phase)
                            <x-rsc.chip wire:click="setPhase({{ $job->id }}, '{{ $phase->value }}')" :active="$job->phase === $phase" class="!px-[13px] !py-[7px] !text-[11px]">
                                {{ $phase->label() }}
                            </x-rsc.chip>
                        @endforeach
                    </div>
                </div>

                <div>
                    <div class="flex items-baseline justify-between font-display">
                        <span class="text-[clamp(26px,3vw,38px)] font-extrabold leading-none tracking-[-0.035em]" x-text="percent + '%'"></span>
                        <span class="font-sans text-xs text-muted">{{ __('complete') }}</span>
                    </div>

                    <div class="mt-3 h-1.5 overflow-hidden rounded-full" style="background: var(--rsc-line)">
                        <div class="h-full origin-left"
                             style="background: linear-gradient(90deg, var(--rsc-accent), var(--rsc-accent-soft))"
                             x-bind:style="`width: ${percent}%`"></div>
                    </div>

                    <input type="range" min="0" max="100" step="5" x-model.number="percent"
                           aria-label="{{ __('Progress') }}" class="mt-3.5 w-full" style="accent-color: var(--rsc-accent)">

                    <div class="mt-2.5 flex flex-wrap gap-x-[18px] gap-y-2 font-mono text-[11px] text-muted">
                        <span>{{ $job->hoursLabel() }}</span>
                        <span>{{ $job->value_label }}</span>
                    </div>
                </div>

                <div class="flex flex-col gap-3.5">
                    <x-rsc.field label="next_milestone">
                        <x-rsc.input x-model="milestone" class="!py-[11px] !text-sm" />
                    </x-rsc.field>

                    <div class="grid gap-3 [grid-template-columns:repeat(auto-fit,minmax(130px,1fr))]">
                        <x-rsc.field label="due">
                            <x-rsc.input type="date" x-model="due" class="!py-[11px] !text-sm" />
                        </x-rsc.field>
                        <label class="block">
                            <span class="mb-2 block font-mono text-[11px] text-warm">waiting_on_client</span>
                            <x-rsc.input x-model="waiting" placeholder="{{ __('Nothing') }}" class="!py-[11px] !text-sm" />
                        </label>
                    </div>

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2.5 border-t border-line pt-3 font-mono text-[11px]">
                        <span class="me-auto text-muted">{{ trans_choice('{0}No files|{1}1 file|[2,*]:count files', $job->attachments_count, ['count' => $job->attachments_count]) }}</span>
                        <button type="button" class="cursor-pointer text-brand"
                                x-on:click="$wire.saveJob({{ $job->id }}, { percent, milestone, due_on: due || null, waiting_on_client: waiting })">
                            {{ __('save changes') }}
                        </button>
                    </div>
                </div>
            </section>
        @endforeach
    </div>
</div>
