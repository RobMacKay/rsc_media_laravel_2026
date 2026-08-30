@props(['heading', 'description' => null])

<x-layouts::rsc.plain :title="$title ?? null">
    <header class="flex flex-wrap items-center gap-x-6 gap-y-3 px-[clamp(16px,4vw,44px)] py-4">
        <a href="{{ route('home') }}" aria-label="{{ __('RSC Media home') }}" class="me-auto block" wire:navigate>
            <x-rsc.logo />
        </a>
        <x-rsc.theme-toggle />
    </header>

    <main class="mx-auto flex w-full max-w-[460px] flex-1 flex-col justify-center px-[clamp(16px,4vw,44px)] py-[clamp(30px,6vw,70px)]">
        <section class="animate-rsc-fade rounded-[22px] border border-line bg-panel p-[clamp(24px,3vw,38px)]">
            <x-rsc.kicker class="mb-4">client_area</x-rsc.kicker>
            <h1 class="m-0 font-display text-[clamp(26px,4vw,38px)] font-extrabold leading-tight tracking-[-0.035em]">{{ $heading }}</h1>

            @if ($description)
                <p class="mt-3 text-sm leading-relaxed text-muted text-pretty">{{ $description }}</p>
            @endif

            @if (session('status'))
                <p class="mt-5 rounded-xl border border-line px-4 py-3 text-sm text-brand">{{ session('status') }}</p>
            @endif

            <div class="mt-6">{{ $slot }}</div>
        </section>
    </main>

    <footer class="flex flex-wrap items-center justify-between gap-x-[30px] gap-y-2.5 border-t border-line px-[clamp(16px,4vw,44px)] py-5 font-mono text-[11px] text-muted">
        <span>{{ __('RSC Media — client area') }}</span>
        <span>{{ __('Locked out? WhatsApp Ross on 07700 900 118') }}</span>
    </footer>
</x-layouts::rsc.plain>
