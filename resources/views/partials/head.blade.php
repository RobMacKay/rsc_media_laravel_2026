<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">

{{-- The manifest carries one theme colour for the install; these two let the
     address bar follow whichever theme the person is actually looking at. --}}
<meta name="theme-color" content="#eef4f2" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#04121a" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-title" content="RSC Media">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
