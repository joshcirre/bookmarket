<?php

declare(strict_types=1);

use App\Enums\ListVisibility;
use App\Models\BookmarkList;
use App\Models\User;
use Livewire\Volt\Volt;

test('owner can update list title', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create(['title' => 'Old Title']);

    $this->actingAs($user);

    Volt::test('lists.settings', ['list' => $list])
        ->set('title', 'New Title')
        ->call('save')
        ->assertHasNoErrors();

    expect($list->fresh()->title)->toBe('New Title');
});

test('owner can update list description', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create(['description' => null]);

    $this->actingAs($user);

    Volt::test('lists.settings', ['list' => $list])
        ->set('description', 'New description')
        ->call('save')
        ->assertHasNoErrors();

    expect($list->fresh()->description)->toBe('New description');
});

test('owner can change visibility from private to public', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->private()->create();

    $this->actingAs($user);

    Volt::test('lists.settings', ['list' => $list])
        ->set('visibility', 'public')
        ->call('save')
        ->assertHasNoErrors();

    expect($list->fresh()->visibility)->toBe(ListVisibility::Public);
});

test('owner can change visibility from public to unlisted', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->public()->create();

    $this->actingAs($user);

    Volt::test('lists.settings', ['list' => $list])
        ->set('visibility', 'unlisted')
        ->call('save')
        ->assertHasNoErrors();

    expect($list->fresh()->visibility)->toBe(ListVisibility::Unlisted);
});

test('title is required', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    $this->actingAs($user);

    Volt::test('lists.settings', ['list' => $list])
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title']);
});

test('other users cannot access list settings', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $list = BookmarkList::factory()->for($owner)->create();

    $this->actingAs($other);

    Volt::test('lists.settings', ['list' => $list])
        ->assertForbidden();
});

test('slug is not changed when title is updated', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create([
        'title' => 'Original Title',
        'slug' => 'original-title',
    ]);

    $this->actingAs($user);

    Volt::test('lists.settings', ['list' => $list])
        ->set('title', 'Completely Different Title')
        ->call('save')
        ->assertHasNoErrors();

    // Slug should remain unchanged to preserve URLs
    expect($list->fresh()->slug)->toBe('original-title');
    expect($list->fresh()->title)->toBe('Completely Different Title');
});
