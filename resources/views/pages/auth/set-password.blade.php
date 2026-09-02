<?php

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts::rsc.plain')]
#[Title('Set your password')]
class extends Component {
    use PasswordValidationRules;

    public User $user;

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Refuse anyone whose password is already their own to change.
     *
     * The link is signed and time limited, and this is what stops it working
     * a second time once it has been used.
     */
    public function mount(User $user): void
    {
        abort_unless($user->must_set_password, 404);

        $this->user = $user;
    }

    /**
     * Set the password and sign this person in.
     */
    public function save(): void
    {
        abort_unless($this->user->must_set_password, 404);

        $validated = $this->validate(['password' => $this->passwordRules()]);

        $this->user->forceFill([
            'password' => $validated['password'],
            'must_set_password' => false,
            // The studio typed this address in and has just proved the person
            // reads it, so there is nothing left to verify.
            'email_verified_at' => $this->user->email_verified_at ?? now(),
        ])->save();

        Auth::login($this->user, remember: true);

        session()->regenerate();

        $this->redirectRoute($this->user->portalRoute(), navigate: true);
    }
}; ?>

{{-- The auth card component renders a whole page, so it cannot nest inside a
     Livewire component. This is the same card, as a single root element. --}}
<div class="flex flex-1 flex-col">
    <header class="flex flex-wrap items-center gap-x-6 gap-y-3 px-[clamp(16px,4vw,44px)] py-4">
        <a href="{{ route('home') }}" aria-label="{{ __('RSC Media home') }}" class="me-auto block" wire:navigate>
            <x-rsc.logo />
        </a>
        <x-rsc.theme-toggle />
    </header>

    <main class="mx-auto flex w-full max-w-[460px] flex-1 flex-col justify-center px-[clamp(16px,4vw,44px)] py-[clamp(30px,6vw,70px)]">
        <section class="animate-rsc-fade rounded-[22px] border border-line bg-panel p-[clamp(24px,3vw,38px)]">
            <x-rsc.kicker class="mb-4">client_area</x-rsc.kicker>
            <h1 class="m-0 font-display text-[clamp(26px,4vw,38px)] font-extrabold leading-tight tracking-[-0.035em]">
                {{ __('Set your password.') }}
            </h1>
            <p class="mt-3 text-sm leading-relaxed text-muted text-pretty">
                {{ __('Pick something only you know, and you are in. Signing in as :email.', ['email' => $user->email]) }}
            </p>

            <form wire:submit="save" class="mt-7 flex flex-col gap-[18px]">
                <x-rsc.field label="password" name="password">
                    <x-rsc.input type="password" wire:model="password" autocomplete="new-password"
                                 placeholder="{{ __('At least 10 characters') }}" />
                </x-rsc.field>

                <x-rsc.field label="confirm_password" name="password_confirmation">
                    <x-rsc.input type="password" wire:model="password_confirmation" autocomplete="new-password"
                                 placeholder="{{ __('Type it again') }}" />
                </x-rsc.field>

                <x-rsc.button type="submit" class="mt-1 w-full py-3.5">{{ __('Set password and sign in') }}</x-rsc.button>
            </form>
        </section>
    </main>
</div>
