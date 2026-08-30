@props(['variant' => 'primary', 'as' => 'button'])

@php
    $classes = match ($variant) {
        'primary' => 'bg-brand text-accent-ink font-display font-bold hover:-translate-y-0.5',
        'outline' => 'border border-line text-body font-semibold hover:border-brand hover:rsc-tint',
        default => 'text-muted',
    };
@endphp

<{{ $as }} {{ $attributes->class([
    'inline-flex cursor-pointer items-center justify-center gap-2 rounded-full px-6 py-3.5 text-[15px] transition-transform duration-200',
    $classes,
]) }}>{{ $slot }}</{{ $as }}>
