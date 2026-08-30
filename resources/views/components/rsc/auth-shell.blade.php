@props(['tab' => 'login', 'heroTitle', 'heroBody', 'points'])

<x-layouts::rsc.plain :title="$title ?? null">
    <header class="flex flex-wrap items-center gap-x-6 gap-y-3 px-[clamp(16px,4vw,44px)] py-4">
        <a href="{{ route('home') }}" aria-label="{{ __('RSC Media home') }}" class="me-auto block" wire:navigate>
            <x-rsc.logo />
        </a>
        <x-rsc.theme-toggle />
    </header>

    <main class="mx-auto grid w-full max-w-[1180px] flex-1 items-center gap-[clamp(30px,5vw,80px)] px-[clamp(16px,4vw,44px)] pb-[clamp(50px,7vw,90px)] pt-[clamp(24px,4vw,60px)] [grid-template-columns:repeat(auto-fit,minmax(340px,1fr))]">
        <div class="max-w-[34ch]">
            <x-rsc.kicker class="mb-4">client_area</x-rsc.kicker>
            <h1 class="m-0 font-display text-[clamp(34px,5vw,62px)] font-extrabold leading-[0.98] tracking-[-0.04em] text-balance">{{ $heroTitle }}</h1>
            <p class="mt-5 text-base leading-relaxed text-muted text-pretty">{{ $heroBody }}</p>

            <div class="mt-[34px] flex flex-col gap-3.5 border-t border-line pt-[26px]">
                @foreach ($points as $mark => $text)
                    <div class="grid grid-cols-[auto_1fr] items-baseline gap-3">
                        <span class="font-mono text-[11px] text-brand">{{ $mark }}</span>
                        <span class="text-sm text-muted">{{ $text }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <section class="animate-rsc-fade rounded-[22px] border border-line bg-panel p-[clamp(24px,3vw,38px)]">
            <div class="mb-[26px] flex gap-1.5 rounded-full border border-line p-1">
                @foreach ([['login', __('log in'), route('login')], ['register', __('create account'), route('register')]] as [$key, $label, $url])
                    <a href="{{ $url }}" wire:navigate @class([
                        'flex-1 rounded-full px-3.5 py-2.5 text-center font-mono text-xs no-underline transition-colors duration-200',
                        'bg-brand text-accent-ink' => $tab === $key,
                        'text-muted hover:text-body' => $tab !== $key,
                    ])>{{ $label }}</a>
                @endforeach
            </div>

            {{ $slot }}
        </section>
    </main>

    <footer class="flex flex-wrap items-center justify-between gap-x-[30px] gap-y-2.5 border-t border-line px-[clamp(16px,4vw,44px)] py-5 font-mono text-[11px] text-muted">
        <span>{{ __('RSC Media — client area') }}</span>
        <span>{{ __('Locked out? WhatsApp Ross on 07700 900 118') }}</span>
    </footer>
</x-layouts::rsc.plain>
