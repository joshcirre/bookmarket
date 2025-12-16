<?php

declare(strict_types=1);

use App\Enums\ListVisibility;
use App\Mcp\Servers\BookmarketServer;
use App\Mcp\Tools\Lists\CreateListTool;
use App\Mcp\Tools\Lists\DeleteListTool;
use App\Mcp\Tools\Lists\GetListTool;
use App\Mcp\Tools\Lists\ListAllListsTool;
use App\Mcp\Tools\Lists\UpdateListTool;
use App\Models\Bookmark;
use App\Models\BookmarkList;
use App\Models\User;

/**
 * Helper to create a user with all MCP permissions for testing.
 */
function createListTestUser(): User
{
    $user = User::factory()->create();
    $user->setMcpPermissions([
        'bookmarks:read', 'bookmarks:write', 'bookmarks:delete',
        'lists:read', 'lists:write', 'lists:delete',
        'tags:read', 'tags:write',
    ]);

    return $user;
}

test('list_all_lists returns user lists', function (): void {
    $user = createListTestUser();
    BookmarkList::factory()->count(3)->for($user)->create();

    // Create lists for another user to ensure isolation
    $otherUser = User::factory()->create();
    BookmarkList::factory()->count(2)->for($otherUser)->create();

    $response = BookmarketServer::actingAs($user)
        ->tool(ListAllListsTool::class, []);

    $response->assertOk();
    $response->assertSee('"total": 3');
});

test('list_all_lists can include bookmarks', function (): void {
    $user = createListTestUser();
    $list = BookmarkList::factory()->for($user)->create();
    Bookmark::factory()->count(2)->for($list)->for($user)->create();

    $response = BookmarketServer::actingAs($user)
        ->tool(ListAllListsTool::class, ['include_bookmarks' => true]);

    $response->assertOk();
    $response->assertSee('"bookmarks":');
});

test('get_list returns list with bookmarks', function (): void {
    $user = createListTestUser();
    $list = BookmarkList::factory()->for($user)->create(['title' => 'Test List']);
    Bookmark::factory()->count(3)->for($list)->for($user)->create();

    $response = BookmarketServer::actingAs($user)
        ->tool(GetListTool::class, ['list_id' => $list->id]);

    $response->assertOk();
    $response->assertSee('Test List');
    $response->assertSee('"bookmarks":');
});

test('get_list returns error for non-existent list', function (): void {
    $user = createListTestUser();

    $response = BookmarketServer::actingAs($user)
        ->tool(GetListTool::class, ['list_id' => 999]);

    $response->assertHasErrors();
});

test('get_list returns error for other users list', function (): void {
    $user = createListTestUser();
    $otherUser = User::factory()->create();
    $list = BookmarkList::factory()->for($otherUser)->create();

    $response = BookmarketServer::actingAs($user)
        ->tool(GetListTool::class, ['list_id' => $list->id]);

    $response->assertHasErrors();
});

test('create_list creates a new list', function (): void {
    $user = createListTestUser();

    $response = BookmarketServer::actingAs($user)
        ->tool(CreateListTool::class, [
            'title' => 'My New List',
            'description' => 'A test description',
            'visibility' => 'public',
        ]);

    $response->assertOk();

    $this->assertDatabaseHas('bookmark_lists', [
        'user_id' => $user->id,
        'title' => 'My New List',
        'description' => 'A test description',
        'visibility' => ListVisibility::Public->value,
    ]);
});

test('create_list defaults to private visibility', function (): void {
    $user = createListTestUser();

    $response = BookmarketServer::actingAs($user)
        ->tool(CreateListTool::class, ['title' => 'Private List']);

    $response->assertOk();

    $this->assertDatabaseHas('bookmark_lists', [
        'user_id' => $user->id,
        'title' => 'Private List',
        'visibility' => ListVisibility::Private->value,
    ]);
});

test('create_list requires title', function (): void {
    $user = createListTestUser();

    $response = BookmarketServer::actingAs($user)
        ->tool(CreateListTool::class, ['description' => 'No title']);

    $response->assertHasErrors();
});

test('update_list updates list properties', function (): void {
    $user = createListTestUser();
    $list = BookmarkList::factory()->for($user)->create([
        'title' => 'Original Title',
        'visibility' => ListVisibility::Private,
    ]);

    $response = BookmarketServer::actingAs($user)
        ->tool(UpdateListTool::class, [
            'list_id' => $list->id,
            'title' => 'Updated Title',
            'visibility' => 'public',
        ]);

    $response->assertOk();

    $list->refresh();
    expect($list->title)->toBe('Updated Title');
    expect($list->visibility)->toBe(ListVisibility::Public);
});

test('update_list returns error without updates', function (): void {
    $user = createListTestUser();
    $list = BookmarkList::factory()->for($user)->create();

    $response = BookmarketServer::actingAs($user)
        ->tool(UpdateListTool::class, ['list_id' => $list->id]);

    $response->assertHasErrors();
});

test('delete_list requires confirmation', function (): void {
    $user = createListTestUser();
    $list = BookmarkList::factory()->for($user)->create();

    $response = BookmarketServer::actingAs($user)
        ->tool(DeleteListTool::class, [
            'list_id' => $list->id,
            'confirm' => false,
        ]);

    $response->assertHasErrors();

    $this->assertDatabaseHas('bookmark_lists', ['id' => $list->id]);
});

test('delete_list deletes list and bookmarks', function (): void {
    $user = createListTestUser();
    $list = BookmarkList::factory()->for($user)->create();
    $bookmark = Bookmark::factory()->for($list)->for($user)->create();

    $response = BookmarketServer::actingAs($user)
        ->tool(DeleteListTool::class, [
            'list_id' => $list->id,
            'confirm' => true,
        ]);

    $response->assertOk();

    $this->assertDatabaseMissing('bookmark_lists', ['id' => $list->id]);
    $this->assertDatabaseMissing('bookmarks', ['id' => $bookmark->id]);
});
