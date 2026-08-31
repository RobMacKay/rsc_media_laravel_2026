@props(['open' => 'false', 'close' => '', 'heading' => null])

{{-- A detail panel that slides in from the right at every width.

     Three things to keep in mind if you touch this:

     1. It is teleported to <body> so no `backdrop-filter` ancestor can turn
        itself into the containing block for `position: fixed`.
     2. It animates by binding classes rather than x-show with x-transition;
        Alpine's class-based transitions leave the element at display:none in
        this build.
     3. Render it unconditionally and put the conditional bit in the slot, and
        drive `open` from Alpine state rather than reading `$wire.<property>`.
        A teleported node can evaluate its bindings before Livewire has
        hydrated, and an undefined property is not null, so the panel opened
        itself on load depending on how the race fell.
     4. The slide is an inline style, not a utility class. Binding the Tailwind
        translate utilities here left the element reporting `translate: 100%`
        while carrying `translate-x-0`, so it never actually moved. An inline
        style cannot lose to a stylesheet. --}}
<template x-teleport="body">
    <div x-effect="document.body.style.overflow = ({{ $open }}) ? 'hidden' : ''"
         x-on:keydown.escape.window="{{ $close }}">
        <div
            x-cloak
            x-on:click="{{ $close }}"
            x-bind:style="({{ $open }}) ? 'opacity: 1' : 'opacity: 0; pointer-events: none'"
            style="opacity: 0; pointer-events: none"
            class="fixed inset-0 z-50 bg-black/60 backdrop-blur-[2px] transition-opacity duration-200"
            aria-hidden="true"
        ></div>

        <div
            {{ $attributes->merge(['id' => 'rsc-slide-over']) }}
            x-cloak
            x-bind:style="({{ $open }}) ? 'translate: 0' : 'translate: 100%'"
            style="translate: 100%"
            x-bind:inert="! ({{ $open }})"
            x-bind:aria-hidden="({{ $open }}) ? 'false' : 'true'"
            role="dialog"
            aria-modal="true"
            @if ($heading) aria-label="{{ $heading }}" @endif
            class="fixed inset-y-0 end-0 z-50 flex w-[min(560px,94vw)] flex-col overflow-y-auto border-s border-line bg-panel transition-transform duration-300 ease-out"
        >
            {{ $slot }}
        </div>
    </div>
</template>
