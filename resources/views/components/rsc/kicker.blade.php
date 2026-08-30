@props(['tone' => 'brand'])

<div {{ $attributes->class([
    'font-mono text-[11px] tracking-[0.12em]',
    'text-brand' => $tone === 'brand',
    'text-muted' => $tone === 'muted',
    'text-warm' => $tone === 'warm',
]) }}>{{ $slot }}</div>
