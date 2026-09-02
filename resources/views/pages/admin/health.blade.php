<?php

use App\Actions\Sites\CheckSite;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Rules\PubliclyRoutableUrl;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::rsc.admin')]
#[Title('Site health')]
class extends Component {
    public bool $formOpen = false;

    public string $name = '';

    public string $url = '';

    public bool $watchSsh = false;

    public int $sshPort = 22;

    /**
     * Get the studio's own sites, which are the only ones editable here.
     *
     * @return Collection<int, Site>
     */
    #[Computed]
    public function ours(): Collection
    {
        return Site::query()->studioOwned()->orderBy('name')->get();
    }

    /**
     * Get every client's sites, grouped by the client they belong to.
     *
     * @return SupportCollection<string, Collection<int, Site>>
     */
    #[Computed]
    public function theirs(): SupportCollection
    {
        return Site::query()
            ->clientOwned()
            ->with('team')
            ->get()
            ->sortBy(fn (Site $site) => [$site->team?->name, $site->name])
            ->groupBy(fn (Site $site) => $site->ownerLabel());
    }

    /**
     * Get the counts across everything being watched.
     *
     * @return array{watched: int, down: int, expiring: int}
     */
    #[Computed]
    public function summary(): array
    {
        $all = $this->ours->concat($this->theirs->flatten());

        return [
            'watched' => $all->count(),
            'down' => $all->where('status', SiteStatus::Down)->count(),
            'expiring' => $all->filter(fn (Site $site) => $site->sslExpiringSoon())->count(),
        ];
    }

    /**
     * Open or close the form for adding one of the studio's own sites.
     */
    public function toggleForm(): void
    {
        $this->formOpen = ! $this->formOpen;
        $this->reset('name', 'url', 'watchSsh', 'sshPort');
        $this->resetValidation();
    }

    /**
     * Start watching one of the studio's own sites.
     */
    public function addSite(): void
    {
        $this->url = $this->normalise($this->url);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:255', 'url:http,https', new PubliclyRoutableUrl],
            'watchSsh' => ['boolean'],
            'sshPort' => ['required_if:watchSsh,true', 'integer', 'min:1', 'max:65535'],
        ]);

        $host = Str::lower((string) parse_url($validated['url'], PHP_URL_HOST));

        // SQL treats nulls as distinct, so the unique index does not cover the
        // studio's own list. Checked here instead.
        if (Site::query()->studioOwned()->where('host', $host)->exists()) {
            $this->addError('url', __('You are already watching :host.', ['host' => $host]));

            return;
        }

        Site::create([
            'team_id' => null,
            'name' => $validated['name'],
            'url' => rtrim($validated['url'], '/'),
            'host' => $host,
            'ssh_enabled' => $this->watchSsh,
            'ssh_port' => $this->sshPort,
        ]);

        $this->reset('name', 'url', 'watchSsh', 'sshPort');
        $this->formOpen = false;

        unset($this->ours, $this->summary);

        Flux::toast(variant: 'success', text: __('Now watching :host.', ['host' => $host]));
    }

    /**
     * Check one of the studio's own sites right now.
     */
    public function checkNow(int $siteId): void
    {
        $site = Site::query()->studioOwned()->findOrFail($siteId);

        app(CheckSite::class)->handle($site);

        unset($this->ours, $this->summary);

        Flux::toast(variant: 'success', text: __('Checked :host.', ['host' => $site->host]));
    }

    /**
     * Stop watching one of the studio's own sites.
     *
     * Scoped to studioOwned so a client's site cannot be removed from here,
     * whatever id is posted.
     */
    public function removeSite(int $siteId): void
    {
        $site = Site::query()->studioOwned()->findOrFail($siteId);

        $site->delete();

        unset($this->ours, $this->summary);

        Flux::toast(variant: 'success', text: __('Stopped watching :host.', ['host' => $site->host]));
    }

    /**
     * Add a scheme when someone types a bare domain.
     */
    private function normalise(string $url): string
    {
        $url = trim($url);

        return $url === '' || Str::startsWith($url, ['http://', 'https://'])
            ? $url
            : 'https://'.ltrim($url, '/');
    }
}; ?>

