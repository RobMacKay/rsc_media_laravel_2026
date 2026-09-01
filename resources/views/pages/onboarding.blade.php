<?php

use App\Enums\ClientAccess;
use App\Enums\ContactPreference;
use App\Enums\NotificationTopic;
use App\Enums\TeamRole;
use App\Models\StudioSetting;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::rsc.plain')]
#[Title('Getting set up')]
class extends Component {
    public string $step = 'company';

    /** @var array<string, bool> */
    public array $done = [];

    // Step one — the business, which is what goes on its invoices.
    public string $tradingName = '';

    public string $companyNumber = '';

    public string $address = '';

    public string $vatNumber = '';

    public string $billingEmail = '';

    public string $systems = '';

    // Step two — the person signing in.
    public string $fullName = '';

    public string $jobTitle = '';

    public string $email = '';

    public string $phone = '';

    public string $contact = 'email';

    /** @var array<string, bool> */
    public array $notifications = [];

    // Step three — inviting the rest of their staff.
    public string $inviteName = '';

    public string $inviteEmail = '';

    public string $inviteAccess = 'tickets';

    /**
     * Fill the form from what we already know, so nobody retypes their own name.
     */
    public function mount(): void
    {
        $user = Auth::user();
        $team = $user->currentTeam;

        $this->fullName = $user->name;
        $this->email = $user->email;
        $this->phone = (string) $user->phone;
        $this->contact = $user->contact_preference->value;
        $this->notifications = $user->notificationChoices();

        $this->tradingName = $team?->name ?? '';
        $this->companyNumber = (string) $team?->company_number;
        $this->address = (string) $team?->address;
        $this->vatNumber = (string) $team?->vat_number;
        $this->billingEmail = (string) ($team?->billing_email ?? $user->email);
        $this->systems = collect($team?->systems ?? [])->join("\n");

        $this->step = $this->steps[0]['key'];
    }

    /**
     * Get the business being set up.
     */
    #[Computed]
    public function team(): ?Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Get the studio's settings, for the intro video.
     */
    #[Computed]
    public function settings(): StudioSetting
    {
        return StudioSetting::current();
    }

    /**
     * Determine whether this person sets up the business, or only themselves.
     *
     * Someone who joined on an invite has an owner who already did steps one
     * and three, so showing them those would be asking them to redo it.
     */
    #[Computed]
    public function runsTheBusiness(): bool
    {
        return Auth::user()->accessFor()->canManageTeam();
    }

    /**
     * Get the steps this person is being asked for.
     *
     * @return array<int, array{key: string, num: string, label: string}>
     */
    #[Computed]
    public function steps(): array
    {
        if (! $this->runsTheBusiness) {
            return [['key' => 'you', 'num' => '01', 'label' => 'your_details']];
        }

        return [
            ['key' => 'company', 'num' => '01', 'label' => 'company'],
            ['key' => 'you', 'num' => '02', 'label' => 'your_details'],
            ['key' => 'team', 'num' => '03', 'label' => 'team'],
        ];
    }

    /**
     * Get the "Step 2 of 3" line for the step being shown.
     */
    #[Computed]
    public function position(): string
    {
        $keys = collect($this->steps)->pluck('key');

        return __('Step :number of :total', [
            'number' => $keys->search($this->step) + 1,
            'total' => $keys->count(),
        ]);
    }

    /**
     * Get the line next to the finish button, saying what is still pending.
     */
    #[Computed]
    public function teamNote(): string
    {
        $pending = $this->people->where('status', 'invited')->count();

        return $pending === 0
            ? __('You can add people later from the team tab')
            : trans_choice(
                '{1}One invite has gone out|[2,*]:count invites have gone out',
                $pending,
                ['count' => $pending],
            );
    }

