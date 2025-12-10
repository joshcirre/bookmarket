<?php

declare(strict_types=1);

use App\Models\BookmarkList;
use App\Models\User;

test('private list is not visible to other users', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $list = BookmarkList::factory()->for($owner)->private()->create();

    $this->actingAs($other)
        ->get(route('lists.show', $list))
        ->assertForbidden();
});

test('private list is not visible to guests', function (): void {
    $owner = User::factory()->create();
    $list = BookmarkList::factory()->for($owner)->private()->create();

    $this->get(route('profile.list', [$owner->username, $list->slug]))
        ->assertNotFound();
});

test('public list is visible to guests', function (): void {
    $owner = User::factory()->create();
    $list = BookmarkList::factory()->for($owner)->public()->create();

    $this->get(route('profile.list', [$owner->username, $list->slug]))
        ->assertOk()
        ->assertSee($list->title);
});

test('unlisted list is visible via direct link', function (): void {
    $owner = User::factory()->create();
    $list = BookmarkList::factory()->for($owner)->unlisted()->create();

    $this->get(route('profile.list', [$owner->username, $list->slug]))
        ->assertOk()
        ->assertSee($list->title);
});

test('public list appears on user profile', function (): void {
    $user = User::factory()->create();

    $publicList = BookmarkList::factory()->for($user)->public()->create();
    $privateList = BookmarkList::factory()->for($user)->private()->create();

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee($publicList->title)
        ->assertDontSee($privateList->title);
});

test('unlisted list does not appear on user profile', function (): void {
    $user = User::factory()->create();

    $unlistedList = BookmarkList::factory()->for($user)->unlisted()->create();

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertDontSee($unlistedList->title);
});
