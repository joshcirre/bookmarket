<?php

declare(strict_types=1);

use App\Models\Bookmark;
use App\Models\BookmarkList;
use App\Models\User;
use Livewire\Volt\Volt;

test('owner can delete their list', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    $this->actingAs($user);

    Volt::test('lists.settings', ['list' => $list])
        ->call('delete')
        ->assertRedirect(route('lists.index'));

    $this->assertDatabaseMissing('bookmark_lists', ['id' => $list->id]);
});

test('deleting list also deletes its bookmarks', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark1 = Bookmark::factory()->for($list)->for($user)->create();
    $bookmark2 = Bookmark::factory()->for($list)->for($user)->create();

    $this->actingAs($user);

    Volt::test('lists.settings', ['list' => $list])
        ->call('delete');

    $this->assertDatabaseMissing('bookmarks', ['id' => $bookmark1->id]);
    $this->assertDatabaseMissing('bookmarks', ['id' => $bookmark2->id]);
});

test('other users cannot delete list', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $list = BookmarkList::factory()->for($owner)->create();

    $this->actingAs($other);

    Volt::test('lists.settings', ['list' => $list])
        ->assertForbidden();

    $this->assertDatabaseHas('bookmark_lists', ['id' => $list->id]);
});

test('guests cannot delete lists', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    $this->get(route('lists.settings', $list))
        ->assertRedirect(route('login'));

    $this->assertDatabaseHas('bookmark_lists', ['id' => $list->id]);
});
