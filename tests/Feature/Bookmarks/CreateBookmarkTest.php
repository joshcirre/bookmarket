<?php

declare(strict_types=1);

use App\Jobs\FetchBookmarkMetadata;
use App\Models\Bookmark;
use App\Models\BookmarkList;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;

test('owner can add bookmark to their list', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    $this->actingAs($user);

    Volt::test('lists.show', ['list' => $list])
        ->set('showAddModal', true)
        ->set('url', 'https://laravel.com')
        ->set('notes', 'Official Laravel website')
        ->call('addBookmark')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('bookmarks', [
        'bookmark_list_id' => $list->id,
        'user_id' => $user->id,
        'url' => 'https://laravel.com',
    ]);

    Queue::assertPushed(FetchBookmarkMetadata::class);
});

test('bookmark domain is extracted from url', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    $this->actingAs($user);

    Volt::test('lists.show', ['list' => $list])
        ->set('showAddModal', true)
        ->set('url', 'https://docs.laravel.com/11.x/installation')
        ->call('addBookmark');

    $bookmark = Bookmark::query()->first();
    expect($bookmark->domain)->toBe('docs.laravel.com');
});

test('bookmark position is auto-assigned', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    $this->actingAs($user);

    Volt::test('lists.show', ['list' => $list])
        ->set('showAddModal', true)
        ->set('url', 'https://first.com')
        ->call('addBookmark');

    Volt::test('lists.show', ['list' => $list])
        ->set('showAddModal', true)
        ->set('url', 'https://second.com')
        ->call('addBookmark');

    $bookmarks = $list->bookmarks()->orderBy('position')->get();

    expect($bookmarks[0]->position)->toBe(1);
    expect($bookmarks[1]->position)->toBe(2);
});

test('tags can be attached to bookmark', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    // Pre-create some tags
    $tag1 = Tag::findOrCreateByName('laravel');
    $tag2 = Tag::findOrCreateByName('php');
    $tag3 = Tag::findOrCreateByName('tutorial');

    $this->actingAs($user);

    Volt::test('lists.show', ['list' => $list])
        ->set('showAddModal', true)
        ->set('url', 'https://example.com')
        ->set('selectedTags', [$tag1->id, $tag2->id, $tag3->id])
        ->call('addBookmark');

    $bookmark = Bookmark::query()->first();
    $tagNames = $bookmark->tags->pluck('name')->toArray();

    expect($tagNames)->toContain('laravel', 'php', 'tutorial');
});

test('bookmarks_count is incremented on list', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    expect($list->fresh()->bookmarks_count)->toBe(0);

    $this->actingAs($user);

    Volt::test('lists.show', ['list' => $list])
        ->set('showAddModal', true)
        ->set('url', 'https://example.com')
        ->call('addBookmark');

    $list->refresh();
    expect($list->bookmarks_count)->toBe(1);
});

test('url is required', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    $this->actingAs($user);

    Volt::test('lists.show', ['list' => $list])
        ->set('showAddModal', true)
        ->set('url', '')
        ->call('addBookmark')
        ->assertHasErrors(['url']);
});

test('url must be valid', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    $this->actingAs($user);

    Volt::test('lists.show', ['list' => $list])
        ->set('showAddModal', true)
        ->set('url', 'not-a-valid-url')
        ->call('addBookmark')
        ->assertHasErrors(['url']);
});

test('other users cannot add bookmarks to list', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $list = BookmarkList::factory()->for($owner)->create();

    $this->actingAs($other);

    Volt::test('lists.show', ['list' => $list])
        ->assertForbidden();
});
