<?php

declare(strict_types=1);

use App\Models\Bookmark;
use App\Models\BookmarkList;
use App\Models\User;
use Livewire\Volt\Volt;

test('owner can delete bookmark', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create();

    $this->actingAs($user);

    Volt::test('lists.show', ['list' => $list])
        ->call('deleteBookmark', $bookmark)
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('bookmarks', ['id' => $bookmark->id]);
});

test('bookmarks_count is decremented when bookmark deleted', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create(['bookmarks_count' => 0]);
    $bookmark = Bookmark::factory()->for($list)->for($user)->create();

    // The factory creates the bookmark which increments the count
    $list->refresh();
    expect($list->bookmarks_count)->toBe(1);

    $this->actingAs($user);

    Volt::test('lists.show', ['list' => $list])
        ->call('deleteBookmark', $bookmark);

    $list->refresh();
    expect($list->bookmarks_count)->toBe(0);
});

test('deleting bookmark removes tag associations', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create();

    // Attach some tags
    $bookmark->tags()->attach([
        \App\Models\Tag::findOrCreateByName('laravel')->id,
        \App\Models\Tag::findOrCreateByName('php')->id,
    ]);

    $this->actingAs($user);

    Volt::test('lists.show', ['list' => $list])
        ->call('deleteBookmark', $bookmark);

    // The pivot records should be gone
    $this->assertDatabaseMissing('taggables', [
        'taggable_id' => $bookmark->id,
        'taggable_type' => Bookmark::class,
    ]);

    // But tags themselves should still exist
    $this->assertDatabaseHas('tags', ['name' => 'laravel']);
    $this->assertDatabaseHas('tags', ['name' => 'php']);
});
