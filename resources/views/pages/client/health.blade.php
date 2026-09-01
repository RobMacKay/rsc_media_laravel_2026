<?php

use App\Actions\Sites\CheckSite;
use App\Enums\SiteStatus;
use App\Models\Site;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Rules\PubliclyRoutableUrl;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::rsc.client')]
#[Title('Site health')]
class extends Component {
    public bool $formOpen = false;

    public string $name = '';

    public string $url = '';

    /**
     * Get the business whose sites these are.
     */
    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Get the studio's settings, for the site allowance.
     */
    #[Computed]
    public function settings(): StudioSetting
    {
        return StudioSetting::current();
    }

    /**
     * Get the sites being watched.
     *
     * @return Collection<int, Site>
     */
    #[Computed]
    public function sites(): Collection
    {
        return $this->team->sites()->get();
    }

    /**
     * Get how many sites this client may add in total.
     */
    #[Computed]
    public function allowance(): int
    {
        return $this->team->effectiveSiteLimit($this->settings);
    }

    /**
     * Determine whether there is room for another site.
     */
    #[Computed]
    public function hasRoom(): bool
    {
        return $this->sites->count() < $this->allowance;
    }

    /**
     * Get the headline counts across every site.
     *
     * @return array{up: int, down: int, expiring: int}
     */
    #[Computed]
    public function summary(): array
    {
        return [
            'up' => $this->sites->where('status', SiteStatus::Up)->count(),
            'down' => $this->sites->where('status', SiteStatus::Down)->count(),
            'expiring' => $this->sites->filter(fn (Site $site) => $site->sslExpiringSoon())->count(),
        ];
    }

    /**
     * Open or close the add form.
     */
    public function toggleForm(): void
    {
        $this->formOpen = ! $this->formOpen;
        $this->reset('name', 'url');
        $this->resetValidation();
    }

