@php
    $user = auth()->user();
    $team = $user?->currentTeam;
    $access = $user?->accessFor();

    $links = [
        ['label' => 'dashboard', 'route' => 'client.dashboard'],
        ['label' => 'tickets', 'route' => 'client.tickets'],
        ['label' => 'team', 'route' => 'client.team'],
        ['label' => 'plan', 'route' => 'client.plan'],
    ];

    if ($access?->canSeeBilling()) {
        $links[] = ['label' => 'invoices', 'route' => 'client.invoices'];
    }

    $nav = collect($links)
        ->map(fn (array $link) => [
            'label' => $link['label'],
            'url' => route($link['route']),
            'active' => request()->routeIs($link['route']),
        ])
        ->all();
@endphp

<x-layouts::rsc.portal :title="$title ?? null" :nav="$nav" badge="client_area">
    <x-slot:identity>
        <div class="grid size-8 place-items-center rounded-full font-display text-xs font-bold text-brand rsc-tint">
            {{ $user?->initials() }}
        </div>
        <div class="leading-[1.25]">
            <div class="font-display text-[13px] font-bold">{{ $user?->name }}</div>
            <div class="text-[11px] text-muted">{{ $team?->name }}</div>
        </div>
    </x-slot:identity>

    {{ $slot }}
</x-layouts::rsc.portal>
