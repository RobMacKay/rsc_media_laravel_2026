<?php

use App\Enums\ClientAccess;
use App\Enums\TeamRole;
use App\Models\Membership;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Notifications\Teams\TeamInvitation as TeamInvitationNotification;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::rsc.client')]
#[Title('Your team')]
class extends Component {
    public bool $inviteOpen = false;

    public bool $inviteSent = false;

    public string $name = '';

    public string $email = '';

    public string $jobTitle = '';

    public string $access = 'tickets';

    /**
     * Get the business the signed-in person is looking at.
     */
    #[Computed]
    public function team(): Team
    {
        return Auth::user()->currentTeam;
    }

    /**
     * Get the people with access, followed by anyone still to accept an invite.
     *
     * @return Collection<int, Membership>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->team->memberships()->with('user')->get();
    }

    /**
     * Get the invitations that have been sent but not yet taken up.
     *
     * @return Collection<int, TeamInvitation>
     */
    #[Computed]
    public function pendingInvitations(): Collection
    {
        return $this->team->invitations()
            ->whereNull('accepted_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>=', now()))
            ->get();
    }

    /**
     * Get the "3 people with access, 1 invite pending" summary line.
     */
    #[Computed]
    public function summary(): string
    {
        $people = $this->members->count();
        $pending = $this->pendingInvitations->count();

        return trans_choice('{1}One person with access|[2,*]:count people with access', $people, ['count' => $people])
            .', '.trans_choice('{0}no invites pending|{1}one invite pending|[2,*]:count invites pending', $pending, ['count' => $pending]);
    }

    /**
     * Open or close the invite form.
     */
    public function toggleInvite(): void
    {
        $this->inviteOpen = ! $this->inviteOpen;
        $this->inviteSent = false;
    }

    /**
     * Close the invite form and clear the confirmation.
     */
    public function closeInvite(): void
    {
        $this->inviteOpen = false;
        $this->inviteSent = false;
    }

    /**
     * Invite someone from the client's business to the portal.
     */
    public function invite(): void
    {
        abort_unless(Auth::user()->accessFor()->canManageTeam(), 403);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'jobTitle' => ['nullable', 'string', 'max:255'],
            'access' => ['required', Rule::enum(ClientAccess::class)],
        ]);

        $invitation = $this->team->invitations()->create([
            'email' => $validated['email'],
            'role' => TeamRole::Member,
            'invited_by' => Auth::id(),
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $validated['email'])
            ->notify(new TeamInvitationNotification($invitation));

        $this->reset('name', 'email', 'jobTitle');
        $this->inviteSent = true;

        unset($this->pendingInvitations, $this->summary);

        Flux::toast(variant: 'success', text: __('Invite sent to :email.', ['email' => $invitation->email]));
    }
}; ?>

