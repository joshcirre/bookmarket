<?php

declare(strict_types=1);

use App\Mcp\Servers\BookmarketServer;
use App\Mcp\Tools\Bookmarks\CreateBookmarkTool;
use App\Mcp\Tools\Bookmarks\DeleteBookmarkTool;
use App\Mcp\Tools\Bookmarks\GetBookmarkTool;
use App\Mcp\Tools\Bookmarks\MoveBookmarkTool;
use App\Mcp\Tools\Bookmarks\ReorderBookmarksTool;
use App\Mcp\Tools\Bookmarks\UpdateBookmarkTool;
use App\Models\Bookmark;
use App\Models\BookmarkList;
use App\Models\User;

test('create_bookmark adds bookmark to list', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    $response = BookmarketServer::actingAs($user)
        ->tool(CreateBookmarkTool::class, [
            'list_id' => $list->id,
            'url' => 'https://laravel.com',
            'title' => 'Laravel Documentation',
            'description' => 'Official Laravel docs',
        ]);

    $response->assertOk();

    $this->assertDatabaseHas('bookmarks', [
        'bookmark_list_id' => $list->id,
        'user_id' => $user->id,
        'url' => 'https://laravel.com',
        'title' => 'Laravel Documentation',
        'domain' => 'laravel.com',
    ]);

    $list->refresh();
    expect($list->bookmarks_count)->toBe(1);
});

test('create_bookmark requires valid url', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    $response = BookmarketServer::actingAs($user)
        ->tool(CreateBookmarkTool::class, [
            'list_id' => $list->id,
            'url' => 'not-a-valid-url',
            'title' => 'Test',
        ]);

    $response->assertHasErrors();
});

test('create_bookmark fails for other users list', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $list = BookmarkList::factory()->for($otherUser)->create();

    $response = BookmarketServer::actingAs($user)
        ->tool(CreateBookmarkTool::class, [
            'list_id' => $list->id,
            'url' => 'https://example.com',
            'title' => 'Test',
        ]);

    $response->assertHasErrors();
});

test('get_bookmark returns bookmark details', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create([
        'title' => 'Test Bookmark',
        'url' => 'https://example.com',
    ]);

    $response = BookmarketServer::actingAs($user)
        ->tool(GetBookmarkTool::class, ['bookmark_id' => $bookmark->id]);

    $response->assertOk();
    $response->assertSee('Test Bookmark');
    $response->assertSee('example.com');
});

test('update_bookmark modifies bookmark', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create([
        'title' => 'Original Title',
    ]);

    $response = BookmarketServer::actingAs($user)
        ->tool(UpdateBookmarkTool::class, [
            'bookmark_id' => $bookmark->id,
            'title' => 'Updated Title',
            'description' => 'New description',
        ]);

    $response->assertOk();

    $bookmark->refresh();
    expect($bookmark->title)->toBe('Updated Title');
    expect($bookmark->description)->toBe('New description');
});

test('update_bookmark can change url and updates domain', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create([
        'url' => 'https://old-site.com/page',
        'domain' => 'old-site.com',
    ]);

    $response = BookmarketServer::actingAs($user)
        ->tool(UpdateBookmarkTool::class, [
            'bookmark_id' => $bookmark->id,
            'url' => 'https://new-site.com/page',
        ]);

    $response->assertOk();

    $bookmark->refresh();
    expect($bookmark->url)->toBe('https://new-site.com/page');
    expect($bookmark->domain)->toBe('new-site.com');
});

test('delete_bookmark removes bookmark', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create();

    $response = BookmarketServer::actingAs($user)
        ->tool(DeleteBookmarkTool::class, ['bookmark_id' => $bookmark->id]);

    $response->assertOk();

    $this->assertDatabaseMissing('bookmarks', ['id' => $bookmark->id]);

    $list->refresh();
    expect($list->bookmarks_count)->toBe(0);
});

test('move_bookmark moves to different list', function (): void {
    $user = User::factory()->create();
    $sourceList = BookmarkList::factory()->for($user)->create(['title' => 'Source']);
    $targetList = BookmarkList::factory()->for($user)->create(['title' => 'Target']);
    $bookmark = Bookmark::factory()->for($sourceList)->for($user)->create();

    $response = BookmarketServer::actingAs($user)
        ->tool(MoveBookmarkTool::class, [
            'bookmark_id' => $bookmark->id,
            'target_list_id' => $targetList->id,
        ]);

    $response->assertOk();

    $bookmark->refresh();
    expect($bookmark->bookmark_list_id)->toBe($targetList->id);

    $sourceList->refresh();
    $targetList->refresh();
    expect($sourceList->bookmarks_count)->toBe(0);
    expect($targetList->bookmarks_count)->toBe(1);
});

test('move_bookmark fails when already in target list', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create();

    $response = BookmarketServer::actingAs($user)
        ->tool(MoveBookmarkTool::class, [
            'bookmark_id' => $bookmark->id,
            'target_list_id' => $list->id,
        ]);

    $response->assertHasErrors();
});

test('reorder_bookmarks changes positions', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();

    $bookmark1 = Bookmark::factory()->for($list)->for($user)->create(['position' => 1]);
    $bookmark2 = Bookmark::factory()->for($list)->for($user)->create(['position' => 2]);
    $bookmark3 = Bookmark::factory()->for($list)->for($user)->create(['position' => 3]);

    // Reverse the order
    $response = BookmarketServer::actingAs($user)
        ->tool(ReorderBookmarksTool::class, [
            'list_id' => $list->id,
            'bookmark_ids' => [$bookmark3->id, $bookmark2->id, $bookmark1->id],
        ]);

    $response->assertOk();

    $bookmark1->refresh();
    $bookmark2->refresh();
    $bookmark3->refresh();

    expect($bookmark3->position)->toBe(1);
    expect($bookmark2->position)->toBe(2);
    expect($bookmark1->position)->toBe(3);
});

test('reorder_bookmarks fails with invalid bookmark ids', function (): void {
    $user = User::factory()->create();
    $list = BookmarkList::factory()->for($user)->create();
    Bookmark::factory()->for($list)->for($user)->create();

    $response = BookmarketServer::actingAs($user)
        ->tool(ReorderBookmarksTool::class, [
            'list_id' => $list->id,
            'bookmark_ids' => [999, 998],
        ]);

    $response->assertHasErrors();
});
