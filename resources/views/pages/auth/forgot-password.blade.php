<x-rsc.auth-card
    :title="__('Forgot password')"
    :heading="__('Forgotten your password?')"
    :description="__('Put in the email address you sign in with and we\'ll send you a link to set a new one.')"
>
    <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-[18px]">
        @csrf

        <x-rsc.field label="email" name="email">
            <x-rsc.input type="email" name="email" required autofocus placeholder="you@yourbusiness.co.uk" />
        </x-rsc.field>

        <x-rsc.button type="submit" data-test="email-password-reset-link-button" class="!px-[26px] !py-[15px]">
            {{ __('Email password reset link') }}
        </x-rsc.button>

        <p class="m-0 text-center text-[13px] text-muted">
            {{ __('Or, return to') }} <a href="{{ route('login') }}" class="no-underline" wire:navigate>{{ __('log in') }}</a>.
        </p>
    </form>
</x-rsc.auth-card>
