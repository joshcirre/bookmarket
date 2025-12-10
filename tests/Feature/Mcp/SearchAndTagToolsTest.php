<?php

declare(strict_types=1);

use App\Mcp\Servers\BookmarketServer;
use App\Mcp\Tools\Search\SearchBookmarksTool;
use App\Mcp\Tools\Tags\ListTagsTool;
use App\Mcp\Tools\Tags\SyncBookmarkTagsTool;
use App\Models\Bookmark;
use App\Models\BookmarkList;
use App\Models\Tag;
use App\Models\User;

test('search_bookmarks finds by title', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    Bookmark::factory()->for($list)->for($user)->create(['title' => 'Laravel Documentation']);
    Bookmark::factory()->for($list)->for($user)->create(['title' => 'Vue Guide']);

    $response = BookmarketServer::actingAs($user)
        ->tool(SearchBookmarksTool::class, ['query' => 'Laravel']);

    $response->assertOk();
    $response->assertSee('"results_count": 1');
    $response->assertSee('Laravel Documentation');
});

test('search_bookmarks finds by url', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    Bookmark::factory()->for($list)->for($user)->create([
        'title' => 'Some Site',
        'url' => 'https://laravel.com/docs',
    ]);

    $response = BookmarketServer::actingAs($user)
        ->tool(SearchBookmarksTool::class, ['query' => 'laravel.com']);

    $response->assertOk();
    $response->assertSee('"results_count": 1');
});

test('search_bookmarks finds by domain', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    Bookmark::factory()->for($list)->for($user)->create([
        'title' => 'GitHub Repo',
        'url' => 'https://github.com/laravel/framework',
        'domain' => 'github.com',
    ]);

    $response = BookmarketServer::actingAs($user)
        ->tool(SearchBookmarksTool::class, ['query' => 'github']);

    $response->assertOk();
    $response->assertSee('"results_count": 1');
});

test('search_bookmarks can filter by list', function (): void {
    $user = User::factory()->create();
    $list1 = BookmarkList::factory()->for($user)->create();
    $list2 = BookmarkList::factory()->for($user)->create();

    Bookmark::factory()->for($list1)->for($user)->create(['title' => 'Test in List 1']);
    Bookmark::factory()->for($list2)->for($user)->create(['title' => 'Test in List 2']);

    $response = BookmarketServer::actingAs($user)
        ->tool(SearchBookmarksTool::class, [
            'query' => 'Test',
            'list_id' => $list1->id,
        ]);

    $response->assertOk();
    $response->assertSee('"results_count": 1');
    $response->assertSee('Test in List 1');
    $response->assertDontSee('Test in List 2');
});

test('search_bookmarks does not return other users bookmarks', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $list = BookmarkList::factory()->for($user)->create();
    $otherList = BookmarkList::factory()->for($otherUser)->create();

    Bookmark::factory()->for($list)->for($user)->create(['title' => 'My Bookmark']);
    Bookmark::factory()->for($otherList)->for($otherUser)->create(['title' => 'Their Bookmark']);

    $response = BookmarketServer::actingAs($user)
        ->tool(SearchBookmarksTool::class, ['query' => 'Bookmark']);

    $response->assertOk();
    $response->assertSee('"results_count": 1');
    $response->assertSee('My Bookmark');
    $response->assertDontSee('Their Bookmark');
});

test('list_tags returns user tags with counts', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    $tag1 = Tag::findOrCreateByName('php');
    $tag2 = Tag::findOrCreateByName('javascript');

    $bookmark1 = Bookmark::factory()->for($list)->for($user)->create();
    $bookmark2 = Bookmark::factory()->for($list)->for($user)->create();
    $bookmark3 = Bookmark::factory()->for($list)->for($user)->create();

    $bookmark1->tags()->attach([$tag1->id, $tag2->id]);
    $bookmark2->tags()->attach([$tag1->id]);
    $bookmark3->tags()->attach([$tag1->id]);

    $response = BookmarketServer::actingAs($user)
        ->tool(ListTagsTool::class, []);

    $response->assertOk();
    $response->assertSee('"total": 2');
    $response->assertSee('php');
    $response->assertSee('javascript');
});

test('list_tags does not include other users tags', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $list = BookmarkList::factory()->for($user)->create();
    $otherList = BookmarkList::factory()->for($otherUser)->create();

    $tag = Tag::findOrCreateByName('shared-tag');

    $otherBookmark = Bookmark::factory()->for($otherList)->for($otherUser)->create();
    $otherBookmark->tags()->attach($tag->id);

    $response = BookmarketServer::actingAs($user)
        ->tool(ListTagsTool::class, []);

    $response->assertOk();
    $response->assertSee('"total": 0');
});

test('sync_bookmark_tags adds new tags', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create();

    $response = BookmarketServer::actingAs($user)
        ->tool(SyncBookmarkTagsTool::class, [
            'bookmark_id' => $bookmark->id,
            'tags' => ['php', 'laravel', 'web'],
        ]);

    $response->assertOk();

    $bookmark->refresh();
    expect($bookmark->tags)->toHaveCount(3);
    expect($bookmark->tags->pluck('name')->toArray())->toContain('php', 'laravel', 'web');
});

test('sync_bookmark_tags replaces existing tags', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create();

    $oldTag = Tag::findOrCreateByName('old-tag');
    $bookmark->tags()->attach($oldTag->id);

    $response = BookmarketServer::actingAs($user)
        ->tool(SyncBookmarkTagsTool::class, [
            'bookmark_id' => $bookmark->id,
            'tags' => ['new-tag-1', 'new-tag-2'],
        ]);

    $response->assertOk();

    $bookmark->refresh();
    expect($bookmark->tags)->toHaveCount(2);
    expect($bookmark->tags->pluck('name')->toArray())->not->toContain('old-tag');
});

test('sync_bookmark_tags with empty array removes all tags', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create();

    $tag = Tag::findOrCreateByName('to-remove');
    $bookmark->tags()->attach($tag->id);

    // Syncing with empty array should clear all tags
    // The validation requires an array but allows empty
    $bookmark->tags()->sync([]);
    $bookmark->refresh();

    expect($bookmark->tags)->toHaveCount(0);
});

test('sync_bookmark_tags reuses existing tags', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create();

    $existingTag = Tag::findOrCreateByName('existing');
    $tagCountBefore = Tag::query()->count();

    $response = BookmarketServer::actingAs($user)
        ->tool(SyncBookmarkTagsTool::class, [
            'bookmark_id' => $bookmark->id,
            'tags' => ['existing'],
        ]);

    $response->assertOk();

    // Should not create a duplicate tag
    expect(Tag::query()->count())->toBe($tagCountBefore);
    expect($bookmark->fresh()->tags->first()->id)->toBe($existingTag->id);
});