    /**
     * Start watching another site.
     */
    public function addSite(): void
    {
        abort_unless(Auth::user()->accessFor()->canRaiseTickets(), 403);

        // Checked again here rather than trusting the button being hidden.
        abort_unless($this->hasRoom, 422);

        $this->url = $this->normalise($this->url);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'string', 'max:255', 'url:http,https', new PubliclyRoutableUrl],
        ]);

        $host = Str::lower((string) parse_url($validated['url'], PHP_URL_HOST));

        if ($this->team->sites()->where('host', $host)->exists()) {
            $this->addError('url', __('You are already watching :host.', ['host' => $host]));

            return;
        }

        $this->team->sites()->create([
            'name' => $validated['name'],
            'url' => rtrim($validated['url'], '/'),
            'host' => $host,
        ]);

        $this->reset('name', 'url');
        $this->formOpen = false;

        unset($this->sites, $this->summary, $this->hasRoom);

        Flux::toast(variant: 'success', text: __('Now watching :host. The first check runs within fifteen minutes.', ['host' => $host]));
    }

    /**
     * Check one site right now, rather than waiting for the schedule.
     */
    public function checkNow(int $siteId): void
    {
        abort_unless(Auth::user()->accessFor()->canRaiseTickets(), 403);

        $site = $this->team->sites()->findOrFail($siteId);

        app(CheckSite::class)->handle($site);

        unset($this->sites, $this->summary);

        Flux::toast(variant: 'success', text: __('Checked :host.', ['host' => $site->host]));
    }

    /**
     * Stop watching a site.
     */
    public function removeSite(int $siteId): void
    {
        abort_unless(Auth::user()->accessFor()->canRaiseTickets(), 403);

        $site = $this->team->sites()->findOrFail($siteId);

        $site->delete();

        unset($this->sites, $this->summary, $this->hasRoom);

        Flux::toast(variant: 'success', text: __('Stopped watching :host.', ['host' => $site->host]));
    }

    /**
     * Add a scheme when someone types a bare domain, which most people do.
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
    <div class="mb-[clamp(22px,2.6vw,34px)] flex flex-wrap items-end justify-between gap-5">
        <div>
            <x-rsc.kicker class="mb-2.5">monitoring</x-rsc.kicker>
            <x-rsc.heading>{{ __('Site health') }}</x-rsc.heading>
            <p class="mt-3 flex flex-wrap items-center gap-2.5 text-[15px] text-muted">
                @if ($this->sites->isEmpty())
                    {{ __('Add a site and we will check it every fifteen minutes.') }}
                @else
                    @if ($this->summary['down'] > 0)
                        <x-rsc.pill tone="warm">{{ trans_choice('{1}1 site is down|[2,*]:count sites are down', $this->summary['down'], ['count' => $this->summary['down']]) }}</x-rsc.pill>
                    @else
                        <x-rsc.pill tone="brand">{{ __('Everything is answering') }}</x-rsc.pill>
                    @endif
                    @if ($this->summary['expiring'] > 0)
                        <x-rsc.pill tone="warm">{{ trans_choice('{1}1 certificate expiring|[2,*]:count certificates expiring', $this->summary['expiring'], ['count' => $this->summary['expiring']]) }}</x-rsc.pill>
                    @endif
                @endif
            </p>
        </div>

        @if (auth()->user()->accessFor()->canRaiseTickets() && $this->hasRoom)
            <x-rsc.button wire:click="toggleForm" class="px-[26px] py-3.5">
                {{ $formOpen ? __('Close form') : __('Add a site') }}
            </x-rsc.button>
        @endif
    </div>

    @if ($formOpen && $this->hasRoom)
        <x-rsc.panel accent class="mb-[clamp(16px,2vw,24px)] animate-rsc-fade !p-[clamp(22px,2.6vw,34px)]">
            <div class="grid items-end gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(220px,1fr))]">
                <x-rsc.field label="what_it_is" name="name">
                    <x-rsc.input wire:model="name" placeholder="{{ __('Main website') }}" />
                </x-rsc.field>
                <x-rsc.field label="address" name="url"
                             hint="{{ __('We will assume https if you leave it off.') }}">
                    <x-rsc.input wire:model="url" placeholder="braemarjoinery.co.uk" />
                </x-rsc.field>
                <x-rsc.button wire:click="addSite" class="!py-3.5">{{ __('Start watching') }}</x-rsc.button>
            </div>
            <p class="mt-4 mb-0 text-[13px] text-muted">
                {{ trans_choice(
                    '{0}You have used all :allowance of your sites.|{1}One more site after this one.|[2,*]:count more sites after this one.',
                    $this->allowance - $this->sites->count() - 1,
                    ['count' => $this->allowance - $this->sites->count() - 1, 'allowance' => $this->allowance],
                ) }}
            </p>
        </x-rsc.panel>
    @endif

    @forelse ($this->sites as $site)
        <x-rsc.panel class="mb-[clamp(12px,1.4vw,18px)] !p-[clamp(18px,2.2vw,26px)]" wire:key="site-{{ $site->id }}">
            <div class="flex flex-wrap items-start justify-between gap-x-5 gap-y-3">
                <div class="min-w-0">
                    <div class="mb-1.5 flex flex-wrap items-center gap-2.5">
                        <x-rsc.pill :tone="$site->status->tone()">{{ str($site->status->label())->lower() }}</x-rsc.pill>
                        @if ($site->sslExpiringSoon())
                            <x-rsc.pill tone="warm">{{ __('certificate') }}</x-rsc.pill>
                        @endif
                    </div>
                    <x-rsc.heading :level="2" class="!text-[clamp(18px,2vw,24px)]">{{ $site->name }}</x-rsc.heading>
                    <a href="{{ $site->url }}" target="_blank" rel="noopener noreferrer"
                       class="mt-1 block font-mono text-[11px] text-muted no-underline hover:text-brand">{{ $site->host }}</a>
                </div>

                <div class="flex flex-wrap items-center gap-2.5">
                    <x-rsc.button variant="outline" href="{{ route('client.health.log', $site) }}" as="a"
                                  class="!px-4 !py-2.5 !text-[13px] no-underline">
                        {{ __('Download log') }}
                    </x-rsc.button>
                    @if (auth()->user()->accessFor()->canRaiseTickets())
                        <x-rsc.button variant="outline" wire:click="checkNow({{ $site->id }})"
                                      class="!px-4 !py-2.5 !text-[13px]">
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

            <div class="mt-5 grid gap-x-6 gap-y-4 border-t border-line pt-4 [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
                @foreach ([
                    ['last_checked', $site->last_checked_at?->diffForHumans() ?? __('Not yet'), 'var(--rsc-text)'],
                    ['response', $site->response_ms === null ? '—' : $site->response_ms.'ms', 'var(--rsc-text)'],
                    ['certificate', $site->sslLabel(), $site->sslExpiringSoon() || $site->ssl_valid === false ? 'var(--rsc-warm)' : 'var(--rsc-text)'],
                    ['uptime_30d', $site->uptimePercent() === null ? '—' : rtrim(rtrim(number_format($site->uptimePercent(), 2), '0'), '.').'%', 'var(--rsc-text)'],
                ] as [$label, $value, $colour])
                    <span class="flex flex-col gap-1.5">
                        <span class="font-mono text-[11px] text-muted">{{ $label }}</span>
                        <span class="font-display text-[15px] font-bold" style="color: {{ $colour }}">{{ $value }}</span>
                    </span>
                @endforeach
            </div>

            @if ($site->status === SiteStatus::Down && $site->last_error)
                <p class="mt-4 mb-0 rounded-xl border border-warm px-4 py-3 text-[13px] text-warm">
                    {{ $site->last_error }}
                </p>
            @endif
        </x-rsc.panel>
    @empty
        <x-rsc.panel class="!p-[clamp(24px,3vw,40px)]">
            <x-rsc.heading :level="2">{{ __('Nothing being watched yet') }}</x-rsc.heading>
            <p class="mt-3 mb-0 max-w-[60ch] text-[15px] text-muted">
                {{ __('Add up to :count sites and we will check each one every fifteen minutes: whether it answers, how quickly, and how long its security certificate has left. If one stops answering we will email you.', ['count' => $this->allowance]) }}
            </p>
        </x-rsc.panel>
    @endforelse

    @if ($this->sites->isNotEmpty())
        <div class="mt-3.5 font-mono text-[11px] text-muted">
            {{ __(':used of :allowance sites', ['used' => $this->sites->count(), 'allowance' => $this->allowance]) }}
        </div>
    @endif
</div>
