@props(['label' => null, 'name' => null, 'hint' => null])

<label class="block">
    @if ($label)
        <span class="mb-2 block font-mono text-[11px] text-muted">{{ $label }}</span>
    @endif
    {{ $slot }}
    @error($name)
        <span class="mt-2 block text-xs text-warm">{{ $message }}</span>
    @enderror
    @if ($hint)
        <span class="mt-2 block text-xs text-muted">{{ $hint }}</span>
    @endif
</label>
