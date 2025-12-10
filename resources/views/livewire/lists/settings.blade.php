<?php

use App\Enums\ListVisibility;
use App\Models\BookmarkList;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
#[Title('List Settings')]
class extends Component {
    public BookmarkList $list;

    public string $title = '';
    public string $description = '';
    public string $visibility = '';

    public function mount(BookmarkList $list): void
    {
        $this->authorize('update', $list);
        $this->list = $list;
        $this->title = $list->title;
        $this->description = $list->description ?? '';
        $this->visibility = $list->visibility->value;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['required', 'in:public,private,unlisted'],
        ];
    }

    public function save(): void
    {
        $validated = $this->validate();

        $this->list->update([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'visibility' => ListVisibility::from($validated['visibility']),
        ]);

        $this->dispatch('list-updated');
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->list);
        $this->list->delete();
        $this->redirect(route('lists.index'), navigate: true);
    }
}; ?>

<div class="max-w-2xl">
    <div class="flex items-center gap-3">
        <flux:button href="{{ route('lists.show', $list) }}" variant="ghost" icon="arrow-left" />
        <flux:heading size="xl">{{ __('List Settings') }}</flux:heading>
    </div>

    <form wire:submit="save" class="mt-8 space-y-6">
        <flux:field>
            <flux:label>{{ __('Title') }}</flux:label>
            <flux:input wire:model="title" />
            <flux:error name="title" />
        </flux:field>

        <flux:field>
            <flux:label>{{ __('Description') }} <span class="text-gray-400">({{ __('optional') }})</span></flux:label>
            <flux:textarea wire:model="description" rows="3" />
            <flux:error name="description" />
        </flux:field>

        <flux:field>
            <flux:label>{{ __('Visibility') }}</flux:label>
            <div class="mt-2 space-y-3">
                @foreach (ListVisibility::cases() as $option)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-4 transition hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800 has-[:checked]:border-blue-500 has-[:checked]:ring-1 has-[:checked]:ring-blue-500">
                        <input
                            type="radio"
                            wire:model="visibility"
                            value="{{ $option->value }}"
                            class="mt-0.5"
                        />
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <flux:icon :name="$option->icon()" class="h-4 w-4 text-gray-500" />
                                <span class="font-medium">{{ $option->label() }}</span>
                            </div>
                            <p class="mt-1 text-sm text-gray-500">{{ $option->description() }}</p>
                        </div>
                    </label>
                @endforeach
            </div>
            <flux:error name="visibility" />
        </flux:field>

        @if ($list->visibility !== \App\Enums\ListVisibility::Private)
            <flux:field>
                <flux:label>{{ __('Share Link') }}</flux:label>
                <div class="flex items-center gap-2">
                    <flux:input value="{{ $list->publicUrl }}" readonly class="flex-1" />
                    <flux:button
                        variant="ghost"
                        icon="clipboard-document"
                        x-data
                        x-on:click="navigator.clipboard.writeText('{{ $list->publicUrl }}'); $flux.toast('Link copied!')"
                    />
                </div>
                <flux:description>
                    {{ __('Anyone with this link can view this list.') }}
                </flux:description>
            </flux:field>
        @endif

        <div class="flex items-center gap-4 pt-4">
            <flux:button type="submit" variant="primary">
                {{ __('Save Changes') }}
            </flux:button>
            <x-action-message on="list-updated">
                {{ __('Saved.') }}
            </x-action-message>
        </div>
    </form>

    <div class="mt-12 rounded-lg border border-red-200 bg-red-50 p-6 dark:border-red-900 dark:bg-red-950">
        <flux:heading size="lg" class="text-red-700 dark:text-red-400">{{ __('Danger Zone') }}</flux:heading>
        <flux:text class="mt-2 text-red-600 dark:text-red-400">
            {{ __('Deleting this list will permanently remove all its bookmarks. This action cannot be undone.') }}
        </flux:text>
        <div class="mt-4">
            <flux:button
                wire:click="delete"
                wire:confirm="{{ __('Are you sure you want to delete this list and all its bookmarks?') }}"
                variant="danger"
            >
                {{ __('Delete List') }}
            </flux:button>
        </div>
    </div>
</div>
