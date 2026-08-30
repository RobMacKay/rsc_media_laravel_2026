@props(['active' => false])

<button type="button" {{ $attributes->class([
    'cursor-pointer rounded-full border px-4 py-2.5 font-mono text-xs transition-all duration-200',
    'border-brand text-brand rsc-tint' => $active,
    'border-line text-muted hover:text-body' => ! $active,
]) }}>{{ $slot }}</button>
