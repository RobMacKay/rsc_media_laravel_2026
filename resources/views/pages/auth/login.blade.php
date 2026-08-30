<x-rsc.auth-shell
    :title="__('Log in')"
    tab="login"
    :hero-title="__('Everything on your projects, in one place.')"
    :hero-body="__('Project progress, open tickets and anything I need from you. Sign in with the email address your welcome note came to.')"
    :points="[
        'a' => __('Raise a ticket without digging through email threads'),
        'b' => __('See where a build is up to, and what it is waiting on'),
        'c' => __('Your support hours and next invoice, always current'),
    ]"
>
    @if (session('status'))
        <p class="mb-5 text-center text-sm text-brand">{{ session('status') }}</p>
    @endif

    @if ($teamInvitation)
        <p class="mb-5 rounded-xl border border-line px-4 py-3 text-sm text-muted">
            {{ __('You have been invited to :team. Log in to accept it.', ['team' => $teamInvitation['teamName']]) }}
        </p>
    @endif

    <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-[18px]">
        @csrf

        <x-rsc.field label="email" name="email">
            <x-rsc.input type="email" name="email" :value="old('email')" required autofocus
                         autocomplete="email" placeholder="you@yourbusiness.co.uk" />
        </x-rsc.field>

        <label class="block">
            <span class="mb-2 flex items-baseline justify-between font-mono text-[11px] text-muted">
                <span>password</span>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="text-[11px] no-underline" wire:navigate>{{ __('forgotten?') }}</a>
                @endif
            </span>
            <x-rsc.input type="password" name="password" required autocomplete="current-password" placeholder="••••••••••" />
            @error('password')
                <span class="mt-2 block text-xs text-warm">{{ $message }}</span>
            @enderror
        </label>

        <label class="flex cursor-pointer items-center gap-2.5 text-sm text-muted">
            <input type="checkbox" name="remember" checked class="size-4" style="accent-color: var(--rsc-accent)">
            <span>{{ __('Keep me signed in on this device') }}</span>
        </label>

        <x-rsc.button type="submit" data-test="login-button" class="!px-[26px] !py-[15px]">{{ __('Log in') }}</x-rsc.button>

        <p class="m-0 text-center text-[13px] text-muted">
            {{ __('No account yet?') }}
            <a href="{{ $teamInvitation ? route('register', ['invitation' => $teamInvitation['code']]) : route('register') }}"
               class="no-underline" data-test="register-link" wire:navigate>{{ __('Create one') }}</a>.
        </p>
    </form>
</x-rsc.auth-shell>
