<?php

use App\Models\StudioSetting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::rsc.marketing')]
#[Title('Cookies')]
class extends Component {
    /**
     * Get the studio's own details, so the contact line cannot go stale.
     */
    #[Computed]
    public function studio(): StudioSetting
    {
        return StudioSetting::current();
    }

    /**
     * Everything this site stores on a visitor's device.
     *
     * If you add anything that stores anything, it belongs in this list the
     * same day. Anything that is not strictly necessary has to be asked about
     * first, which is why nothing here needs a banner.
     *
     * @return array<int, array{name: string, purpose: string, when: string, life: string}>
     */
    #[Computed]
    public function cookies(): array
    {
        return [
            [
                'name' => (string) config('session.cookie'),
                'purpose' => __('Keeps you signed in and remembers where you are in the site. Without it the client area cannot work at all.'),
                'when' => __('Every visit'),
                'life' => trans_choice('{1}1 minute of inactivity|[2,*]:count minutes of inactivity', (int) config('session.lifetime'), ['count' => (int) config('session.lifetime')]),
            ],
            [
                'name' => 'XSRF-TOKEN',
                'purpose' => __('Proves a form was submitted by you on this site, and not by another site pretending to be you.'),
                'when' => __('Every visit'),
                'life' => __('Until you close the browser'),
            ],
            [
                'name' => 'remember_web_*',
                'purpose' => __('Keeps you signed in between visits.'),
                'when' => __('Only if you tick "Keep me signed in on this device"'),
                'life' => __('Until you log out'),
            ],
            [
                'name' => 'flux.appearance',
                'purpose' => __('Remembers whether you chose the light or the dark theme. It stays in your browser and is never sent to us.'),
                'when' => __('Only if you use the theme switch'),
                'life' => __('Until you clear your browser data'),
            ],
            [
                'name' => 'Cloudflare Turnstile',
                'purpose' => __('Checks that forms are being filled in by a person rather than a script. It does not profile you or follow you between sites.'),
                'when' => __('On the enquiry, sign-in and registration forms'),
                'life' => __('The length of the check'),
            ],
        ];
    }
}; ?>

<div class="mx-auto w-full max-w-[820px] px-[clamp(18px,5vw,64px)] py-[clamp(40px,7vw,96px)]">
    <a href="{{ route('home') }}" class="mb-[clamp(24px,3vw,38px)] inline-flex items-center gap-2 font-mono text-[11px] text-muted no-underline transition-colors hover:text-brand" wire:navigate>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" class="size-4" aria-hidden="true">
            <path d="M15 6l-6 6 6 6" />
        </svg>
        {{ __('back to the site') }}
    </a>

    <x-rsc.kicker class="mb-2.5">cookies</x-rsc.kicker>
    <x-rsc.heading>{{ __('What this site stores') }}</x-rsc.heading>

    <p class="mt-5 text-base leading-relaxed text-muted text-pretty">
        {{ __('Short version: nothing that follows you around. No Google Analytics, no advertising pixels, no third-party tracking of any kind. Everything below is either needed to make the site work or something you switched on yourself, which is why you are not asked to agree to any of it.') }}
    </p>

    <div class="mt-[clamp(28px,3.4vw,44px)] flex flex-col gap-[clamp(12px,1.4vw,18px)]">
        @foreach ($this->cookies as $cookie)
            <x-rsc.panel wire:key="cookie-{{ $loop->index }}">
                <div class="flex flex-wrap items-baseline gap-x-3.5 gap-y-2">
                    <span class="font-mono text-[13px] text-brand">{{ $cookie['name'] }}</span>
                    <span class="ms-auto font-mono text-[11px] text-muted">{{ $cookie['life'] }}</span>
                </div>
                <p class="mt-2.5 mb-0 text-sm leading-relaxed text-muted text-pretty">{{ $cookie['purpose'] }}</p>
                <p class="mt-2 mb-0 font-mono text-[11px] text-muted">{{ $cookie['when'] }}</p>
            </x-rsc.panel>
        @endforeach
    </div>

    <x-rsc.heading :level="2" class="mt-[clamp(34px,4vw,56px)] !text-[clamp(20px,2.4vw,28px)]">{{ __('Turning them off') }}</x-rsc.heading>
    <p class="mt-3.5 text-base leading-relaxed text-muted text-pretty">
        {{ __('Your browser can block or delete cookies for any site, this one included. Block the first two and the client area will not be able to sign you in — that is what they are for, rather than a threat.') }}
    </p>

    <x-rsc.heading :level="2" class="mt-[clamp(28px,3.4vw,44px)] !text-[clamp(20px,2.4vw,28px)]">{{ __('If we ever add analytics') }}</x-rsc.heading>
    <p class="mt-3.5 text-base leading-relaxed text-muted text-pretty">
        {{ __('We would ask you first, and it would stay off until you said yes. This page would say so on the day it changed.') }}
    </p>

    <div class="mt-[clamp(34px,4vw,56px)] border-t border-line pt-[clamp(20px,2.4vw,30px)] font-mono text-[11px] text-muted">
        <p class="m-0">{{ __('Last updated :date', ['date' => 'September 2026']) }}</p>
        <p class="mt-2 mb-0">
            {{ __('Questions about any of this:') }}
            <a href="mailto:{{ $this->studio->email }}" class="text-brand no-underline">{{ $this->studio->email }}</a>
        </p>
    </div>
</div>
