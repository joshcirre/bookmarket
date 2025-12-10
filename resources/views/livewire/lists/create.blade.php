<?php

use App\Enums\ListVisibility;
use App\Models\BookmarkList;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new
#[Layout('components.layouts.app')]
#[Title('Create List')]
class extends Component {
    public string $title = '';
    public string $description = '';
    public string $visibility = 'private';

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'visibility' => ['required', 'in:public,private,unlisted'],
        ];
    }

    public function create(): void
    {
        $validated = $this->validate();

        $list = Auth::user()->bookmarkLists()->create([
            'title' => $validated['title'],
            'description' => $validated['description'],
            'visibility' => ListVisibility::from($validated['visibility']),
        ]);

        $this->redirect(route('lists.show', $list), navigate: true);
    }
}; ?>

<div class="max-w-2xl">
    <flux:heading size="xl">{{ __('Create a new list') }}</flux:heading>
    <flux:text class="mt-2 text-gray-500">
        {{ __('Lists help you organize bookmarks into collections. You can make them public, private, or shareable via link.') }}
    </flux:text>

    <form wire:submit="create" class="mt-8 space-y-6">
        <flux:field>
            <flux:label>{{ __('Title') }}</flux:label>
            <flux:input
                wire:model="title"
                placeholder="{{ __('e.g., Laravel Packages, Design Inspiration') }}"
                autofocus
            />
            <flux:error name="title" />
        </flux:field>

        <flux:field>
            <flux:label>{{ __('Description') }} <span class="text-gray-400">({{ __('optional') }})</span></flux:label>
            <flux:textarea
                wire:model="description"
                placeholder="{{ __('What is this list about?') }}"
                rows="3"
            />
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

        <div class="flex items-center gap-4 pt-4">
            <flux:button type="submit" variant="primary">
                {{ __('Create List') }}
            </flux:button>
            <flux:button href="{{ route('lists.index') }}" variant="ghost">
                {{ __('Cancel') }}
            </flux:button>
        </div>
    </form>
</div>
