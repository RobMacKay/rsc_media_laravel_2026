@php $complete = $project->isComplete(); @endphp

<section class="rounded-[20px] border border-line bg-panel p-[clamp(22px,2.4vw,30px)]" wire:key="project-{{ $project->id }}">
    <div class="flex flex-wrap items-start justify-between gap-x-5 gap-y-3">
        <div>
            @if ($project->reference)
                <div class="mb-2 font-mono text-[11px] tracking-[0.08em] text-muted">{{ $project->reference }}</div>
            @endif
            <x-rsc.heading :level="2" class="!text-[clamp(20px,2.3vw,28px)]">{{ $project->title }}</x-rsc.heading>
            <p class="m-0 mt-2 max-w-[60ch] text-sm text-muted text-pretty">{{ $project->summary }}</p>
        </div>
        <x-rsc.pill :tone="$complete ? 'muted' : 'brand'" class="!px-3 !py-[5px]">
            {{ $complete ? __('Live') : __('In build') }}
        </x-rsc.pill>
    </div>

    @if ($complete)
        <div class="mt-[22px] flex flex-wrap gap-x-6 gap-y-2 border-t border-line pt-5 text-sm text-muted">
            <span>{{ __('Live since :when', ['when' => $project->completed_on?->format('j F Y') ?? $project->due_on?->format('j F Y')]) }}</span>
            <span>{{ $project->value_label }}</span>
        </div>
    @else
        <div class="mt-6 border-t border-line pt-[22px]">
            <div class="mb-5 flex flex-wrap gap-2">
                @foreach (\App\Enums\ProjectPhase::cases() as $phase)
                    @php $on = $project->phase === $phase; @endphp
                    <span @class([
                        'rounded-full border px-3.5 py-[7px] font-mono text-[11px]',
                        'border-transparent bg-brand text-accent-ink' => $on,
                        'border-line text-muted' => ! $on,
                    ])>{{ $phase->label() }}</span>
                @endforeach
            </div>

            <div class="grid items-start gap-[clamp(20px,2.4vw,34px)] [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
                <div>
                    <div class="flex items-baseline justify-between font-display">
                        <span class="text-[clamp(28px,3.2vw,40px)] font-extrabold leading-none tracking-[-0.04em]">{{ $project->percent }}%</span>
                        <span class="font-sans text-xs text-muted">{{ __('complete') }}</span>
                    </div>
                    <x-rsc.meter :percent="$project->percent" class="mt-3" />
                    <div class="mt-3 flex flex-wrap gap-x-[18px] gap-y-1.5 font-mono text-[11px] text-muted">
                        <span>{{ $project->hoursLabel() }}</span>
                        <span>{{ $project->value_label }}</span>
                    </div>
                </div>

                <div>
                    <div class="mb-1.5 font-mono text-[11px] text-muted">next_milestone</div>
                    <div class="font-display text-base font-bold">{{ $project->milestone }}</div>
                    <div class="mt-[3px] text-[13px] text-muted">{{ $project->due_on?->format('l j F') }}</div>
                </div>

                <div>
                    <div class="mb-1.5 font-mono text-[11px] {{ $project->waiting_on_client ? 'text-warm' : 'text-muted' }}">
                        {{ $project->waiting_on_client ? 'waiting_on_you' : 'status' }}
                    </div>
                    <div class="font-display text-base font-bold">
                        {{ $project->waiting_on_client ?: __('Nothing from you') }}
                    </div>
                    <div class="mt-[3px] text-[13px] text-muted">
                        {{ $project->waiting_on_client ? __('We\'ll chase if we need it') : __('We\'ll email if that changes') }}
                    </div>
                </div>
            </div>
        </div>
    @endif

    @php $files = $project->attachments->where('shared_with_client', true); @endphp

    @if ($files->isNotEmpty())
        <div class="mt-[22px] border-t border-line pt-5">
            <div class="mb-2.5 font-mono text-[11px] text-muted">files</div>
            <div class="flex flex-col gap-2.5">
                @foreach ($files as $file)
                    <x-rsc.attachment :file="$file" wire:key="project-file-{{ $file->id }}" />
                @endforeach
            </div>
        </div>
    @endif
</section>