<div>
    <div class="mb-[clamp(22px,2.6vw,34px)] flex flex-wrap items-end justify-between gap-5">
        <div>
            <x-rsc.kicker class="mb-2.5">{{ str($this->team->name)->snake()->lower() }}</x-rsc.kicker>
            <x-rsc.heading>{{ __('Your team') }}</x-rsc.heading>
            <p class="mt-3 text-[15px] text-muted">{{ $this->summary }}</p>
        </div>

        @if (auth()->user()->accessFor()->canManageTeam())
            <x-rsc.button wire:click="toggleInvite" class="px-[26px] py-3.5">
                {{ $inviteOpen ? __('Close form') : __('Add a person') }}
            </x-rsc.button>
        @endif
    </div>

    @if ($inviteOpen && auth()->user()->accessFor()->canManageTeam())
        <x-rsc.panel accent class="mb-[clamp(16px,2vw,24px)] animate-rsc-fade !p-[clamp(22px,2.6vw,34px)]">
            @if ($inviteSent)
                <div class="flex flex-col gap-3.5 py-3.5">
                    <x-rsc.kicker>invite_sent</x-rsc.kicker>
                    <div class="font-display text-[clamp(22px,2.6vw,32px)] font-extrabold tracking-[-0.03em]">{{ __('Invite on its way.') }}</div>
                    <p class="m-0 max-w-[52ch] text-[15px] text-muted">
                        {{ __('It expires in seven days. They\'ll show as pending in the list below until they set a password.') }}
                    </p>
                    <x-rsc.button variant="outline" wire:click="closeInvite" class="self-start !px-[22px] !py-2.5 !text-sm">
                        {{ __('Back to team') }}
                    </x-rsc.button>
                </div>
            @else
                <form wire:submit="invite" class="flex flex-col gap-5">
                    <x-rsc.kicker tone="muted">add_a_person</x-rsc.kicker>

                    <div class="grid gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(200px,1fr))]">
                        <x-rsc.field label="name" name="name">
                            <x-rsc.input wire:model="name" placeholder="Callum Reid" />
                        </x-rsc.field>
                        <x-rsc.field label="email" name="email">
                            <x-rsc.input type="email" wire:model="email" placeholder="callum@{{ str($this->team->name)->slug() }}.co.uk" />
                        </x-rsc.field>
                        <x-rsc.field label="job_title" name="jobTitle">
                            <x-rsc.input wire:model="jobTitle" placeholder="{{ __('Optional') }}" />
                        </x-rsc.field>
                    </div>

                    <div>
                        <span class="mb-2 block font-mono text-[11px] text-muted">access</span>
                        <div class="flex flex-wrap gap-2">
                            @foreach (ClientAccess::cases() as $level)
                                <x-rsc.chip wire:click="$set('access', '{{ $level->value }}')" :active="$access === $level->value" class="!text-[13px] !font-sans">
                                    {{ $level->label() }}
                                </x-rsc.chip>
                            @endforeach
                        </div>
                        <p class="mt-2.5 text-xs text-muted">{{ ClientAccess::from($access)->hint() }}</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-4">
                        <x-rsc.button type="submit" class="px-[30px] py-3.5">{{ __('Send invite') }}</x-rsc.button>
                        <span class="text-[13px] text-muted">{{ __('They get an email with a code to set their own password.') }}</span>
                    </div>
                </form>
            @endif
        </x-rsc.panel>
    @endif

    <div class="grid gap-[clamp(12px,1.4vw,18px)] [grid-template-columns:repeat(auto-fit,minmax(300px,1fr))]">
        @foreach ($this->members as $membership)
            @php
                $isYou = $membership->user_id === auth()->id();
                $tone = $isYou ? 'brand' : 'muted';
            @endphp
            <div class="flex flex-col gap-4 rounded-[18px] border border-line bg-panel p-[clamp(20px,2.2vw,28px)]">
                <div class="flex items-start gap-3.5">
                    <div class="grid size-[42px] flex-none place-items-center rounded-full font-display text-sm font-bold"
                         @style([
                             'color: var(--rsc-accent); background: color-mix(in srgb, var(--rsc-accent) 20%, transparent)' => $isYou,
                             'color: var(--rsc-text); background: color-mix(in srgb, var(--rsc-text) 9%, transparent)' => ! $isYou,
                         ])>{{ $membership->user->initials() }}</div>
                    <div class="min-w-0 flex-1">
                        <div class="font-display text-[17px] font-bold tracking-[-0.02em]">{{ $membership->user->name }}</div>
                        <div class="mt-0.5 text-[13px] text-muted">{{ $membership->job_title ?? $membership->role->label() }}</div>
                    </div>
                    <x-rsc.pill :tone="$tone" class="flex-none !text-[10px] !tracking-[0.08em]">{{ $isYou ? 'you' : 'active' }}</x-rsc.pill>
                </div>
                <div class="text-[13px] break-words text-muted">{{ $membership->user->email }}</div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2.5 border-t border-line pt-3.5 font-mono text-[11px]">
                    <span class="me-auto text-muted">{{ $membership->access->label() }}</span>
                </div>
            </div>
        @endforeach

        @foreach ($this->pendingInvitations as $invitation)
            <div class="flex flex-col gap-4 rounded-[18px] border border-line bg-panel p-[clamp(20px,2.2vw,28px)]">
                <div class="flex items-start gap-3.5">
                    <div class="grid size-[42px] flex-none place-items-center rounded-full font-display text-sm font-bold text-warm rsc-tint-warm">
                        {{ str($invitation->email)->substr(0, 2)->upper() }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-display text-[17px] font-bold tracking-[-0.02em]">{{ str($invitation->email)->before('@') }}</div>
                        <div class="mt-0.5 text-[13px] text-muted">{{ __('Invited :when', ['when' => $invitation->created_at->diffForHumans()]) }}</div>
                    </div>
                    <x-rsc.pill tone="warm" class="flex-none !text-[10px] !tracking-[0.08em]">pending</x-rsc.pill>
                </div>
                <div class="text-[13px] break-words text-muted">{{ $invitation->email }}</div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2.5 border-t border-line pt-3.5 font-mono text-[11px]">
                    <span class="me-auto text-muted">{{ __('Expires :when', ['when' => $invitation->expires_at?->format('j M')]) }}</span>
                </div>
            </div>
        @endforeach
    </div>
</div>
