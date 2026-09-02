{{-- One monitored site. `editable` is false for anything a client owns: the
     studio watches those but does not touch them. The component methods are
     scoped to studioOwned as well, so this is presentation, not the guard. --}}
<x-rsc.panel class="mb-[clamp(10px,1.2vw,14px)] !p-[clamp(16px,2vw,22px)]" wire:key="site-{{ $site->id }}">
    <div class="flex flex-wrap items-start justify-between gap-x-5 gap-y-3">
        <div class="min-w-0">
            <div class="mb-1.5 flex flex-wrap items-center gap-2.5">
                <x-rsc.pill :tone="$site->status->tone()">{{ str($site->status->label())->lower() }}</x-rsc.pill>
                @if ($site->sslExpiringSoon())
                    <x-rsc.pill tone="warm">{{ __('certificate') }}</x-rsc.pill>
                @endif
            </div>
            <div class="font-display text-[17px] font-bold tracking-[-0.02em]">{{ $site->name }}</div>
            <a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer"
               class="mt-1 block font-mono text-[11px] text-muted no-underline hover:text-brand">{{ $site->host }}</a>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <x-rsc.button variant="outline" href="{{ route('client.health.log', $site) }}" as="a"
                          class="!px-4 !py-2.5 !text-[13px] no-underline">
                {{ __('Log') }}
            </x-rsc.button>
            @if ($editable)
                <x-rsc.button variant="outline" wire:click="checkNow({{ $site->id }})" class="!px-4 !py-2.5 !text-[13px]">
                    {{ __('Check now') }}
                </x-rsc.button>
                <button type="button" wire:click="removeSite({{ $site->id }})"
                        wire:confirm="{{ __('Stop watching :host?', ['host' => $site->host]) }}"
                        class="cursor-pointer bg-transparent p-0 font-mono text-[11px] text-muted transition-colors hover:text-warm">
                    {{ __('remove') }}
                </button>
            @endif
        </div>
    </div>

    <div class="mt-4 grid gap-x-6 gap-y-3.5 border-t border-line pt-3.5 [grid-template-columns:repeat(auto-fit,minmax(140px,1fr))]">
        @foreach ([
            ['last_checked', $site->last_checked_at?->diffForHumans() ?? __('Not yet'), 'var(--rsc-text)'],
            ['response', $site->response_ms === null ? '—' : $site->response_ms.'ms', 'var(--rsc-text)'],
            ['certificate', $site->sslLabel(), $site->sslExpiringSoon() || $site->ssl_valid === false ? 'var(--rsc-warm)' : 'var(--rsc-text)'],
            ['uptime_30d', $site->uptimePercent() === null ? '—' : rtrim(rtrim(number_format($site->uptimePercent(), 2), '0'), '.').'%', 'var(--rsc-text)'],
            ['ssh_'.$site->ssh_port, $site->sshLabel(), $site->ssh_enabled && $site->ssh_ok === false ? 'var(--rsc-warm)' : ($site->ssh_enabled ? 'var(--rsc-text)' : 'var(--rsc-muted)')],
        ] as [$label, $value, $colour])
            <span class="flex flex-col gap-1.5">
                <span class="font-mono text-[11px] text-muted">{{ $label }}</span>
                <span class="font-display text-sm font-bold" style="color: {{ $colour }}">{{ $value }}</span>
            </span>
        @endforeach
    </div>

    @if ($site->status === \App\Enums\SiteStatus::Down && $site->last_error)
        <p class="mt-3.5 mb-0 rounded-xl border border-warm px-4 py-3 text-[13px] text-warm">{{ $site->last_error }}</p>
    @endif
</x-rsc.panel>
