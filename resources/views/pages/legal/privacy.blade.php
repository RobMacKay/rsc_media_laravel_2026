<?php

use App\Models\StudioSetting;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::rsc.marketing')]
#[Title('Privacy')]
class extends Component {
    /**
     * Get the studio's own details, so the registered entity and the contact
     * address cannot drift from what is on the invoices.
     */
    #[Computed]
    public function studio(): StudioSetting
    {
        return StudioSetting::current();
    }

    /**
     * What is held, why, and for how long.
     *
     * @return array<int, array{title: string, body: string}>
     */
    #[Computed]
    public function sections(): array
    {
        return [
            [
                'title' => __('If you use the contact form'),
                'body' => __('We keep your name, email address, company and what you wrote, so that we can reply and so we have a record of what was asked. Nothing is passed to anyone else, and you are not added to any list — there is no mailing list to be added to.'),
            ],
            [
                'title' => __('If you have a client account'),
                'body' => __('We hold what you would expect a supplier to hold: who you are, your business details, your billing address, the projects and tickets you have raised, the files attached to them, and your invoices. It is there so we can do the work and bill for it.'),
            ],
            [
                'title' => __('Files you upload'),
                'body' => __('Attachments are stored privately on our server, never in a public folder. They can only be reached through your account, and only by you, your colleagues on the same account, and us.'),
            ],
            [
                'title' => __('Site monitoring'),
                'body' => __('If we watch a site for you, we record whether it answered, how quickly, and when its certificate expires. That is about the site, not about anyone visiting it.'),
            ],
            [
                'title' => __('Who else sees it'),
                'body' => __('Our hosting provider and our email provider, because the site has to run somewhere and email has to be sent by something. Nobody buys it, and nobody is given it for marketing.'),
            ],
            [
                'title' => __('How long we keep it'),
                'body' => __('Enquiries that do not turn into work are cleared out when they are no longer any use. Anything attached to invoices is kept for six years, because HMRC requires it. Close your account and we remove the rest.'),
            ],
            [
                'title' => __('What you can ask for'),
                'body' => __('A copy of what we hold about you, a correction, or its deletion. Ask and it gets done — there is no form and no department, just an email to the address below.'),
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

    <x-rsc.kicker class="mb-2.5">privacy</x-rsc.kicker>
    <x-rsc.heading>{{ __('What we hold, and why') }}</x-rsc.heading>

    <p class="mt-5 text-base leading-relaxed text-muted text-pretty">
        {{ __(':company is the controller of anything personal on this site. We hold what we need to do the work and nothing beyond it.', ['company' => $this->studio->company_name]) }}
    </p>

    <div class="mt-[clamp(28px,3.4vw,44px)] flex flex-col gap-[clamp(12px,1.4vw,18px)]">
        @foreach ($this->sections as $section)
            <x-rsc.panel wire:key="section-{{ $loop->index }}">
                <x-rsc.heading :level="2" class="!text-[clamp(17px,2vw,21px)]">{{ $section['title'] }}</x-rsc.heading>
                <p class="mt-2.5 mb-0 text-sm leading-relaxed text-muted text-pretty">{{ $section['body'] }}</p>
            </x-rsc.panel>
        @endforeach
    </div>

    <x-rsc.heading :level="2" class="mt-[clamp(34px,4vw,56px)] !text-[clamp(20px,2.4vw,28px)]">{{ __('Cookies') }}</x-rsc.heading>
    <p class="mt-3.5 text-base leading-relaxed text-muted text-pretty">
        {{ __('There is no tracking on this site, so you are not asked to agree to any.') }}
        <a href="{{ route('legal.cookies') }}" class="text-brand no-underline" wire:navigate>{{ __('Everything it does store is listed here') }}</a>.
    </p>

    <div class="mt-[clamp(34px,4vw,56px)] border-t border-line pt-[clamp(20px,2.4vw,30px)] font-mono text-[11px] text-muted">
        <p class="m-0">{{ $this->studio->company_name }}@if ($this->studio->company_number) · {{ $this->studio->company_number }}@endif</p>
        @foreach ($this->studio->addressLines() as $line)
            <p class="m-0">{{ $line }}</p>
        @endforeach
        <p class="mt-2 mb-0">
            <a href="mailto:{{ $this->studio->email }}" class="text-brand no-underline">{{ $this->studio->email }}</a>
        </p>
        <p class="mt-3.5 mb-0">{{ __('Unhappy with how we have handled something? You can complain to the Information Commissioner at ico.org.uk.') }}</p>
        <p class="mt-2 mb-0">{{ __('Last updated :date', ['date' => 'September 2026']) }}</p>
    </div>
</div>
