@props(['title', 'hint', 'name', 'model', 'multiple' => false, 'busy' => null])

{{-- The dashed "attach a file" control from the handoff. --}}
<div>
    <label class="flex cursor-pointer flex-wrap items-center justify-between gap-4 rounded-[14px] border border-dashed border-line px-[18px] py-4 transition-colors duration-200 hover:border-brand">
        <span>
            <span class="block font-display text-sm font-bold">{{ $title }}</span>
            <span class="mt-[3px] block text-xs text-muted">{{ $hint }}</span>
        </span>
        <input type="file" wire:model="{{ $model }}" @if ($multiple) multiple @endif
               class="max-w-full text-[13px] text-muted file:me-3 file:cursor-pointer file:rounded-full file:border file:border-line file:bg-transparent file:px-4 file:py-2 file:font-mono file:text-[11px] file:text-body">
    </label>

    <div wire:loading wire:target="{{ $busy ?? $model }}" class="mt-2 font-mono text-[11px] text-muted">
        {{ __('uploading…') }}
    </div>

    @error($name)
        <p class="mt-2 mb-0 text-xs text-warm">{{ $message }}</p>
    @enderror
    @error($name.'.*')
        <p class="mt-2 mb-0 text-xs text-warm">{{ $message }}</p>
    @enderror
</div>
