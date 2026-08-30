<x-rsc.auth-card
    :title="__('Confirm password')"
    :heading="__('Confirm it\'s you.')"
    :description="__('This part of the portal is locked down. Put your password in again before you carry on.')"
>
    <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-[18px]">
        @csrf

        <x-rsc.field label="password" name="password">
            <x-rsc.input type="password" name="password" required autofocus autocomplete="current-password" placeholder="••••••••••" />
        </x-rsc.field>

        <x-rsc.button type="submit" data-test="confirm-password-button" class="!px-[26px] !py-[15px]">
            {{ __('Confirm') }}
        </x-rsc.button>
    </form>
</x-rsc.auth-card>
