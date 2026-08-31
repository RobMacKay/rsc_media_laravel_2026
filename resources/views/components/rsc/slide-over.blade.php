@props(['open' => 'false', 'close' => '', 'heading' => null])

{{-- A detail panel that slides in from the right on small screens and becomes a
     centred dialog from `lg` up.

     Same two traps as the nav drawer, for the same reasons: it is teleported to
     <body> so no `backdrop-filter` ancestor can turn it into the containing
     block for `position: fixed`, and it animates by binding classes rather than
     x-show with x-transition. --}}
<template x-teleport="body">
    <div x-effect="document.body.style.overflow = ({{ $open }}) ? 'hidden' : ''">
        <div
            x-cloak
            x-on:click="{{ $close }}"
            x-bind:class="({{ $open }}) ? 'opacity-100' : 'pointer-events-none opacity-0'"
            class="fixed inset-0 z-50 bg-black/60 backdrop-blur-[2px] transition-opacity duration-200"
            aria-hidden="true"
        ></div>

        <div
            x-cloak
            x-bind:class="({{ $open }}) ? 'opacity-100' : 'pointer-events-none opacity-0'"
            class="fixed inset-0 z-50 flex transition-opacity duration-200 lg:items-center lg:justify-center lg:p-6"
            x-on:click.self="{{ $close }}"
        >
            <div
                x-bind:class="({{ $open }}) ? 'translate-x-0' : 'translate-x-full lg:translate-x-0'"
                x-bind:inert="! ({{ $open }})"
                x-bind:aria-hidden="({{ $open }}) ? 'false' : 'true'"
                role="dialog"
                aria-modal="true"
                @if ($heading) aria-label="{{ $heading }}" @endif
                class="ms-auto flex h-full w-[min(460px,92vw)] flex-col overflow-y-auto border-s border-line bg-panel transition-transform duration-300 ease-out lg:m-0 lg:h-auto lg:max-h-[85vh] lg:w-[min(760px,92vw)] lg:rounded-[22px] lg:border lg:shadow-[0_24px_80px_rgba(0,0,0,0.45)]"
            >
                {{ $slot }}
            </div>
        </div>
    </div>
</template>
