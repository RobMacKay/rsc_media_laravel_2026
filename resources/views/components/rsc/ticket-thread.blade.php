@props(['comments', 'showInternal' => false])

<div class="flex flex-col gap-3">
    @forelse ($comments as $comment)
        @php $studio = $comment->fromStudio(); @endphp

        <div @class([
            'rounded-2xl border px-4 py-3.5',
            'border-dashed' => $comment->is_internal,
            'border-line' => ! $comment->is_internal,
        ]) @style([
            'border-color: color-mix(in srgb, var(--rsc-warm) 45%, transparent); background: color-mix(in srgb, var(--rsc-warm) 7%, transparent)' => $comment->is_internal,
            'background: color-mix(in srgb, var(--rsc-accent) 6%, transparent)' => $studio && ! $comment->is_internal,
        ])>
            <div class="mb-2 flex flex-wrap items-center gap-x-2.5 gap-y-1 font-mono text-[11px]">
                <span class="{{ $studio ? 'text-brand' : 'text-muted' }}">
                    {{ $comment->author?->name ?? __('Removed user') }}
                </span>
                @if ($studio)
                    <span class="text-muted">· RSC Media</span>
                @endif
                @if ($comment->is_internal && $showInternal)
                    <span class="rounded-full px-2 py-0.5 text-[10px] tracking-[0.08em] text-warm rsc-tint-warm">internal</span>
                @endif
                <span class="ms-auto text-muted">{{ $comment->created_at?->diffForHumans() }}</span>
            </div>

            <p class="m-0 text-sm leading-relaxed whitespace-pre-line text-pretty">{{ $comment->body }}</p>
        </div>
    @empty
        <p class="m-0 text-[13px] text-muted">{{ __('Nothing on this ticket yet.') }}</p>
    @endforelse
</div>
