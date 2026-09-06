@props([
    /** A label for this form, so Cloudflare's analytics can tell them apart. */
    'action',
    /** 'load' checks when the page opens; 'submit' waits until the form is actually sent. */
    'on' => 'load',
    /** For 'submit': the Livewire property the token is written to. */
    'model' => null,
])

@php
    $turnstile = app(App\Support\Turnstile::class);
    $options = $turnstile->enabled() ? $turnstile->widgetOptions($action) : [];
@endphp

@if ($turnstile->enabled())
    @once
        {{-- Defined before the script loads, so the callback exists by the time
             it fires. Every widget on the page waits on the same promise. --}}
        <script>
            window.rscTurnstileReady = new Promise((resolve) => {
                window.onloadTurnstileCallback = resolve;
            });

            document.addEventListener('alpine:init', () => {
                Alpine.data('rscTurnstile', (options, property) => ({
                    token: null,

                    async init() {
                        await window.rscTurnstileReady;

                        window.turnstile.render(this.$el, {
                            ...options,
                            execution: 'execute',
                            callback: (token) => { this.token = token; },
                        });

                        this.guard(this.$el.closest('form'));
                    },

                    /**
                     * Hold the first submit back until Cloudflare has answered.
                     *
                     * Capture phase, so this runs before Livewire's own submit
                     * handler on the same form and can stop it; the second pass
                     * has a token and is let straight through.
                     */
                    guard(form) {
                        if (! form) return;

                        form.addEventListener('submit', (event) => {
                            if (this.token) {
                                // Let this one through, then forget the token:
                                // Cloudflare refuses a second submit that
                                // reuses it, so the next one starts again.
                                queueMicrotask(() => { this.token = null; });

                                return;
                            }

                            event.preventDefault();
                            event.stopImmediatePropagation();

                            this.challenge().then(() => form.requestSubmit());
                        }, { capture: true });
                    },

                    /**
                     * Get a token, and hand it to the component.
                     *
                     * Reset first: a token is single use, so a second submit
                     * reusing the first one is refused as a duplicate.
                     */
                    challenge() {
                        this.token = null;

                        return new Promise((resolve) => {
                            const waitForToken = setInterval(() => {
                                if (! this.token) return;

                                clearInterval(waitForToken);
                                this.$wire.set(property, this.token, false);
                                resolve();
                            }, 100);

                            window.turnstile.reset(this.$el);
                            window.turnstile.execute(this.$el);
                        });
                    },
                }));
            });
        </script>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=onloadTurnstileCallback&render=explicit"
                async defer></script>
    @endonce

    @if ($on === 'submit')
        {{-- Livewire posts the token as a property rather than a form field, and
             must not diff away the widget Cloudflare has drawn into this div. --}}
        <div wire:ignore x-data="rscTurnstile(@js($options), @js($model))"></div>
    @else
        <div wire:ignore x-data
             x-init="window.rscTurnstileReady.then(() => window.turnstile.render($el, @js($options)))"></div>
    @endif

    @error(App\Support\Turnstile::FIELD)
        <p class="mt-2 mb-0 text-xs text-warm">{{ $message }}</p>
    @enderror

    @if ($model)
        @error($model)
            <p class="mt-2 mb-0 text-xs text-warm">{{ $message }}</p>
        @enderror
    @endif
@endif
