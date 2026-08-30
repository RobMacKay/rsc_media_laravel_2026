<x-rsc.auth-card
    :title="__('Two-factor authentication')"
    :heading="__('One more step.')"
>
    <div
        x-cloak
        x-data="{
            showRecoveryInput: @js($errors->has('recovery_code')),
            toggle() {
                this.showRecoveryInput = ! this.showRecoveryInput;
                this.$nextTick(() => (this.showRecoveryInput ? this.$refs.recovery : this.$refs.code)?.focus());
            },
        }"
    >
        <p class="mb-6 text-sm leading-relaxed text-muted text-pretty" x-show="! showRecoveryInput">
            {{ __('Enter the code from your authenticator app.') }}
        </p>
        <p class="mb-6 text-sm leading-relaxed text-muted text-pretty" x-show="showRecoveryInput">
            {{ __('Enter one of the emergency recovery codes you saved when you set this up.') }}
        </p>

        <form method="POST" action="{{ route('two-factor.login.store') }}" class="flex flex-col gap-[18px]">
            @csrf

            <div x-show="! showRecoveryInput">
                <x-rsc.field label="authentication_code" name="code">
                    <x-rsc.input name="code" x-ref="code" inputmode="numeric" autocomplete="one-time-code"
                                 maxlength="6" placeholder="000000"
                                 class="text-center font-mono text-2xl tracking-[0.4em]" />
                </x-rsc.field>
            </div>

            <div x-show="showRecoveryInput">
                <x-rsc.field label="recovery_code" name="recovery_code">
                    <x-rsc.input name="recovery_code" x-ref="recovery" x-bind:required="showRecoveryInput"
                                 autocomplete="one-time-code" class="font-mono tracking-[0.08em]" />
                </x-rsc.field>
            </div>

            <x-rsc.button type="submit" class="!px-[26px] !py-[15px]">{{ __('Continue') }}</x-rsc.button>

            <p class="m-0 text-center text-[13px] text-muted">
                <span class="opacity-70">{{ __('or you can') }}</span>
                <button type="button" x-on:click="toggle()" class="cursor-pointer underline">
                    <span x-show="! showRecoveryInput">{{ __('log in using a recovery code') }}</span>
                    <span x-show="showRecoveryInput">{{ __('log in using an authentication code') }}</span>
                </button>
            </p>
        </form>
    </div>
</x-rsc.auth-card>
