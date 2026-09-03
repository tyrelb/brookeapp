<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head', ['title' => $title ?? 'Fitness Wallet'])
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="no-referrer">
    </head>
    <body class="min-h-screen bg-zinc-50 text-zinc-900 antialiased dark:bg-zinc-900 dark:text-zinc-100">
        <div class="mx-auto max-w-3xl px-4 py-8 sm:py-12">
            <header class="mb-8 flex items-center gap-3">
                <span class="flex aspect-square size-10 items-center justify-center rounded-xl bg-accent-content text-accent-foreground">
                    <x-app-logo-icon class="size-6 fill-current text-white" />
                </span>
                <div>
                    <div class="text-lg font-semibold leading-tight">{{ $heading ?? 'Fitness Wallet' }}</div>
                    @if (isset($subheading))
                        <div class="text-sm text-zinc-500">{{ $subheading }}</div>
                    @endif
                </div>
            </header>

            {{ $slot }}

            <footer class="mt-10 text-center text-xs text-zinc-400">
                This page is private to you. Please don't share the link. Powered by BrookeApp.
            </footer>
        </div>
        @fluxScripts
    </body>
</html>
