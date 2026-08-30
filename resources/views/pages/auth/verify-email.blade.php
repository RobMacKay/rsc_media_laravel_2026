<x-rsc.auth-card
    :title="__('Email verification')"
    :heading="__('Check your inbox.')"
    :description="__('Click the link we just emailed you and you\'re in. It can take a minute to arrive.')"
>
    @if (session('status') === 'verification-link-sent')
        <p class="mb-5 rounded-xl border border-line px-4 py-3 text-sm text-brand">
            {{ __('A new verification link has been sent to the email address you provided during registration.') }}
        </p>
    @endif

    <div class="flex flex-wrap items-center gap-3.5">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <x-rsc.button type="submit" class="!px-[26px] !py-3.5">{{ __('Resend verification email') }}</x-rsc.button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" data-test="logout-button" class="cursor-pointer font-mono text-xs text-muted hover:text-body">
                {{ __('log out') }}
            </button>
        </form>
    </div>
</x-rsc.auth-card>
