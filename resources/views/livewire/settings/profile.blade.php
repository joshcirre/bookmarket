<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    public string $name = '';
    public string $email = '';
    public string $username = '';
    public string $bio = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
        $this->username = Auth::user()->username;
        $this->bio = Auth::user()->bio ?? '';
    }

    /**
     * Update the profile information for the currently authenticated user.
     */
    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:30',
                'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
            'bio' => ['nullable', 'string', 'max:500'],
        ], [
            'username.regex' => 'Username must be lowercase, start and end with a letter or number, and can contain hyphens.',
            'username.unique' => 'This username is already taken.',
        ]);

        $user->fill($validated);
        $user->save();

        $this->dispatch('profile-updated', name: $user->name);
    }

    /**
     * Format username as user types.
     */
    public function updatedUsername(string $value): void
    {
        $this->username = Str::slug($value);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Profile')" :subheading="__('Update your profile information')">
        <form wire:submit="updateProfileInformation" class="my-6 w-full space-y-6">
            <flux:input wire:model="name" :label="__('Name')" type="text" required autofocus autocomplete="name" />

            <div>
                <flux:input wire:model="email" :label="__('Email')" type="email" required disabled autocomplete="email" />
            </div>

            <flux:field>
                <flux:label>{{ __('Username') }}</flux:label>
                <div class="flex items-center gap-2">
                    <span class="text-zinc-500">@</span>
                    <flux:input wire:model.blur="username" class="flex-1" required />
                </div>
                <flux:error name="username" />
                <flux:description>
                    {{ __('Your public profile will be at') }} <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ url('@' . $username) }}</span>
                </flux:description>
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Bio') }}</flux:label>
                <flux:textarea wire:model="bio" rows="3" :placeholder="__('Tell people a little about yourself...')" />
                <flux:error name="bio" />
            </flux:field>

            <div class="flex items-center gap-4">
                <div class="flex items-center justify-end">
                    <flux:button variant="primary" type="submit" class="w-full">{{ __('Save') }}</flux:button>
                </div>

                <x-action-message class="me-3" on="profile-updated">
                    {{ __('Saved.') }}
                </x-action-message>
            </div>
        </form>

        <livewire:settings.delete-user-form />
    </x-settings.layout>
</section>
