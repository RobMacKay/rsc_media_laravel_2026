<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="route('home')">
RSC<span style="color: rgba(10, 32, 41, 0.62); font-weight: 400;">MEDIA</span>
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
@php
    $studio = \App\Models\StudioSetting::current();

    // The footer slot is run through Markdown, so each line has to be its own
    // paragraph or they all collapse onto one.
    $lines = array_values(array_filter([
        collect([$studio->company_name ?? config('app.name'), $studio->company_number])->filter()->join(' · '),
        implode(', ', $studio->addressLines()),
        collect([
            $studio->email ? '['.$studio->email.'](mailto:'.$studio->email.')' : null,
            $studio->website ? '['.$studio->website.']('.\Illuminate\Support\Str::start($studio->website, 'https://').')' : null,
        ])->filter()->join(' · '),
    ]));
@endphp
{!! implode("\n\n", $lines) !!}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
