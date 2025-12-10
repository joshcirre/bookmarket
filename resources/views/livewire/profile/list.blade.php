<?php

use App\Enums\ListVisibility;
use App\Models\BookmarkList;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.public')]
class extends Component {
    use WithPagination;

    public User $user;
    public BookmarkList $list;

    public function mount(string $username, string $slug): void
    {
        $this->user = User::where('username', $username)->firstOrFail();

        $this->list = BookmarkList::where('user_id', $this->user->id)
            ->where('slug', $slug)
            ->firstOrFail();

        // Check visibility - private lists are not accessible
        if ($this->list->visibility === ListVisibility::Private) {
            abort(404);
        }
    }

    public function bookmarks()
    {
        return $this->list->bookmarks()
            ->with('tags')
            ->orderBy('position')
            ->paginate(50);
    }
}; ?>

<div>
    <div class="mb-6">
        <a
            href="{{ route('profile.show', $user->username) }}"
            class="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-gray-700 dark:hover:text-gray-300"
        >
            <flux:icon name="arrow-left" class="h-4 w-4" />
            {{ '@' . $user->username }}
        </a>
    </div>

    <div>
        <flux:heading size="xl">{{ $list->title }}</flux:heading>
        @if ($list->description)
            <flux:text class="mt-2 text-gray-500">{{ $list->description }}</flux:text>
        @endif
        <div class="mt-4 flex items-center gap-4 text-sm text-gray-500">
            <div class="flex items-center gap-2">
                <flux:avatar
                    size="sm"
                    :src="$user->avatar ?: null"
                    :name="$user->initials()"
                />
                <span>{{ $user->name }}</span>
            </div>
            <span class="flex items-center gap-1">
                <flux:icon name="bookmark" class="h-4 w-4" />
                {{ $list->bookmarks_count }} {{ __('bookmarks') }}
            </span>
        </div>
    </div>

    <div class="mt-8 space-y-2">
        @forelse ($this->bookmarks() as $bookmark)
            <div
                class="rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                wire:key="bookmark-{{ $bookmark->id }}"
            >
                <div class="flex items-start gap-3">
                    <img
                        src="{{ $bookmark->favicon }}"
                        alt=""
                        class="mt-1 h-4 w-4 shrink-0"
                        loading="lazy"
                    />
                    <div class="min-w-0 flex-1">
                        <a
                            href="{{ $bookmark->url }}"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="font-medium text-gray-900 hover:text-blue-600 dark:text-gray-100 dark:hover:text-blue-400"
                        >
                            {{ $bookmark->title }}
                        </a>
                        <div class="mt-0.5 truncate text-sm text-gray-500">{{ $bookmark->domain }}</div>
                        @if ($bookmark->description)
                            <div class="mt-2 text-sm text-gray-600 dark:text-gray-400">{{ $bookmark->description }}</div>
                        @endif
                        @if ($bookmark->tags->isNotEmpty())
                            <div class="mt-2 flex flex-wrap gap-1">
                                @foreach ($bookmark->tags as $tag)
                                    <flux:badge size="sm">{{ $tag->name }}</flux:badge>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-dashed border-gray-300 dark:border-gray-700 p-12 text-center">
                <flux:icon name="bookmark" class="mx-auto h-12 w-12 text-gray-400" />
                <flux:heading size="lg" class="mt-4">{{ __('No bookmarks yet') }}</flux:heading>
                <flux:text class="mt-2 text-gray-500">
                    {{ __('This list is empty.') }}
                </flux:text>
            </div>
        @endforelse

        @if ($this->bookmarks()->hasPages())
            <div class="mt-6">
                {{ $this->bookmarks()->links() }}
            </div>
        @endif
    </div>
</div>
