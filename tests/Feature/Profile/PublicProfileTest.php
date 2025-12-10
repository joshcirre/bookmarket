<?php

declare(strict_types=1);

use App\Models\BookmarkList;
use App\Models\User;

test('public profile page loads for valid username', function (): void {
    $user = User::factory()->create(['username' => 'johndoe']);

    $this->get(route('profile.show', 'johndoe'))
        ->assertOk()
        ->assertSee($user->name)
        ->assertSee('@johndoe');
});

test('public profile shows 404 for invalid username', function (): void {
    $this->get(route('profile.show', 'nonexistent'))
        ->assertNotFound();
});

test('public profile shows user bio', function (): void {
    $user = User::factory()->create([
        'username' => 'developer',
        'bio' => 'I build things with Laravel',
    ]);

    $this->get(route('profile.show', 'developer'))
        ->assertOk()
        ->assertSee('I build things with Laravel');
});

test('public profile only shows public lists', function (): void {
    $user = User::factory()->create();

    $publicList = BookmarkList::factory()->for($user)->public()->create(['title' => 'Public List']);
    $privateList = BookmarkList::factory()->for($user)->private()->create(['title' => 'Private List']);
    $unlistedList = BookmarkList::factory()->for($user)->unlisted()->create(['title' => 'Unlisted List']);

    $this->get(route('profile.show', $user->username))
        ->assertOk()
        ->assertSee('Public List')
        ->assertDontSee('Private List')
        ->assertDontSee('Unlisted List');
});

test('public list page shows bookmarks', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->public()->create();

    $list->bookmarks()->create([
        'user_id' => $user->id,
        'url' => 'https://laravel.com',
        'title' => 'Laravel Framework',
    ]);

    $this->get(route('profile.list', [$user->username, $list->slug]))
        ->assertOk()
        ->assertSee('Laravel Framework')
        ->assertSee('laravel.com');
});

test('accessing list with wrong username returns 404', function (): void {
    $owner = User::factory()->create(['username' => 'owner']);
    $other = User::factory()->create(['username' => 'other']);

    $list = BookmarkList::factory()->for($owner)->public()->create();

    // Try to access owner's list via other's username
    $this->get(route('profile.list', ['other', $list->slug]))
        ->assertNotFound();
});
