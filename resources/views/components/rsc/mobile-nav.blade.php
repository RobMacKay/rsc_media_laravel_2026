@props(['links' => []])

{{-- Below `lg` the header cannot hold the nav, the theme switch and the account on
     one line, so everything moves into a drawer that slides in from the left.

     Two things to keep in mind if you touch this:

     1. The panel is teleported to <body>. The header sets `backdrop-filter`,
        which makes it a containing block for `position: fixed` children, so a
        drawer left inside it is positioned against the header, not the viewport.
     2. It is animated by binding classes rather than x-show with x-transition:
        Alpine's class-based transitions leave the element stuck at display:none
        in this build. --}}
<div
    x-data="{
        open: false,
        close() { this.open = false },
    }"
    x-on:keydown.escape.window="close()"
    x-effect="document.body.style.overflow = open ? 'hidden' : ''"
    class="lg:hidden"
>
    <button
        type="button"
        x-on:click="open = true"
        x-bind:aria-expanded="open ? 'true' : 'false'"
        aria-controls="rsc-mobile-nav"
        aria-label="{{ __('Open menu') }}"
        class="grid size-9 cursor-pointer place-items-center rounded-full border border-line text-muted transition-colors hover:border-brand hover:text-brand"
    >
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" class="size-[18px]" aria-hidden="true">
            <path d="M4 7h16M4 12h16M4 17h16" />
        </svg>
    </button>

    <template x-teleport="body">
    <div class="lg:hidden">
    <div
        x-cloak
        x-on:click="close()"
        x-bind:class="open ? 'opacity-100' : 'pointer-events-none opacity-0'"
        class="fixed inset-0 z-50 bg-black/60 backdrop-blur-[2px] transition-opacity duration-200"
        aria-hidden="true"
    ></div>

    <div
        id="rsc-mobile-nav"
        x-cloak
        x-bind:class="open ? 'translate-x-0' : '-translate-x-full'"
        x-bind:inert="! open"
        x-bind:aria-hidden="open ? 'false' : 'true'"
        role="dialog"
        aria-modal="true"
        aria-label="{{ __('Menu') }}"
        class="fixed inset-y-0 start-0 z-50 flex w-[min(320px,85vw)] flex-col gap-6 overflow-y-auto border-e border-line bg-panel px-6 py-5 transition-transform duration-300 ease-out"
    >
        <div class="flex items-center justify-between gap-4">
            <x-rsc.logo />

            <button
                type="button"
                x-on:click="close()"
                aria-label="{{ __('Close menu') }}"
                class="grid size-9 cursor-pointer place-items-center rounded-full border border-line text-muted transition-colors hover:border-brand hover:text-brand"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" class="size-[18px]" aria-hidden="true">
                    <path d="M6 6l12 12M18 6L6 18" />
                </svg>
            </button>
        </div>

        <nav class="flex flex-col">
            @foreach ($links as $link)
                <a
                    href="{{ $link['url'] }}"
                    @if ($link['navigate'] ?? false) wire:navigate @endif
                    x-on:click="close()"
                    @if ($link['active'] ?? false) aria-current="page" @endif
                    @class([
                        'border-b border-line py-3.5 font-mono text-sm no-underline transition-colors',
                        'text-brand' => $link['active'] ?? false,
                        'text-muted hover:text-body' => ! ($link['active'] ?? false),
                    ])
                >{{ $link['label'] }}</a>
            @endforeach
        </nav>

        @isset($footer)
            <div class="mt-auto flex flex-col gap-4 border-t border-line pt-5">
                {{ $footer }}
            </div>
        @endisset
    </div>
    </div>
    </template>
</div>
