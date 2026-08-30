{{-- Mirrors the design's mono text switch; Flux's appearance store owns the `.dark` class. --}}
<button type="button" x-data aria-label="{{ __('Switch theme') }}"
        x-on:click="$flux.appearance = $flux.dark ? 'light' : 'dark'"
        {{ $attributes->class('cursor-pointer rounded-full border border-line px-3.5 py-[7px] font-mono text-[11px] text-muted transition-colors hover:border-brand hover:text-brand') }}>
    <span x-text="$flux.dark ? 'light' : 'dark'">dark</span>
</button>
