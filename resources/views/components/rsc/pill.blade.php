@props(['tone' => 'muted'])

@php
    $var = match ($tone) {
        'brand' => '--rsc-accent',
        'soft' => '--rsc-accent-soft',
        'warm' => '--rsc-warm',
        default => null,
    };
    $style = $var
        ? "color: var($var); background: color-mix(in srgb, var($var) 14%, transparent); border-color: color-mix(in srgb, var($var) 42%, transparent)"
        : 'color: var(--rsc-muted); background: transparent; border-color: var(--rsc-line)';
@endphp

<span {{ $attributes->class('inline-block whitespace-nowrap rounded-full border px-2.5 py-1 font-mono text-[11px]') }}
      style="{{ $style }}">{{ $slot }}</span>
