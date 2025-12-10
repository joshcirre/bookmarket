<?php

use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.public')]
class extends Component {
    use WithPagination;

    public User $user;

    public function mount(string $username): void
    {
        $this->user = User::where('username', $username)->firstOrFail();
    }

    public function lists()
    {
        return $this->user->publicBookmarkLists()
            ->withCount('bookmarks')
            ->latest()
            ->paginate(12);
    }
}; ?>

<div>
    <div class="text-center">
        <flux:avatar
            size="xl"
            :src="$user->avatar ?: null"
            :name="$user->initials()"
            class="mx-auto"
        />
        <flux:heading size="xl" class="mt-4">{{ $user->name }}</flux:heading>
        <flux:text class="text-gray-500">{{ '@' . $user->username }}</flux:text>
        @if ($user->bio)
            <flux:text class="mt-3 max-w-lg mx-auto">{{ $user->bio }}</flux:text>
        @endif
    </div>

    <div class="mt-10">
        @if ($this->lists()->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-300 dark:border-gray-700 p-12 text-center">
                <flux:icon name="bookmark" class="mx-auto h-12 w-12 text-gray-400" />
                <flux:heading size="lg" class="mt-4">{{ __('No public lists yet') }}</flux:heading>
                <flux:text class="mt-2 text-gray-500">
                    {{ $user->name }} {{ __("hasn't shared any lists publicly.") }}
                </flux:text>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->lists() as $list)
                    <a
                        href="{{ route('profile.list', [$user->username, $list->slug]) }}"
                        class="group rounded-lg border border-gray-200 bg-white p-5 shadow-sm transition hover:border-gray-300 hover:shadow dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-600"
                        wire:key="list-{{ $list->id }}"
                    >
                        <flux:heading size="lg" class="truncate">{{ $list->title }}</flux:heading>
                        @if ($list->description)
                            <flux:text class="mt-1 text-sm text-gray-500 line-clamp-2">
                                {{ $list->description }}
                            </flux:text>
                        @endif
                        <div class="mt-4 flex items-center gap-1 text-sm text-gray-500">
                            <flux:icon name="bookmark" class="h-4 w-4" />
                            {{ $list->bookmarks_count }} {{ __('bookmarks') }}
                        </div>
                    </a>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $this->lists()->links() }}
            </div>
        @endif
    </div>
</div>
