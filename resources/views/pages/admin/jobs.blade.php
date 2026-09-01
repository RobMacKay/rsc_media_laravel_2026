<?php

use App\Actions\Attachments\StoreAttachment;
use App\Enums\ProjectPhase;
use App\Models\Attachment;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new
#[Layout('layouts::rsc.admin')]
#[Title('Jobs')]
class extends Component {
    use WithFileUploads;

    /** Which job's files are open, since only one panel shows at a time. */
    public ?int $filesForJobId = null;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    public bool $shareUploads = true;

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
     * Get the job whose files are open, if any.
     */
    #[Computed]
    public function openJob(): ?Project
    {
        return $this->filesForJobId === null
            ? null
            : $this->jobs->firstWhere('id', $this->filesForJobId);
    }

    /**
     * Show or hide the files for a job.
     */
    public function toggleFiles(int $projectId): void
    {
        $this->filesForJobId = $this->filesForJobId === $projectId ? null : $projectId;

        $this->reset('uploads');
        $this->resetValidation();

        unset($this->openJob);
    }

    /**
     * Attach one or more files to the job whose panel is open.
     */
    public function attachFiles(): void
    {
        $job = $this->openJob;

        abort_unless($job, 404);

        $this->validate(
            [
                'uploads' => ['required', 'array', 'max:10'],
                'uploads.*' => Attachment::rules(Attachment::STUDIO_MIMES, Attachment::maxUploadKb(Attachment::STUDIO_MAX_KB)),
            ],
            [
                'uploads.required' => __('Choose a file first.'),
                'uploads.max' => __('Ten files at a time is the limit.'),
                ...Attachment::messages('uploads.*', Attachment::STUDIO_MIMES, Attachment::maxUploadKb(Attachment::STUDIO_MAX_KB)),
            ],
            ['uploads.*' => __('file')],
        );

        foreach ($this->uploads as $upload) {
            app(StoreAttachment::class)->handle($job, $upload, Auth::user(), $this->shareUploads);
        }

        $count = count($this->uploads);

        $this->reset('uploads');

        unset($this->jobs, $this->openJob);

        Flux::toast(variant: 'success', text: trans_choice(
            '{1}Attached 1 file.|[2,*]Attached :count files.',
            $count,
            ['count' => $count],
        ));
    }

    /**
     * Remove a file from the job whose panel is open.
     */
    public function removeFile(int $attachmentId): void
    {
        $job = $this->openJob;

        abort_unless($job, 404);

        $attachment = $job->attachments()->findOrFail($attachmentId);

        $attachment->delete();

        unset($this->jobs, $this->openJob);

        Flux::toast(variant: 'success', text: __('Removed :name.', ['name' => $attachment->name]));
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
                        <button type="button" wire:click="toggleFiles({{ $job->id }})" class="cursor-pointer text-brand">
                            {{ $filesForJobId === $job->id ? __('hide files') : __('attach file') }}
                        </button>
                        <button type="button" class="cursor-pointer text-brand"
                                x-on:click="$wire.saveJob({{ $job->id }}, { percent, milestone, due_on: due || null, waiting_on_client: waiting })">
                            {{ __('save changes') }}
                        </button>
                    </div>

                    @if ($filesForJobId === $job->id)
                        <div class="flex flex-col gap-3.5 border-t border-line pt-3.5">
                            @foreach ($job->attachments as $file)
                                <x-rsc.attachment :file="$file" show-share wire:key="file-{{ $file->id }}">
                                    <x-slot:action>
                                        <button type="button" wire:click="removeFile({{ $file->id }})"
                                                wire:confirm="{{ __('Remove :name?', ['name' => $file->name]) }}"
                                                class="cursor-pointer bg-transparent p-0 font-mono text-[11px] text-muted transition-colors hover:text-warm">
                                            {{ __('remove') }}
                                        </button>
                                    </x-slot:action>
                                </x-rsc.attachment>
                            @endforeach

                            <x-rsc.dropzone name="uploads" model="uploads" multiple
                                            :title="__('Attach a file')"
                                            :hint="__('Quotes, invoices, specs, screenshots. Up to :size.', ['size' => \Illuminate\Support\Number::fileSize(\App\Models\Attachment::maxUploadKb(\App\Models\Attachment::STUDIO_MAX_KB) * 1024)])" />

                            <label class="flex cursor-pointer items-center gap-2.5 text-[13px] text-muted">
                                <input type="checkbox" wire:model.live="shareUploads" class="size-4 accent-[var(--rsc-accent)]">
                                <span>{{ __('Visible to the client as soon as it uploads') }}</span>
                            </label>

                            @if ($uploads)
                                <x-rsc.button wire:click="attachFiles" class="self-start !px-5 !py-2.5 !text-sm">
                                    {{ trans_choice('{1}Upload 1 file|[2,*]Upload :count files', count($uploads), ['count' => count($uploads)]) }}
                                </x-rsc.button>
                            @endif
                        </div>
                    @endif
                </div>
            </section>
        @endforeach
    </div>
</div>
