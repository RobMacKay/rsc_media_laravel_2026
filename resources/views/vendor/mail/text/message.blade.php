{!! strip_tags($header ?? 'RSC Media') !!}

{!! strip_tags($slot) !!}
@isset($subcopy)

---
{!! strip_tags($subcopy) !!}
@endisset

@php
    $studio = \App\Models\StudioSetting::current();
@endphp
{{ $studio->company_name ?? config('app.name') }}@if ($studio->company_number) · {{ $studio->company_number }}@endif

@if ($studio->addressLines()){{ implode(', ', $studio->addressLines()) }}
@endif
@if ($studio->email){{ $studio->email }}@endif@if ($studio->email && $studio->website) · @endif@if ($studio->website){{ $studio->website }}@endif
