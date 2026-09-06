@props([
    /** Whether to also refuse a submission that comes back faster than a person could type. */
    'timed' => false,
    /** For a Livewire form: the property to bind to, since a plain field is never posted. */
    'model' => null,
])

@php $honeypot = app(App\Support\Honeypot::class); @endphp

{{-- Clipped rather than display:none — some bots skip hidden fields, and this
     way it is still a real field to anything that fills forms in blind.
     `sr-only` clips to a 1px box wherever it sits, so it cannot push the page
     about the way an off-screen absolute element can; `aria-hidden` keeps it
     away from screen readers, and `tabindex` from the keyboard. --}}
<div aria-hidden="true" class="sr-only pointer-events-none">
    <label>
        {{ __('Leave this empty') }}
        <input type="text" @if ($model) wire:model="{{ $model }}" @else name="{{ App\Support\Honeypot::FIELD }}" @endif
               value="" tabindex="-1" autocomplete="off">
    </label>
</div>

@if ($timed)
    <input type="hidden" name="{{ App\Support\Honeypot::STAMP }}" value="{{ $honeypot->stamp() }}">
@endif

@error($model ?? App\Support\Honeypot::FIELD)
    <p class="mt-2 mb-0 text-xs text-warm">{{ $message }}</p>
@enderror
