<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
{{-- Lets the server pick phone-sized layouts (the calendar opens as a list) without a flash. --}}
<script>document.cookie = 'narrow_screen=' + (window.innerWidth < 1024 ? 1 : 0) + ';path=/;max-age=31536000;SameSite=Lax';</script>

<title>{{ $title ?? config('app.name', 'BrookeApp') }}</title>

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
