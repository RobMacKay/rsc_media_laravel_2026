@props([
    'variant' => 'primary',
    'as' => 'button',
    /**
     * What this button is waiting on, so it can show a pending state.
     *
     * Derived from `wire:click` where there is one. A submit button has to be
     * told, because the component cannot see the form it sits in.
     *
     * It must be targeted: an untargeted `wire:loading` fires on `wire:poll`
     * too, so on the queue and health screens every button would flicker every
     * fifteen seconds with nobody touching anything.
     */
    'target' => null,
    /** Optional replacement label while it works, e.g. "Sending…". */
    'busyLabel' => null,
])

@php
    $classes = match ($variant) {
        'primary' => 'bg-brand text-accent-ink font-display font-bold hover:-translate-y-0.5',
        'outline' => 'border border-line text-body font-semibold hover:border-brand hover:rsc-tint',
        default => 'text-muted',
    };

    // `wire:target` matches on the method name, so drop any arguments.
    $click = $attributes->get('wire:click');
    $waitingOn = $target ?: ($click ? \Illuminate\Support\Str::before($click, '(') : null);

    $isButton = $as === 'button';
    $livewire = $isButton && $waitingOn !== null;

    // A plain form posts and the page goes away, so nothing has to undo this.
    // Livewire forms are left to `wire:loading`, or the button would stay
    // disabled after the response with nothing to re-enable it.
    $plainSubmit = $isButton && ! $livewire && $attributes->get('type') === 'submit';

    $pending = array_merge(
        $livewire ? [
            'wire:target' => $waitingOn,
            'wire:loading.attr' => 'disabled',
        ] : [],
        $plainSubmit ? [
            'x-data' => '{ busy: false }',
            // Only a real form submit counts, so validation that never leaves
            // the page does not leave a dead button behind.
            'x-init' => "\$el.closest('form')?.addEventListener('submit', () => busy = true)",
            'x-bind:disabled' => 'busy',
        ] : [],
    );
@endphp

<{{ $as }} {{ $attributes->class([
    'inline-flex cursor-pointer items-center justify-center gap-2 rounded-full px-6 py-3.5 text-[15px] transition-transform duration-200',
    'disabled:cursor-wait disabled:opacity-70 disabled:hover:translate-y-0' => $livewire || $plainSubmit,
    $classes,
])->merge($pending) }}>
    @if ($livewire)
        <x-rsc.spinner wire:loading wire:target="{{ $waitingOn }}" />

        @if ($busyLabel)
            <span wire:loading.remove wire:target="{{ $waitingOn }}">{{ $slot }}</span>
            <span wire:loading wire:target="{{ $waitingOn }}">{{ $busyLabel }}</span>
        @else
            {{ $slot }}
        @endif
    @elseif ($plainSubmit)
        <x-rsc.spinner x-show="busy" x-cloak />

        @if ($busyLabel)
            <span x-show="! busy">{{ $slot }}</span>
            <span x-show="busy" x-cloak>{{ $busyLabel }}</span>
        @else
            {{ $slot }}
        @endif
    @else
        {{ $slot }}
    @endif
</{{ $as }}>
