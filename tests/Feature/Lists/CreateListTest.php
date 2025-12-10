<?php

declare(strict_types=1);

use App\Enums\ListVisibility;
use App\Models\BookmarkList;
use App\Models\User;
use Livewire\Volt\Volt;

test('guests cannot access create list page', function (): void {
    $this->get(route('lists.create'))
        ->assertRedirect(route('login'));
});

test('authenticated users can view create list page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('lists.create'))
        ->assertOk();
});

test('user can create a private list', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('lists.create')
        ->set('title', 'My Reading List')
        ->set('description', 'Books I want to read')
        ->set('visibility', 'private')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertDatabaseHas('bookmark_lists', [
        'user_id' => $user->id,
        'title' => 'My Reading List',
        'description' => 'Books I want to read',
        'visibility' => ListVisibility::Private->value,
    ]);
});

test('user can create a public list', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('lists.create')
        ->set('title', 'Public Resources')
        ->set('visibility', 'public')
        ->call('create')
        ->assertHasNoErrors();

    $list = BookmarkList::query()->where('title', 'Public Resources')->first();

    expect($list)
        ->visibility->toBe(ListVisibility::Public)
        ->user_id->toBe($user->id);
});

test('list title is required', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('lists.create')
        ->set('title', '')
        ->set('visibility', 'private')
        ->call('create')
        ->assertHasErrors(['title' => 'required']);
});

test('list slug is auto-generated from title', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    Volt::test('lists.create')
        ->set('title', 'Laravel Packages')
        ->set('visibility', 'private')
        ->call('create')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('bookmark_lists', [
        'user_id' => $user->id,
        'slug' => 'laravel-packages',
    ]);
});

test('duplicate slugs are made unique per user', function (): void {
    $user = User::factory()->create();

    BookmarkList::factory()->for($user)->create(['title' => 'Test', 'slug' => 'test']);

    $this->actingAs($user);

    Volt::test('lists.create')
        ->set('title', 'Test')
        ->set('visibility', 'private')
        ->call('create')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('bookmark_lists', [
        'user_id' => $user->id,
        'slug' => 'test-1',
    ]);
});
