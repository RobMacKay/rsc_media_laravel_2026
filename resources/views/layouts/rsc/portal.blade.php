@props([
    'badge' => 'client_area',
    'badgeTone' => 'muted',
    'nav' => [],
    'footerLabel' => 'RSC Media — client area',
    'footerNote' => 'Urgent? WhatsApp Ross on 07700 900 118',
    'wide' => false,
    'title' => null,
])

<x-layouts::rsc.base :title="$title ?? null">
    <div class="relative min-h-screen">
        <div aria-hidden="true" class="pointer-events-none fixed inset-0 z-0 opacity-45 rsc-hatch"></div>

        <div class="relative z-1 flex min-h-screen flex-col">
            <header class="sticky top-0 z-40 flex flex-wrap items-center gap-x-7 gap-y-3 border-b border-line px-[clamp(16px,4vw,44px)] py-3.5 backdrop-blur-[14px]"
                    style="background: color-mix(in srgb, var(--rsc-ink) 84%, transparent)">
                <a href="{{ route('home') }}" aria-label="{{ __('RSC Media home') }}">
                    <x-rsc.logo />
                </a>

                <span @class([
                    'rounded-full border px-2.5 py-1 font-mono text-[11px] tracking-[0.1em]',
                    'border-line text-muted' => $badgeTone === 'muted',
                    'text-warm rsc-tint-warm' => $badgeTone === 'warm',
                ]) @style([
                    'border-color: color-mix(in srgb, var(--rsc-warm) 45%, transparent)' => $badgeTone === 'warm',
                ])>{{ $badge }}</span>

                <nav class="ms-auto hidden gap-1.5 rounded-full border border-line p-1 lg:flex">
                    @foreach ($nav as $item)
                        <a href="{{ $item['url'] }}" wire:navigate @class([
                            'rounded-full px-4 py-[7px] font-mono text-xs transition-colors duration-200',
                            'bg-brand text-accent-ink' => $item['active'],
                            'text-muted hover:text-body' => ! $item['active'],
                        ])>{{ $item['label'] }}</a>
                    @endforeach
                </nav>

                <div class="hidden items-center gap-3 lg:flex">
                    <x-rsc.theme-toggle />

                    @if (isset($identity))
                        <div class="flex items-center gap-2.5 border-s border-line ps-3">{{ $identity }}</div>
                    @endif
                </div>

                <div class="ms-auto lg:hidden">
                    <x-rsc.mobile-nav :links="collect($nav)->map(fn (array $item) => [...$item, 'navigate' => true])->all()">
                        <x-slot:footer>
                            @if (isset($identity))
                                <div class="flex items-center gap-2.5">{{ $identity }}</div>
                            @endif

                            <x-rsc.theme-toggle class="self-start" />
                        </x-slot:footer>
                    </x-rsc.mobile-nav>
                </div>
            </header>

            <main class="mx-auto w-full {{ $wide ? 'max-w-[1480px]' : 'max-w-[1280px]' }} flex-1 animate-rsc-fade px-[clamp(16px,4vw,44px)] pb-[clamp(60px,8vw,110px)] pt-[clamp(26px,4vw,52px)]">
                {{ $slot }}
            </main>

            <footer class="flex flex-wrap items-center justify-between gap-x-8 gap-y-3 border-t border-line px-[clamp(16px,4vw,44px)] py-[22px] font-mono text-[11px] text-muted">
                <span>{{ $footerLabel }}</span>
                @isset($footerAction)
                    {{ $footerAction }}
                @else
                    <span>{{ $footerNote }}</span>
                @endisset
            </footer>
        </div>
    </div>
</x-layouts::rsc.base>
