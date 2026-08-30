@props(['level' => 1])

@php
    $size = match ((int) $level) {
        1 => 'text-[clamp(30px,4.4vw,52px)] leading-[1.02] tracking-[-0.035em] font-extrabold',
        2 => 'text-[clamp(21px,2.4vw,30px)] tracking-[-0.025em] font-bold',
        default => 'text-[17px] tracking-[-0.02em] font-bold',
    };
@endphp

<h{{ $level }} {{ $attributes->class(['font-display m-0', $size]) }}>{{ $slot }}</h{{ $level }}>
