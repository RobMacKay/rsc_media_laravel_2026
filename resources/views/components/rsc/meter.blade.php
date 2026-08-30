@props(['percent' => 0, 'height' => 'h-1.5', 'gradient' => true])

<div class="{{ $height }} overflow-hidden rounded-full" style="background: var(--rsc-line)">
    <div class="h-full origin-left animate-rsc-bar"
         style="width: {{ max(0, min(100, (float) $percent)) }}%; background: {{ $gradient ? 'linear-gradient(90deg, var(--rsc-accent), var(--rsc-accent-soft))' : 'var(--rsc-accent)' }}"></div>
</div>
