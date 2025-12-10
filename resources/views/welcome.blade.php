<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Bookmarket') }}</title>
    <link rel="icon" href="/favicon.ico" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-white dark:bg-zinc-900 flex items-center justify-center antialiased">
    <div class="w-full max-w-md px-6 py-12 text-center">
        <div class="mb-8">
            <flux:icon name="link" class="mx-auto h-16 w-16 text-zinc-900 dark:text-white" />
        </div>

        <h1 class="text-3xl font-bold text-zinc-900 dark:text-white">
            {{ config('app.name', 'Bookmarket') }}
        </h1>

        <p class="mt-3 text-zinc-600 dark:text-zinc-400">
            Save and organize your bookmarks.
        </p>

        <div class="mt-8">
            @auth
                <flux:button href="{{ route('lists.index') }}" variant="primary" class="w-full">
                    Go to My Lists
                </flux:button>
            @else
                <flux:button href="{{ route('login') }}" variant="primary" class="w-full">
                    Sign In
                </flux:button>
            @endauth
        </div>
    </div>
</body>
</html>