<div wire:poll.60s>
    <div class="mb-[clamp(20px,2.4vw,30px)] flex flex-wrap items-end justify-between gap-5">
        <div>
            <x-rsc.kicker class="mb-2.5">monitoring</x-rsc.kicker>
            <x-rsc.heading class="!text-[clamp(28px,4vw,46px)]">{{ __('Site health') }}</x-rsc.heading>
            <p class="mt-3 flex flex-wrap items-center gap-2.5 text-[15px] text-muted">
                @if ($this->summary['down'] > 0)
                    <x-rsc.pill tone="warm">{{ trans_choice('{1}1 site down|[2,*]:count sites down', $this->summary['down'], ['count' => $this->summary['down']]) }}</x-rsc.pill>
                @else
                    <x-rsc.pill tone="brand">{{ __('Everything is answering') }}</x-rsc.pill>
                @endif
                @if ($this->summary['expiring'] > 0)
                    <x-rsc.pill tone="warm">{{ trans_choice('{1}1 certificate expiring|[2,*]:count certificates expiring', $this->summary['expiring'], ['count' => $this->summary['expiring']]) }}</x-rsc.pill>
                @endif
                <span class="font-mono text-[11px]">{{ trans_choice('{0}nothing watched|{1}1 site watched|[2,*]:count sites watched', $this->summary['watched'], ['count' => $this->summary['watched']]) }}</span>
            </p>
        </div>

        <x-rsc.button wire:click="toggleForm" class="px-[26px] py-3.5">
            {{ $formOpen ? __('Close form') : __('Add one of ours') }}
        </x-rsc.button>
    </div>

    @if ($formOpen)
        <x-rsc.panel accent class="mb-[clamp(16px,2vw,24px)] animate-rsc-fade !p-[clamp(22px,2.6vw,34px)]">
            <div class="grid items-end gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                <x-rsc.field label="what_it_is" name="name">
                    <x-rsc.input wire:model="name" placeholder="{{ __('RSC Media site') }}" class="!py-3" />
                </x-rsc.field>
                <x-rsc.field label="address" name="url" hint="{{ __('We will assume https if you leave it off.') }}">
                    <x-rsc.input wire:model="url" placeholder="rscmedia.co.uk" class="!py-3" />
                </x-rsc.field>
                <x-rsc.button wire:click="addSite" class="!py-3.5">{{ __('Start watching') }}</x-rsc.button>
            </div>

            <div class="mt-[18px] flex flex-wrap items-center gap-x-5 gap-y-3 border-t border-line pt-4">
                <label class="flex cursor-pointer items-center gap-2.5 text-[13px] text-muted">
                    <input type="checkbox" wire:model.live="watchSsh" class="size-4" style="accent-color: var(--rsc-accent)">
                    <span>{{ __('Also check SSH is answering') }}</span>
                </label>
                @if ($watchSsh)
                    <label class="flex items-center gap-2.5 text-[13px] text-muted">
                        <span class="font-mono text-[11px]">port</span>
                        <x-rsc.input type="number" min="1" max="65535" wire:model="sshPort" class="!w-24 !py-2" />
                    </label>
                @endif
                @error('sshPort') <span class="text-xs text-warm">{{ $message }}</span> @enderror
            </div>
        </x-rsc.panel>
    @endif

    <div class="mb-2.5 font-mono text-[11px] tracking-[0.08em] text-muted">ours</div>

    @forelse ($this->ours as $site)
        @include('pages.admin.partials.site-row', ['site' => $site, 'editable' => true])
    @empty
        <x-rsc.panel class="mb-[clamp(12px,1.4vw,18px)] !p-[clamp(18px,2.2vw,26px)]">
            <p class="m-0 text-[15px] text-muted">{{ __('None of your own sites are being watched yet.') }}</p>
        </x-rsc.panel>
    @endforelse

    <div class="mt-[clamp(22px,2.6vw,32px)] mb-2.5 flex flex-wrap items-baseline gap-3 font-mono text-[11px] tracking-[0.08em] text-muted">
        <span>clients</span>
        <span class="normal-case tracking-normal">{{ __('read only — they manage these themselves') }}</span>
    </div>

    @forelse ($this->theirs as $client => $sites)
        <div class="mb-[clamp(12px,1.4vw,18px)]" wire:key="client-{{ str($client)->slug() }}">
            <div class="mb-2 font-display text-[15px] font-bold tracking-[-0.015em]">{{ $client }}</div>
            @foreach ($sites as $site)
                @include('pages.admin.partials.site-row', ['site' => $site, 'editable' => false])
            @endforeach
        </div>
    @empty
        <x-rsc.panel class="!p-[clamp(18px,2.2vw,26px)]">
            <p class="m-0 text-[15px] text-muted">{{ __('No client has added a site yet.') }}</p>
        </x-rsc.panel>
    @endforelse
</div>
