<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800">
        <header class="border-b border-zinc-200 dark:border-zinc-700">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
                <a href="{{ url('/') }}" class="flex items-center space-x-2 rtl:space-x-reverse">
                    <flux:icon name="link" class="h-6 w-6 text-zinc-900 dark:text-white" />
                    <span class="text-lg font-semibold text-zinc-900 dark:text-white">{{ config('app.name', 'Bookmarket') }}</span>
                </a>
                <div class="flex items-center gap-4">
                    @auth
                        <flux:button href="{{ route('lists.index') }}" variant="ghost">
                            {{ __('My Lists') }}
                        </flux:button>
                    @else
                        <flux:button href="{{ route('login') }}" variant="primary">
                            {{ __('Sign In') }}
                        </flux:button>
                    @endauth
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 py-8">
            {{ $slot }}
        </main>

        <footer class="border-t border-zinc-200 dark:border-zinc-700">
            <div class="mx-auto max-w-5xl px-4 py-6 text-center text-sm text-gray-500">
                {{ __('Powered by') }} <a href="{{ url('/') }}" class="text-blue-500 hover:underline">Bookmarket</a>
            </div>
        </footer>

        @fluxScripts
    </body>
</html>
