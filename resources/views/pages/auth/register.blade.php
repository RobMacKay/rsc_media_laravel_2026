<x-rsc.auth-shell
    :title="__('Create account')"
    tab="register"
    :hero-title="__('Set up your access.')"
    :hero-body="__('Start a free account for your business, or join one you have been invited to. Add as many people from your team as you need — each gets their own login.')"
    :points="[
        'a' => __('One account per person, shared view of the same projects'),
        'b' => __('Invite codes are tied to your business, not to a seat count'),
        'c' => __('No card details, no marketing email, ever'),
    ]"
>
    @if ($teamInvitation)
        <p class="mb-5 rounded-xl border border-line px-4 py-3 text-sm text-muted">
            {{ __('You have been invited to :team. Create your login to join.', ['team' => $teamInvitation['teamName']]) }}
        </p>
    @endif

    <form method="POST" action="{{ route('register.store') }}" class="flex flex-col gap-[18px]"
          x-data="{
              password: '',
              get score() {
                  const value = this.password;
                  if (! value) return 0;
                  let score = 0;
                  if (value.length >= 10) score += 2; else if (value.length >= 6) score += 1;
                  if (/[A-Z]/.test(value) && /[a-z]/.test(value)) score += 1;
                  if (/[0-9]/.test(value) || /[^A-Za-z0-9]/.test(value)) score += 1;
                  return Math.min(score, 4);
              },
              get tone() {
                  return this.score >= 4 ? 'var(--rsc-accent)' : this.score >= 2 ? 'var(--rsc-accent-soft)' : 'var(--rsc-warm)';
              },
              get label() {
                  return [
                      @js(__('Use at least 10 characters.')),
                      @js(__('Weak — add length or a number.')),
                      @js(__('Getting there — mix in another character type.')),
                      @js(__('Good.')),
                      @js(__('Strong.')),
                  ][this.score];
              },
          }">
        @csrf

        @if ($teamInvitation)
            <input type="hidden" name="invitation" value="{{ $teamInvitation['code'] }}">
        @endif

        <div class="grid gap-[18px] [grid-template-columns:repeat(auto-fit,minmax(150px,1fr))]">
            <x-rsc.field label="your_name" name="name">
                <x-rsc.input name="name" :value="old('name')" required autofocus autocomplete="name" placeholder="Kirsty Munro" />
            </x-rsc.field>

            @unless ($teamInvitation)
                <x-rsc.field label="business" name="business">
                    <x-rsc.input name="business" :value="old('business')" required autocomplete="organization" placeholder="Braemar Joinery" />
                </x-rsc.field>
            @endunless
        </div>

        <x-rsc.field label="work_email" name="email">
            <x-rsc.input type="email" name="email" :value="old('email')" required autocomplete="email" placeholder="you@yourbusiness.co.uk" />
        </x-rsc.field>

        @unless ($teamInvitation)
            <x-rsc.field label="invite_code" name="invitation"
                         hint="{{ __('Only if you were sent one — leave it blank to open a new account.') }}">
                <x-rsc.input name="invitation" :value="old('invitation')"
                             placeholder="{{ __('From your welcome email') }}"
                             class="font-mono tracking-[0.08em]" />
            </x-rsc.field>
        @endunless

        <label class="block">
            <span class="mb-2 block font-mono text-[11px] text-muted">password</span>
            <x-rsc.input type="password" name="password" required autocomplete="new-password"
                         x-model="password"
                         placeholder="{{ __('At least 10 characters') }}"
                         passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}" />

            <span class="mt-2.5 flex gap-[5px]" aria-hidden="true">
                @foreach (range(0, 3) as $bar)
                    <span class="h-1 flex-1 rounded-full transition-colors duration-200"
                          x-bind:style="`background: ${score > {{ $bar }} ? tone : 'var(--rsc-line)'}`"></span>
                @endforeach
            </span>
            <span class="mt-2 block text-xs text-muted" x-text="label"></span>

            @error('password')
                <span class="mt-2 block text-xs text-warm">{{ $message }}</span>
            @enderror
        </label>

        <x-rsc.field label="confirm_password" name="password_confirmation">
            <x-rsc.input type="password" name="password_confirmation" required autocomplete="new-password"
                         placeholder="{{ __('Type it again') }}"
                         passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}" />
        </x-rsc.field>

        <x-rsc.button type="submit" data-test="register-user-button" class="!px-[26px] !py-[15px]">
            {{ __('Create account') }}
        </x-rsc.button>

        <p class="m-0 text-center text-[13px] text-muted text-pretty">
            {{ __('Already set up?') }}
            <a href="{{ $teamInvitation ? route('login', ['invitation' => $teamInvitation['code']]) : route('login') }}"
               class="no-underline" data-test="team-invitation-login-link" wire:navigate>{{ __('Log in') }}</a>.
        </p>
    </form>
</x-rsc.auth-shell>
