<?php

use App\Models\BookmarkList;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new
#[Layout('components.layouts.app')]
#[Title('My Lists')]
class extends Component {
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function lists()
    {
        return BookmarkList::query()
            ->where('user_id', Auth::id())
            ->when($this->search, fn ($q) => $q->where('title', 'like', "%{$this->search}%"))
            ->withCount('bookmarks')
            ->latest()
            ->paginate(12);
    }

    public function deleteList(BookmarkList $list): void
    {
        $this->authorize('delete', $list);
        $list->delete();
    }
}; ?>

<div>
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">{{ __('My Lists') }}</flux:heading>
            <flux:text class="mt-2 text-gray-500">{{ __('Organize your bookmarks into collections') }}</flux:text>
        </div>
        <flux:button href="{{ route('lists.create') }}" variant="primary" icon="plus">
            {{ __('New List') }}
        </flux:button>
    </div>

    <div class="mt-6">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="{{ __('Search lists...') }}"
            icon="magnifying-glass"
            class="max-w-sm"
        />
    </div>

    <div class="mt-6">
        @if ($this->lists()->isEmpty())
            <div class="rounded-lg border border-dashed border-gray-300 dark:border-gray-700 p-12 text-center">
                <flux:icon name="bookmark" class="mx-auto h-12 w-12 text-gray-400" />
                <flux:heading size="lg" class="mt-4">{{ __('No lists yet') }}</flux:heading>
                <flux:text class="mt-2 text-gray-500">
                    {{ __('Create your first list to start organizing bookmarks.') }}
                </flux:text>
                <div class="mt-6">
                    <flux:button href="{{ route('lists.create') }}" variant="primary" icon="plus">
                        {{ __('Create your first list') }}
                    </flux:button>
                </div>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($this->lists() as $list)
                    <a
                        href="{{ route('lists.show', $list) }}"
                        class="group relative rounded-lg border border-gray-200 bg-white p-5 shadow-sm transition hover:border-gray-300 hover:shadow dark:border-gray-700 dark:bg-gray-800 dark:hover:border-gray-600"
                        wire:key="list-{{ $list->id }}"
                    >
                        <div class="flex items-start justify-between">
                            <div class="flex-1 min-w-0">
                                <flux:heading size="lg" class="truncate">{{ $list->title }}</flux:heading>
                                @if ($list->description)
                                    <flux:text class="mt-1 text-sm text-gray-500 line-clamp-2">
                                        {{ $list->description }}
                                    </flux:text>
                                @endif
                            </div>
                            <flux:tooltip :content="$list->visibility->description()">
                                <flux:icon :name="$list->visibility->icon()" class="h-4 w-4 text-gray-400 ml-2 shrink-0" />
                            </flux:tooltip>
                        </div>

                        <div class="mt-4 flex items-center gap-4 text-sm text-gray-500">
                            <span class="flex items-center gap-1">
                                <flux:icon name="bookmark" class="h-4 w-4" />
                                {{ $list->bookmarks_count }} {{ __('bookmarks') }}
                            </span>
                            <flux:badge size="sm" :color="match($list->visibility->value) { 'public' => 'green', 'unlisted' => 'yellow', default => 'zinc' }">
                                {{ $list->visibility->label() }}
                            </flux:badge>
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
