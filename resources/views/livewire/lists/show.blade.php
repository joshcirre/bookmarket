<?php

use App\Jobs\FetchBookmarkMetadata;
use App\Models\Bookmark;
use App\Models\BookmarkList;
use App\Models\Tag;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
class extends Component {
    public BookmarkList $list;

    // Add bookmark modal
    public bool $showAddModal = false;
    public string $url = '';
    public string $notes = '';
    public array $selectedTags = [];
    public string $newTagName = '';

    // Edit bookmark
    public ?int $editingId = null;
    public string $editTitle = '';
    public string $editNotes = '';
    public array $editTags = [];
    public string $editNewTagName = '';

    public function mount(BookmarkList $list): void
    {
        $this->authorize('update', $list);
        $this->list = $list;
    }

    #[Computed]
    public function availableTags(): array
    {
        return Tag::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    public function addNewTag(): void
    {
        $name = trim($this->newTagName);
        if ($name === '') {
            return;
        }

        $tag = Tag::findOrCreateByName($name);

        if (! in_array($tag->id, $this->selectedTags, true)) {
            $this->selectedTags[] = $tag->id;
        }

        $this->newTagName = '';
        unset($this->availableTags);
    }

    public function addEditTag(): void
    {
        $name = trim($this->editNewTagName);
        if ($name === '') {
            return;
        }

        $tag = Tag::findOrCreateByName($name);

        if (! in_array($tag->id, $this->editTags, true)) {
            $this->editTags[] = $tag->id;
        }

        $this->editNewTagName = '';
        unset($this->availableTags);
    }

    public function addBookmark(): void
    {
        $validated = $this->validate([
            'url' => ['required', 'url', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        // Create bookmark with URL as temporary title (will be replaced by job)
        $bookmark = $this->list->bookmarks()->create([
            'user_id' => Auth::id(),
            'url' => $validated['url'],
            'title' => $validated['url'], // Temporary - will be replaced by AI
            'notes' => $validated['notes'],
        ]);

        // Sync selected tags
        if (! empty($this->selectedTags)) {
            $bookmark->tags()->sync($this->selectedTags);
        }

        // Dispatch job to fetch metadata and generate AI title/tags
        FetchBookmarkMetadata::dispatch($bookmark);

        $this->reset(['url', 'notes', 'selectedTags', 'newTagName', 'showAddModal']);
        $this->list->refresh();
    }

    public function startEdit(Bookmark $bookmark): void
    {
        $this->editingId = $bookmark->id;
        $this->editTitle = $bookmark->title;
        $this->editNotes = $bookmark->notes ?? '';
        $this->editTags = $bookmark->tags->pluck('id')->toArray();
    }

    public function saveEdit(): void
    {
        $bookmark = Bookmark::findOrFail($this->editingId);
        $this->authorize('update', $bookmark);

        $validated = $this->validate([
            'editTitle' => ['required', 'string', 'max:255'],
            'editNotes' => ['nullable', 'string', 'max:5000'],
        ]);

        $bookmark->update([
            'title' => $validated['editTitle'],
            'notes' => $validated['editNotes'],
        ]);

        $bookmark->tags()->sync($this->editTags);

        $this->cancelEdit();
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingId', 'editTitle', 'editNotes', 'editTags', 'editNewTagName']);
    }

    public function deleteBookmark(Bookmark $bookmark): void
    {
        $this->authorize('delete', $bookmark);
        $bookmark->delete();
        $this->list->refresh();
    }

    public function updateOrder(array $order): void
    {
        foreach ($order as $position => $id) {
            Bookmark::where('id', $id)
                ->where('bookmark_list_id', $this->list->id)
                ->update(['position' => $position]);
        }
    }

    public function bookmarks()
    {
        return $this->list->bookmarks()->with('tags')->orderBy('position')->get();
    }
}; ?>

<div>
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center gap-3">
                <flux:heading size="xl">{{ $list->title }}</flux:heading>
                <flux:badge size="sm" :color="match($list->visibility->value) { 'public' => 'green', 'unlisted' => 'yellow', default => 'zinc' }">
                    {{ $list->visibility->label() }}
                </flux:badge>
            </div>
            @if ($list->description)
                <flux:text class="mt-2 text-gray-500">{{ $list->description }}</flux:text>
            @endif
        </div>
        <div class="flex items-center gap-2">
            @if (!$list->isPrivate())
                <flux:button
                    variant="ghost"
                    icon="link"
                    x-data
                    x-on:click="navigator.clipboard.writeText('{{ $list->publicUrl }}'); $flux.toast('Link copied!')"
                >
                    {{ __('Copy Link') }}
                </flux:button>
            @endif
            <flux:button href="{{ route('lists.settings', $list) }}" variant="ghost" icon="cog-6-tooth">
                {{ __('Settings') }}
            </flux:button>
        </div>
    </div>

    <div class="mt-6">
        <flux:button wire:click="$set('showAddModal', true)" variant="primary" icon="plus">
            {{ __('Add Bookmark') }}
        </flux:button>
    </div>

    {{-- Add Bookmark Modal --}}
    <flux:modal wire:model="showAddModal" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Add a bookmark') }}</flux:heading>

            <form wire:submit="addBookmark" class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('URL') }}</flux:label>
                    <flux:input
                        wire:model="url"
                        type="url"
                        placeholder="https://example.com"
                        autofocus
                    />
                    <flux:error name="url" />
                    <flux:description>
                        {{ __('Paste a URL and we\'ll automatically fetch the title and suggest tags.') }}
                    </flux:description>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Why are you saving this?') }} <span class="text-gray-400">({{ __('optional') }})</span></flux:label>
                    <flux:textarea wire:model="notes" rows="2" placeholder="{{ __('Remind yourself why this is useful...') }}" />
                    <flux:error name="notes" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Tags') }} <span class="text-gray-400">({{ __('optional') }})</span></flux:label>
                    @if (count($this->availableTags) > 0)
                        <flux:pillbox wire:model="selectedTags" multiple searchable placeholder="{{ __('Select tags...') }}">
                            @foreach ($this->availableTags as $id => $name)
                                <flux:pillbox.option :value="$id">{{ $name }}</flux:pillbox.option>
                            @endforeach
                        </flux:pillbox>
                    @endif
                    <div class="mt-2">
                        <flux:input.group>
                            <flux:input
                                wire:model="newTagName"
                                wire:keydown.enter.prevent="addNewTag"
                                placeholder="{{ __('Create new tag...') }}"
                                size="sm"
                            />
                            <flux:button wire:click="addNewTag" size="sm" icon="plus" />
                        </flux:input.group>
                    </div>
                    <flux:description>
                        {{ __('Select existing tags or create new ones. Tags may also be suggested automatically.') }}
                    </flux:description>
                </flux:field>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <flux:button wire:click="$set('showAddModal', false)" variant="ghost">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary">{{ __('Save Bookmark') }}</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    <div class="mt-6 space-y-2" x-data="{
        dragging: null,
        reorder(items) {
            $wire.updateOrder(items.map(el => el.dataset.id))
        }
    }">
        @forelse ($this->bookmarks() as $bookmark)
            <div
                wire:key="bookmark-{{ $bookmark->id }}"
                data-id="{{ $bookmark->id }}"
                class="group rounded-lg border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-800"
                draggable="true"
                x-on:dragstart="dragging = $el; $el.classList.add('opacity-50')"
                x-on:dragend="dragging = null; $el.classList.remove('opacity-50')"
                x-on:dragover.prevent="if (dragging !== $el) $el.classList.add('border-blue-500')"
                x-on:dragleave="$el.classList.remove('border-blue-500')"
                x-on:drop.prevent="
                    $el.classList.remove('border-blue-500');
                    if (dragging && dragging !== $el) {
                        const items = [...$el.parentElement.children];
                        const fromIndex = items.indexOf(dragging);
                        const toIndex = items.indexOf($el);
                        if (fromIndex < toIndex) {
                            $el.after(dragging);
                        } else {
                            $el.before(dragging);
                        }
                        reorder([...$el.parentElement.children]);
                    }
                "
            >
                @if ($editingId === $bookmark->id)
                    <form wire:submit="saveEdit" class="space-y-3">
                        <flux:input wire:model="editTitle" placeholder="{{ __('Title') }}" />
                        <flux:textarea wire:model="editNotes" rows="2" placeholder="{{ __('Notes...') }}" />
                        @if (count($this->availableTags) > 0)
                            <flux:pillbox wire:model="editTags" multiple searchable size="sm" placeholder="{{ __('Tags...') }}">
                                @foreach ($this->availableTags as $id => $name)
                                    <flux:pillbox.option :value="$id">{{ $name }}</flux:pillbox.option>
                                @endforeach
                            </flux:pillbox>
                        @endif
                        <flux:input.group class="mt-2">
                            <flux:input
                                wire:model="editNewTagName"
                                wire:keydown.enter.prevent="addEditTag"
                                placeholder="{{ __('New tag...') }}"
                                size="sm"
                            />
                            <flux:button wire:click="addEditTag" size="sm" icon="plus" />
                        </flux:input.group>
                        <div class="flex items-center gap-2">
                            <flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button>
                            <flux:button wire:click="cancelEdit" variant="ghost" size="sm">{{ __('Cancel') }}</flux:button>
                        </div>
                    </form>
                @else
                    <div class="flex items-start gap-3">
                        <div class="shrink-0 cursor-move text-gray-400 opacity-0 transition group-hover:opacity-100">
                            <flux:icon name="bars-3" class="h-5 w-5" />
                        </div>
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
                                <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ $bookmark->description }}</div>
                            @endif
                            @if ($bookmark->notes)
                                <div class="mt-2 text-sm italic text-gray-500 dark:text-gray-400">{{ $bookmark->notes }}</div>
                            @endif
                            @if ($bookmark->tags->isNotEmpty())
                                <div class="mt-2 flex flex-wrap gap-1">
                                    @foreach ($bookmark->tags as $tag)
                                        <flux:badge size="sm">{{ $tag->name }}</flux:badge>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                        <div class="flex items-center gap-1 opacity-0 transition group-hover:opacity-100">
                            <flux:button wire:click="startEdit({{ $bookmark->id }})" variant="ghost" size="sm" icon="pencil" />
                            <flux:button
                                wire:click="deleteBookmark({{ $bookmark->id }})"
                                wire:confirm="{{ __('Are you sure you want to delete this bookmark?') }}"
                                variant="ghost"
                                size="sm"
                                icon="trash"
                            />
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-lg border border-dashed border-gray-300 dark:border-gray-700 p-12 text-center">
                <flux:icon name="bookmark" class="mx-auto h-12 w-12 text-gray-400" />
                <flux:heading size="lg" class="mt-4">{{ __('No bookmarks yet') }}</flux:heading>
                <flux:text class="mt-2 text-gray-500">
                    {{ __('Add your first bookmark to this list.') }}
                </flux:text>
            </div>
        @endforelse
    </div>
</div>
