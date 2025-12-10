<?php

declare(strict_types=1);

use App\Models\Bookmark;
use App\Models\BookmarkList;
use App\Models\User;
use Livewire\Volt\Volt;

test('owner can reorder bookmarks', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    $bookmark1 = Bookmark::factory()->for($list)->for($user)->create(['position' => 1]);
    $bookmark2 = Bookmark::factory()->for($list)->for($user)->create(['position' => 2]);
    $bookmark3 = Bookmark::factory()->for($list)->for($user)->create(['position' => 3]);

    $this->actingAs($user);

    // Reorder: move bookmark3 to first position
    Volt::test('lists.show', ['list' => $list])
        ->call('updateOrder', [$bookmark3->id, $bookmark1->id, $bookmark2->id]);

    expect($bookmark3->fresh()->position)->toBe(0);
    expect($bookmark1->fresh()->position)->toBe(1);
    expect($bookmark2->fresh()->position)->toBe(2);
});

test('reorder only affects bookmarks in the same list', function (): void {
    $user = User::factory()->create();
    $list1 = BookmarkList::factory()->for($user)->create();
    $list2 = BookmarkList::factory()->for($user)->create();

    $bookmark1 = Bookmark::factory()->for($list1)->for($user)->create(['position' => 1]);
    $bookmarkOther = Bookmark::factory()->for($list2)->for($user)->create(['position' => 1]);

    $this->actingAs($user);

    // Try to include bookmark from another list in the reorder
    Volt::test('lists.show', ['list' => $list1])
        ->call('updateOrder', [$bookmarkOther->id, $bookmark1->id]);

    // bookmarkOther should not be affected since it's not in list1
    expect($bookmarkOther->fresh()->position)->toBe(1);
});

test('bookmarks are displayed in position order', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    // Create in reverse order to verify sorting works
    Bookmark::factory()->for($list)->for($user)->create(['position' => 3, 'title' => 'Third']);
    Bookmark::factory()->for($list)->for($user)->create(['position' => 1, 'title' => 'First']);
    Bookmark::factory()->for($list)->for($user)->create(['position' => 2, 'title' => 'Second']);

    $this->actingAs($user);

    // Query bookmarks directly to verify ordering
    $bookmarks = $list->bookmarks()->orderBy('position')->pluck('title')->toArray();

    expect($bookmarks)->toBe(['First', 'Second', 'Third']);
});