    /**
     * Get everyone already on the account, and anyone still to accept.
     *
     * @return Collection<int, array{name: string, email: string, access: string, status: string}>
     */
    #[Computed]
    public function people(): Collection
    {
        if (! $this->team) {
            return new Collection;
        }

        $members = $this->team->memberships()->with('user')->get()
            ->map(fn ($membership) => [
                'name' => $membership->user->name,
                'email' => $membership->user->email,
                'access' => $membership->access->label(),
                'status' => $membership->user_id === Auth::id() ? 'you' : 'active',
            ]);

        $invited = $this->team->invitations()
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->get()
            ->map(fn (TeamInvitation $invitation) => [
                'name' => $invitation->name ?? str($invitation->email)->before('@')->toString(),
                'email' => $invitation->email,
                'access' => $invitation->access?->label() ?? '',
                'status' => 'invited',
            ]);

        return $members->concat($invited)->values();
    }

    /**
     * Move to a step without saving, which is what the tabs across the top do.
     */
    public function go(string $step): void
    {
        abort_unless(collect($this->steps)->pluck('key')->contains($step), 404);

        $this->step = $step;

        $this->dispatch('onboarding-step-changed');
    }

    /**
     * Set how this person would rather be contacted.
     */
    public function chooseContact(string $contact): void
    {
        $this->contact = ContactPreference::from($contact)->value;
    }

    /**
     * Turn an email topic on or off.
     */
    public function toggleNotification(string $topic): void
    {
        $key = NotificationTopic::from($topic)->value;

        $this->notifications[$key] = ! ($this->notifications[$key] ?? false);
    }

    /**
     * Save the business, then move on.
     */
    public function saveCompany(): void
    {
        abort_unless($this->runsTheBusiness && $this->team, 403);

        $validated = $this->validate([
            'tradingName' => ['required', 'string', 'max:255'],
            'companyNumber' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'vatNumber' => ['nullable', 'string', 'max:255'],
            'billingEmail' => ['nullable', 'email', 'max:255'],
            'systems' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->team->update([
            'name' => $validated['tradingName'],
            'company_number' => $validated['companyNumber'] ?: null,
            'address' => $validated['address'] ?: null,
            'vat_number' => $validated['vatNumber'] ?: null,
            'billing_email' => $validated['billingEmail'] ?: null,
            'systems' => $this->lines($validated['systems'] ?? ''),
        ]);

        unset($this->team);

        $this->complete('company', 'you');
    }

    /**
     * Save this person's own details, then move on.
     */
    public function saveYou(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'fullName' => ['required', 'string', 'max:255'],
            'jobTitle' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'contact' => ['required', Rule::enum(ContactPreference::class)],
        ]);

        $user->forceFill([
            'name' => $validated['fullName'],
            'phone' => $validated['phone'] ?: null,
            'contact_preference' => $validated['contact'],
            'notification_preferences' => $this->notifications,
        ])->save();

        if ($this->team) {
            $this->team->memberships()
                ->where('user_id', $user->id)
                ->update(['job_title' => $validated['jobTitle'] ?: null]);
        }

        $this->complete('you', 'team');
    }

