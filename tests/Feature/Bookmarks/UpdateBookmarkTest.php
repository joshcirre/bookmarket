<?php

declare(strict_types=1);

use App\Models\Bookmark;
use App\Models\BookmarkList;
use App\Models\Tag;
use App\Models\User;
use Livewire\Volt\Volt;

test('owner can update bookmark title', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create(['title' => 'Old Title']);

    $this->actingAs($user);

    Volt::test('lists.show', ['list' => $list])
        ->call('startEdit', $bookmark)
        ->set('editTitle', 'New Title')
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($bookmark->fresh()->title)->toBe('New Title');
});

test('owner can update bookmark notes', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create(['notes' => null]);

    $this->actingAs($user);

    Volt::test('lists.show', ['list' => $list])
        ->call('startEdit', $bookmark)
        ->set('editNotes', 'New notes about this bookmark')
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($bookmark->fresh()->notes)->toBe('New notes about this bookmark');
});

test('owner can update bookmark tags', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create();

    $tag1 = Tag::findOrCreateByName('laravel');
    $tag2 = Tag::findOrCreateByName('php');

    $this->actingAs($user);

    Volt::test('lists.show', ['list' => $list])
        ->call('startEdit', $bookmark)
        ->set('editTags', [$tag1->id, $tag2->id])
        ->call('saveEdit')
        ->assertHasNoErrors();

    $tagNames = $bookmark->fresh()->tags->pluck('name')->toArray();
    expect($tagNames)->toContain('laravel', 'php');
});

test('title is required when editing', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create();

    $this->actingAs($user);

    Volt::test('lists.show', ['list' => $list])
        ->call('startEdit', $bookmark)
        ->set('editTitle', '')
        ->call('saveEdit')
        ->assertHasErrors(['editTitle']);
});

test('cancel edit resets editing state', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create(['title' => 'Original']);

    $this->actingAs($user);

    $component = Volt::test('lists.show', ['list' => $list])
        ->call('startEdit', $bookmark)
        ->set('editTitle', 'Changed')
        ->call('cancelEdit');

    expect($component->get('editingId'))->toBeNull();
    expect($bookmark->fresh()->title)->toBe('Original');
});
