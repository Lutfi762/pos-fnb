<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>
    {{ filled($title ?? null) ? __($title).' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
{{-- Browser chrome colour. A meta tag cannot read CSS variables, so these
     two mirror --base in resources/css/app.css (light and dark). --}}
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#f0f3ef">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0b1311">
<meta name="mobile-web-app-capable" content="yes">

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