    /**
     * Invite someone else from the business.
     */
    public function invite(): void
    {
        abort_unless($this->runsTheBusiness && $this->team, 403);

        $validated = $this->validate([
            'inviteName' => ['required', 'string', 'max:255'],
            'inviteEmail' => ['required', 'email', 'max:255'],
            'inviteAccess' => ['required', Rule::enum(ClientAccess::class)],
        ]);

        $invitation = $this->team->invitations()->create([
            'email' => $validated['inviteEmail'],
            'name' => $validated['inviteName'],
            'role' => TeamRole::Member,
            'access' => $validated['inviteAccess'],
            'invited_by' => Auth::id(),
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $validated['inviteEmail'])
            ->notify(new TeamInvitationNotification($invitation));

        $this->reset('inviteName', 'inviteEmail');
        $this->done['team'] = true;

        unset($this->people);

        Flux::toast(variant: 'success', text: __('Invite sent to :email.', ['email' => $invitation->email]));
    }

    /**
     * Mark the wizard done and go to the portal.
     */
    public function finish(): void
    {
        Auth::user()->forceFill(['onboarded_at' => now()])->save();

        $this->redirectRoute('client.dashboard', navigate: true);
    }

    /**
     * Split a textarea into trimmed lines.
     *
     * @return array<int, string>
     */
    private function lines(string $value): array
    {
        return collect(explode("\n", $value))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Tick a step off and move to the next one, or finish if it was the last.
     */
    private function complete(string $step, string $next): void
    {
        $this->done[$step] = true;

        $keys = collect($this->steps)->pluck('key');

        if (! $keys->contains($next)) {
            $this->finish();

            return;
        }

        $this->go($next);
    }
}; ?>

{{-- Each step scrolls to the step nav rather than the top of the page: with
     an intro video above it, scrolling to the top puts the form off screen. --}}
<div x-data
     x-on:onboarding-step-changed.window="$refs.steps?.scrollIntoView({ behavior: 'smooth', block: 'start' })"
     class="flex flex-1 flex-col">

    <header class="flex flex-wrap items-center gap-x-7 gap-y-3 border-b border-line px-[clamp(16px,4vw,44px)] py-3.5">
        <x-rsc.logo />
        <x-rsc.pill>getting_set_up</x-rsc.pill>
        <button type="button" wire:click="finish"
                class="ms-auto cursor-pointer font-mono text-[11px] text-muted transition-colors hover:text-brand">
            {{ __('skip for now') }}
        </button>
    </header>

    <main class="mx-auto w-full max-w-[1080px] animate-rsc-fade px-[clamp(16px,4vw,44px)] pt-[clamp(26px,4vw,52px)] pb-[clamp(60px,8vw,110px)]">

        <div class="mb-[clamp(24px,3vw,38px)]">
            <x-rsc.kicker class="mb-2.5">welcome</x-rsc.kicker>
            <x-rsc.heading>{{ __("Let's get you set up") }}</x-rsc.heading>
            <p class="mt-3.5 max-w-[58ch] text-[16px] leading-relaxed text-muted text-pretty">
                {{ $this->runsTheBusiness
                    ? __('Three short steps. Two minutes, and you can change any of it later from your settings.')
                    : __('One short step, and you can change any of it later from your settings.') }}
            </p>
        </div>

        @if ($this->settings->welcome_video_url)
            <x-rsc.panel padded="false" class="mb-[clamp(18px,2.2vw,28px)] overflow-hidden">
                <div class="relative aspect-video bg-ink">
                    <iframe src="{{ $this->settings->welcome_video_url }}"
                            title="{{ __('Introduction from Ross') }}"
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                            allowfullscreen
                            class="absolute inset-0 block size-full border-0"></iframe>
                </div>
                <div class="flex flex-wrap items-center gap-x-5 gap-y-2.5 border-t border-line px-[22px] py-4">
                    <div class="font-display text-base font-bold">{{ __('A quick hello from Ross') }}</div>
                    <div class="text-sm text-muted">{{ __('What the portal does, and how to get hold of me. 90 seconds.') }}</div>
                </div>
            </x-rsc.panel>
        @endif

        <div x-ref="steps" class="scroll-mt-5"></div>

        @if (count($this->steps) > 1)
            <nav class="mb-[clamp(18px,2.2vw,26px)] flex w-fit max-w-full flex-nowrap gap-1.5 overflow-x-auto rounded-full border border-line p-1.5 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                @foreach ($this->steps as $tab)
                    @php $on = $step === $tab['key']; @endphp
                    <button type="button" wire:click="go('{{ $tab['key'] }}')"
                            class="flex flex-none cursor-pointer items-center gap-2 rounded-full px-3.5 py-2.5 font-mono text-xs sm:gap-2.5 sm:px-[18px] transition-colors duration-200 {{ $on ? 'bg-brand text-accent-ink' : 'text-muted hover:text-body' }}">
                        <span class="opacity-60">{{ $tab['num'] }}</span>
                        <span>{{ $tab['label'] }}</span>
                        @if ($done[$tab['key']] ?? false)
                            <span class="{{ $on ? 'text-accent-ink' : 'text-brand' }}">&check;</span>
                        @endif
                    </button>
                @endforeach
            </nav>
        @endif

        @if ($step === 'company')
            <x-rsc.panel class="animate-rsc-fade !p-[clamp(24px,3vw,40px)]">
                <x-rsc.heading :level="2">{{ __('Your company') }}</x-rsc.heading>
                <p class="mt-2.5 mb-[clamp(22px,2.6vw,30px)] max-w-[56ch] text-[15px] text-muted">
                    {{ __('This is what goes on your invoices, so worth getting right.') }}
                </p>

                <div class="grid gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(230px,1fr))]">
                    <x-rsc.field label="trading_name" name="tradingName">
                        <x-rsc.input wire:model="tradingName" placeholder="Braemar Joinery" />
                    </x-rsc.field>
                    <x-rsc.field label="company_number" name="companyNumber">
                        <x-rsc.input wire:model="companyNumber" placeholder="{{ __('SC000000 — leave blank if sole trader') }}" />
                    </x-rsc.field>
                    <x-rsc.field label="billing_address" name="address" class="[grid-column:1/-1]">
                        <x-rsc.textarea rows="3" wire:model="address" placeholder="Unit 4, Lochy Industrial Estate&#10;Fort William&#10;PH33 6TQ" />
                    </x-rsc.field>
                    <x-rsc.field label="vat_number" name="vatNumber">
                        <x-rsc.input wire:model="vatNumber" placeholder="{{ __('GB000000000 — optional') }}" />
                    </x-rsc.field>
                    <x-rsc.field label="invoices_go_to" name="billingEmail">
                        <x-rsc.input type="email" wire:model="billingEmail" placeholder="accounts@braemarjoinery.co.uk" />
                    </x-rsc.field>
                    <x-rsc.field label="what_we_look_after — one per line" name="systems" class="[grid-column:1/-1]"
                                 hint="{{ __('These become the options when you raise a ticket.') }}">
                        <x-rsc.textarea rows="3" wire:model="systems" placeholder="braemarjoinery.co.uk&#10;Quote and job tracker&#10;Trade counter stock pages" />
                    </x-rsc.field>
                </div>

                <div class="mt-[clamp(24px,2.8vw,32px)] flex flex-wrap items-center gap-3.5 border-t border-line pt-[22px]">
                    <x-rsc.button wire:click="saveCompany">{{ __('Save and continue') }}</x-rsc.button>
                    <span class="text-[13px] text-muted">{{ $this->position }}</span>
                </div>
            </x-rsc.panel>
        @endif

        @if ($step === 'you')
            <x-rsc.panel class="animate-rsc-fade !p-[clamp(24px,3vw,40px)]">
                <x-rsc.heading :level="2">{{ __('Your details') }}</x-rsc.heading>
                <p class="mt-2.5 mb-[clamp(22px,2.6vw,30px)] max-w-[56ch] text-[15px] text-muted">
                    {{ __("So we know who we're speaking to, and where to send things.") }}
                </p>

                <div class="grid gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(230px,1fr))]">
                    <x-rsc.field label="full_name" name="fullName">
                        <x-rsc.input wire:model="fullName" placeholder="Kirsty Munro" />
                    </x-rsc.field>
                    <x-rsc.field label="job_title" name="jobTitle">
                        <x-rsc.input wire:model="jobTitle" placeholder="Office Manager" />
                    </x-rsc.field>
                    <x-rsc.field label="email"
                                 hint="{{ __('What you sign in with. Change it in your profile settings, where we can verify the new address.') }}">
                        <x-rsc.input type="email" value="{{ $email }}" readonly class="cursor-not-allowed opacity-70" />
                    </x-rsc.field>
                    <x-rsc.field label="direct_number" name="phone">
                        <x-rsc.input wire:model="phone" placeholder="01397 000000" />
                    </x-rsc.field>
                </div>

                <div class="mt-[clamp(24px,2.8vw,32px)]">
                    <span class="mb-2.5 block font-mono text-[11px] text-muted">best_way_to_reach_you</span>
                    <div class="flex flex-wrap gap-2">
                        @foreach (ContactPreference::cases() as $option)
                            <x-rsc.chip wire:click="chooseContact('{{ $option->value }}')"
                                        :active="$contact === $option->value"
                                        class="!font-sans !text-sm">{{ $option->label() }}</x-rsc.chip>
                        @endforeach
                    </div>
                </div>

                <div class="mt-[clamp(20px,2.4vw,28px)]">
                    <span class="mb-2.5 block font-mono text-[11px] text-muted">email_me_about</span>
                    <div class="flex flex-col">
                        @foreach (NotificationTopic::cases() as $topic)
                            @php $on = $notifications[$topic->value] ?? false; @endphp
                            <button type="button" wire:click="toggleNotification('{{ $topic->value }}')"
                                    role="switch" aria-checked="{{ $on ? 'true' : 'false' }}"
                                    class="flex w-full cursor-pointer items-center gap-3.5 border-b border-line bg-transparent px-0.5 py-3.5 text-left">
                                <span class="relative h-[22px] w-[38px] flex-none rounded-full transition-colors duration-200 {{ $on ? 'bg-brand' : 'bg-[var(--rsc-line)]' }}">
                                    <span class="absolute top-[3px] size-4 rounded-full transition-[left] duration-200 {{ $on ? 'left-[19px] bg-[var(--rsc-on-accent)]' : 'left-[3px] bg-[var(--rsc-muted)]' }}"></span>
                                </span>
                                <span class="flex flex-col gap-0.5">
                                    <span class="font-display text-[15px] font-semibold text-body">{{ $topic->label() }}</span>
                                    <span class="text-[13px] text-muted">{{ $topic->note() }}</span>
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="mt-[clamp(24px,2.8vw,32px)] flex flex-wrap items-center gap-3.5 border-t border-line pt-[22px]">
                    <x-rsc.button wire:click="saveYou">
                        {{ count($this->steps) > 1 ? __('Save and continue') : __('Save and go to my portal') }}
                    </x-rsc.button>
                    @if ($this->runsTheBusiness)
                        <x-rsc.button variant="outline" wire:click="go('company')">{{ __('Back') }}</x-rsc.button>
                    @endif
                    <span class="text-[13px] text-muted">{{ $this->position }}</span>
                </div>
            </x-rsc.panel>
        @endif

        @if ($step === 'team')
            <x-rsc.panel class="animate-rsc-fade !p-[clamp(24px,3vw,40px)]">
                <x-rsc.heading :level="2">{{ __('Your team') }}</x-rsc.heading>
                <p class="mt-2.5 mb-[clamp(22px,2.6vw,30px)] max-w-[56ch] text-[15px] text-muted">
                    {{ __('Anyone who might need to raise a ticket. They get an email invite that lasts seven days.') }}
                </p>

                <div class="mb-[22px] flex flex-col gap-2.5">
                    @foreach ($this->people as $person)
                        @php
                            $isYou = $person['status'] === 'you';
                            $pending = $person['status'] === 'invited';
                            $tone = $pending ? 'warm' : ($isYou ? 'brand' : 'muted');
                        @endphp
                        <div class="flex flex-wrap items-center gap-3.5 rounded-2xl border border-line px-[18px] py-4"
                             wire:key="person-{{ $loop->index }}">
                            <span class="grid size-[38px] flex-none place-items-center rounded-full font-mono text-xs"
                                  style="background: color-mix(in srgb, {{ $pending ? 'var(--rsc-warm)' : ($isYou ? 'var(--rsc-accent)' : 'var(--rsc-text)') }} {{ $pending || $isYou ? '16%' : '9%' }}, transparent); color: {{ $pending ? 'var(--rsc-warm)' : ($isYou ? 'var(--rsc-accent)' : 'var(--rsc-text)') }}">
                                {{ str($person['name'])->squish()->explode(' ')->take(2)->map(fn (string $word) => mb_substr($word, 0, 1))->join('') }}
                            </span>
                            <div class="min-w-0">
                                <div class="font-display text-[15px] font-bold">{{ $person['name'] }}</div>
                                <div class="text-[13px] text-muted">{{ $person['email'] }}</div>
                            </div>
                            <x-rsc.pill :tone="$tone" class="ms-auto whitespace-nowrap">
                                {{ $pending ? __('invite sent') : ($isYou ? __('you') : __('active')) }}
                            </x-rsc.pill>
                            @if ($person['access'])
                                <span class="whitespace-nowrap text-[13px] text-muted">{{ $person['access'] }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>

                <div class="grid items-end gap-4 rounded-2xl border border-dashed border-line p-5 [grid-template-columns:repeat(auto-fit,minmax(180px,1fr))]">
                    <x-rsc.field label="name" name="inviteName">
                        <x-rsc.input wire:model="inviteName" placeholder="Alan Munro" />
                    </x-rsc.field>
                    <x-rsc.field label="email" name="inviteEmail">
                        <x-rsc.input type="email" wire:model="inviteEmail" placeholder="alan@braemarjoinery.co.uk" />
                    </x-rsc.field>
                    <x-rsc.field label="access" name="inviteAccess">
                        <x-rsc.select wire:model="inviteAccess">
                            @foreach (ClientAccess::cases() as $access)
                                <option value="{{ $access->value }}">{{ $access->label() }}</option>
                            @endforeach
                        </x-rsc.select>
                    </x-rsc.field>
                    <x-rsc.button wire:click="invite" class="!rounded-xl !bg-[color-mix(in_srgb,var(--rsc-accent)_16%,transparent)] !text-brand">
                        {{ __('Send invite') }}
                    </x-rsc.button>
                </div>

                <div class="mt-5 flex flex-wrap gap-x-[22px] gap-y-3.5 text-[13px] text-muted">
                    @foreach (ClientAccess::cases() as $access)
                        <span><strong class="font-semibold text-body">{{ $access->label() }}</strong> — {{ $access->hint() }}</span>
                    @endforeach
                </div>

                <div class="mt-[clamp(24px,2.8vw,32px)] flex flex-wrap items-center gap-3.5 border-t border-line pt-[22px]">
                    <x-rsc.button wire:click="finish">{{ __('Finish and go to my portal') }}</x-rsc.button>
                    <x-rsc.button variant="outline" wire:click="go('you')">{{ __('Back') }}</x-rsc.button>
                    <span class="text-[13px] text-muted">{{ $this->teamNote }}</span>
                </div>
            </x-rsc.panel>
        @endif

        <section class="mt-[clamp(28px,3.4vw,44px)]">
            <x-rsc.kicker tone="muted" class="mb-3.5 !tracking-[0.1em]">once_you_are_in</x-rsc.kicker>
            <div class="grid gap-[clamp(12px,1.4vw,18px)] [grid-template-columns:repeat(auto-fit,minmax(240px,1fr))]">
                @foreach ([
                    [__('Raise a ticket'), __('Something broken or a small change. Tickets tab, describe it, we come back with a time quote.')],
                    [__('Join or change a plan'), __('Monthly support with included hours and a response time. Change it whenever, starts the first of the month.')],
                    [__('Propose a project'), __('Anything bigger than a ticket. Tell us roughly what you need, we write up scope and price for you to sign off.')],
                ] as [$title, $blurb])
                    <x-rsc.panel class="!rounded-[18px] !p-[22px]">
                        <x-rsc.heading :level="3">{{ $title }}</x-rsc.heading>
                        <p class="mt-2 text-sm leading-[1.55] text-muted">{{ $blurb }}</p>
                    </x-rsc.panel>
                @endforeach
            </div>
        </section>

    </main>
</div>
