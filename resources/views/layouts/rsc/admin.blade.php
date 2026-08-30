@php
    $nav = collect([
        ['label' => 'queue', 'route' => 'admin.queue'],
        ['label' => 'jobs', 'route' => 'admin.jobs'],
        ['label' => 'invoices', 'route' => 'admin.invoices'],
        ['label' => 'settings', 'route' => 'admin.settings'],
    ])->map(fn (array $link) => [
        'label' => $link['label'],
        'url' => route($link['route']),
        'active' => request()->routeIs($link['route']),
    ])->all();
@endphp

<x-layouts::rsc.portal
    :title="$title ?? null"
    :nav="$nav"
    badge="admin"
    badge-tone="warm"
    footer-label="RSC Media — admin"
    wide
>
    <x-slot:footer-action>
        <a href="{{ route('client.dashboard') }}" class="no-underline" wire:navigate>view the client side →</a>
    </x-slot:footer-action>

    <x-slot:identity>
        <div class="grid size-8 place-items-center rounded-full font-display text-xs font-bold text-brand rsc-tint">RSC</div>
    </x-slot:identity>

    {{ $slot }}
</x-layouts::rsc.portal>
