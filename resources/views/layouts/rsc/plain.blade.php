{{-- Full-bleed shell with the hatch backdrop: used by the auth screens. --}}
<x-layouts::rsc.base :title="$title ?? null">
    <div class="relative flex min-h-screen flex-col">
        <x-rsc.backdrop />
        <div class="relative z-1 flex flex-1 flex-col">
            {{ $slot }}
        </div>
    </div>
</x-layouts::rsc.base>
