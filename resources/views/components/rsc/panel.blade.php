@props(['accent' => false, 'padded' => true])

<div {{ $attributes->class([
    'rounded-[20px] border bg-panel',
    'border-brand shadow-[0_0_60px_var(--rsc-glow)]' => $accent,
    'border-line' => ! $accent,
    'p-[clamp(20px,2.4vw,30px)]' => $padded,
]) }}>
    {{ $slot }}
</div>
