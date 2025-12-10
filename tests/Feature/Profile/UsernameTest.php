<?php

declare(strict_types=1);

use App\Models\User;
use Livewire\Volt\Volt;

test('user can update their username', function (): void {
    $user = User::factory()->create(['username' => 'old-username']);

    $this->actingAs($user);

    Volt::test('settings.profile')
        ->set('username', 'new-username')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->fresh()->username)->toBe('new-username');
});

test('username must be unique', function (): void {
    $existingUser = User::factory()->create(['username' => 'taken-username']);
    $user = User::factory()->create(['username' => 'my-username']);

    $this->actingAs($user);

    Volt::test('settings.profile')
        ->set('username', 'taken-username')
        ->call('updateProfileInformation')
        ->assertHasErrors(['username']);

    expect($user->fresh()->username)->toBe('my-username');
});

test('user can keep their own username', function (): void {
    $user = User::factory()->create(['username' => 'my-username']);

    $this->actingAs($user);

    Volt::test('settings.profile')
        ->set('username', 'my-username')
        ->set('name', 'Updated Name')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->fresh()->username)->toBe('my-username');
    expect($user->fresh()->name)->toBe('Updated Name');
});

test('username must be at least 3 characters', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('settings.profile')
        ->set('username', 'ab')
        ->call('updateProfileInformation')
        ->assertHasErrors(['username']);
});

test('username must be at most 30 characters', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('settings.profile')
        ->set('username', str_repeat('a', 31))
        ->call('updateProfileInformation')
        ->assertHasErrors(['username']);
});

test('username with leading hyphen is auto-corrected by slug', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    // Str::slug removes leading hyphens, so this becomes 'username'
    $component = Volt::test('settings.profile')
        ->set('username', '-username');

    expect($component->get('username'))->toBe('username');
});

test('username with trailing hyphen is auto-corrected by slug', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    // Str::slug removes trailing hyphens, so this becomes 'username'
    $component = Volt::test('settings.profile')
        ->set('username', 'username-');

    expect($component->get('username'))->toBe('username');
});

test('valid usernames are accepted', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    // Simple lowercase
    Volt::test('settings.profile')
        ->set('username', 'validuser')
        ->call('updateProfileInformation')
        ->assertHasNoErrors(['username']);

    // With numbers
    Volt::test('settings.profile')
        ->set('username', 'user123')
        ->call('updateProfileInformation')
        ->assertHasNoErrors(['username']);

    // With hyphens
    Volt::test('settings.profile')
        ->set('username', 'valid-user-name')
        ->call('updateProfileInformation')
        ->assertHasNoErrors(['username']);

    // Starting with number
    Volt::test('settings.profile')
        ->set('username', '123user')
        ->call('updateProfileInformation')
        ->assertHasNoErrors(['username']);
});

test('user can update their bio', function (): void {
    $user = User::factory()->create(['bio' => null, 'username' => 'testuser']);

    $this->actingAs($user);

    Volt::test('settings.profile')
        ->set('bio', 'This is my bio')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->fresh()->bio)->toBe('This is my bio');
});

test('bio can be empty', function (): void {
    $user = User::factory()->create(['bio' => 'Old bio', 'username' => 'testuser']);

    $this->actingAs($user);

    Volt::test('settings.profile')
        ->set('bio', '')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect($user->fresh()->bio)->toBe('');
});

test('bio cannot exceed 500 characters', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('settings.profile')
        ->set('bio', str_repeat('a', 501))
        ->call('updateProfileInformation')
        ->assertHasErrors(['bio']);
});

test('username is auto-slugified on blur', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = Volt::test('settings.profile')
        ->set('username', 'My Username');

    // The updatedUsername method should slugify it
    expect($component->get('username'))->toBe('my-username');
});

test('public profile url updates with username', function (): void {
    $user = User::factory()->create(['username' => 'original']);

    $this->actingAs($user);

    Volt::test('settings.profile')
        ->set('username', 'newname')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    // Verify the profile is accessible at the new URL
    $this->get('/@newname')->assertOk();

    // Old URL should 404
    $this->get('/@original')->assertNotFound();
});
