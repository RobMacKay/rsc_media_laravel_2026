<x-rsc.auth-card
    :title="__('Reset password')"
    :heading="__('Set a new password.')"
    :description="__('Choose something you have not used elsewhere. Ten characters or more.')"
>
    <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-[18px]">
        @csrf
        <input type="hidden" name="token" value="{{ request()->route('token') }}">

        <x-rsc.field label="email" name="email">
            <x-rsc.input type="email" name="email" value="{{ request('email') }}" required autocomplete="email" />
        </x-rsc.field>

        <x-rsc.field label="new_password" name="password">
            <x-rsc.input type="password" name="password" required autocomplete="new-password"
                         placeholder="{{ __('At least 10 characters') }}"
                         passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}" />
        </x-rsc.field>

        <x-rsc.field label="confirm_password" name="password_confirmation">
            <x-rsc.input type="password" name="password_confirmation" required autocomplete="new-password"
                         placeholder="{{ __('Type it again') }}"
                         passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}" />
        </x-rsc.field>

        <x-rsc.button type="submit" data-test="reset-password-button" class="!px-[26px] !py-[15px]">
            {{ __('Reset password') }}
        </x-rsc.button>
    </form>
</x-rsc.auth-card>
