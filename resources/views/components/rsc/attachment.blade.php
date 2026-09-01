@props(['file', 'showShare' => false])

{{-- One file row. The name is the download link, which goes through the
     attachments route because files live on the private disk and are never
     reachable by URL. Any action stays outside the link so the row is valid
     markup and a delete button cannot swallow the download click. --}}
@php $hasTrailing = $showShare || isset($action); @endphp

<div {{ $attributes->class([
    'grid items-center gap-3.5 rounded-[14px] border border-line px-3.5 py-3 transition-colors duration-200 focus-within:border-brand hover:border-brand',
    'grid-cols-[44px_1fr_auto]' => $hasTrailing,
    'grid-cols-[44px_1fr]' => ! $hasTrailing,
]) }}>
    <span class="grid h-[34px] place-items-center rounded-[9px] font-mono text-[10px] tracking-[0.04em]"
          @style([
              'color: var(--rsc-accent); background: color-mix(in srgb, var(--rsc-accent) 14%, transparent)' => $file->shared_with_client,
              'color: var(--rsc-muted); background: color-mix(in srgb, var(--rsc-text) 8%, transparent)' => ! $file->shared_with_client,
          ])>{{ $file->kind }}</span>

    <span class="min-w-0">
        <a href="{{ route('attachments.download', $file) }}"
           class="block font-display text-sm font-bold break-words text-body no-underline hover:text-brand">{{ $file->name }}</a>
        <span class="mt-[3px] block font-mono text-[11px] text-muted">{{ $file->metaLabel() }}</span>
    </span>

    @if ($hasTrailing)
        <span class="flex items-center gap-2.5">
            @if ($showShare)
                <x-rsc.pill :tone="$file->shared_with_client ? 'brand' : 'muted'" class="!text-[10px] !tracking-[0.06em]">
                    {{ $file->shared_with_client ? 'client' : 'internal' }}
                </x-rsc.pill>
            @endif
            {{ $action ?? '' }}
        </span>
    @endif
</div>
