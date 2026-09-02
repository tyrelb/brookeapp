<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => 'BrookeApp'])
    </head>
    <body class="min-h-screen bg-white text-zinc-900 antialiased dark:bg-zinc-800 dark:text-zinc-100">
        <div class="flex min-h-screen flex-col items-center justify-center px-6 py-12">
            <div class="w-full max-w-md text-center">
                <a href="{{ route('home') }}" class="mx-auto mb-6 flex items-center justify-center gap-3">
                    <span class="flex aspect-square size-12 items-center justify-center rounded-xl bg-accent-content text-accent-foreground">
                        <x-app-logo-icon class="size-7 fill-current text-white dark:text-black" />
                    </span>
                    <span class="text-3xl font-semibold tracking-tight">BrookeApp</span>
                </a>

                <h1 class="text-xl font-medium text-zinc-700 dark:text-zinc-200">
                    Billing and Fitness Wallet tracking for personal trainers.
                </h1>
                <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">
                    Track monthly memberships, prepaid session balances, payments, and GST in one place.
                </p>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:justify-center">
                    @auth
                        <flux:button :href="route('dashboard')" variant="primary" class="w-full sm:w-auto">Go to dashboard</flux:button>
                    @else
                        <flux:button :href="route('login')" variant="primary" class="w-full sm:w-auto">Log in</flux:button>
                        <flux:button :href="route('register')" class="w-full sm:w-auto">Register</flux:button>
                    @endauth
                </div>
            </div>
        </div>

        @fluxScripts
    </body>
</html>
