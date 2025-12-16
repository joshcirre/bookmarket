<?php

declare(strict_types=1);

use App\Mcp\Tools\Bookmarks\CreateBookmarkTool;
use App\Mcp\Tools\Bookmarks\DeleteBookmarkTool;
use App\Mcp\Tools\Lists\ListAllListsTool;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

beforeEach(function (): void {
    // Clear any cached data
    cache()->flush();
});

test('tools are available when user has required permission', function (): void {
    $user = User::factory()->create(['workos_id' => 'user_123']);

    // Set permissions on the user (simulating JWT extraction)
    $user->setMcpPermissions(['lists:read', 'bookmarks:read']);

    Auth::setUser($user);

    $tool = new ListAllListsTool;

    expect($tool->shouldRegister())->toBeTrue();
});

test('tools are denied when user lacks required permission', function (): void {
    $user = User::factory()->create(['workos_id' => 'user_123']);

    // Only give read permissions, not write
    $user->setMcpPermissions(['lists:read', 'bookmarks:read']);

    Auth::setUser($user);

    $tool = new CreateBookmarkTool;

    expect($tool->shouldRegister())->toBeFalse();
});

test('delete tools require delete permission', function (): void {
    $user = User::factory()->create(['workos_id' => 'user_123']);

    // Give write but not delete permission
    $user->setMcpPermissions(['bookmarks:read', 'bookmarks:write']);

    Auth::setUser($user);

    $tool = new DeleteBookmarkTool;

    expect($tool->shouldRegister())->toBeFalse();

    // Now add delete permission
    $user->setMcpPermissions(['bookmarks:read', 'bookmarks:write', 'bookmarks:delete']);

    expect($tool->shouldRegister())->toBeTrue();
});

test('tools are denied when user has no permissions', function (): void {
    $user = User::factory()->create(['workos_id' => 'user_123']);

    // No permissions set (empty array)
    $user->setMcpPermissions([]);

    Auth::setUser($user);

    $tool = new ListAllListsTool;

    expect($tool->shouldRegister())->toBeFalse();
});

test('tools are denied when no user is authenticated', function (): void {
    // Don't set any user
    Auth::logout();

    $tool = new ListAllListsTool;

    expect($tool->shouldRegister())->toBeFalse();
});

test('user hasMcpPermission method works correctly', function (): void {
    $user = User::factory()->create();

    $user->setMcpPermissions(['bookmarks:read', 'lists:write']);

    expect($user->hasMcpPermission('bookmarks:read'))->toBeTrue();
    expect($user->hasMcpPermission('lists:write'))->toBeTrue();
    expect($user->hasMcpPermission('bookmarks:delete'))->toBeFalse();
    expect($user->hasMcpPermission('nonexistent'))->toBeFalse();
});

test('user hasAnyMcpPermission method works correctly', function (): void {
    $user = User::factory()->create();

    $user->setMcpPermissions(['bookmarks:read']);

    expect($user->hasAnyMcpPermission(['bookmarks:read', 'bookmarks:write']))->toBeTrue();
    expect($user->hasAnyMcpPermission(['bookmarks:delete', 'lists:delete']))->toBeFalse();
});

test('user role is set and retrieved correctly', function (): void {
    $user = User::factory()->create();

    expect($user->getMcpRole())->toBeNull();

    $user->setMcpRole('subscriber');

    expect($user->getMcpRole())->toBe('subscriber');
});

test('permissions can be set from object (JWT decode format)', function (): void {
    $user = User::factory()->create();

    // JWT::decode returns stdClass, simulate that
    $permissionsFromJwt = (object) ['0' => 'bookmarks:read', '1' => 'lists:write'];

    $user->setMcpPermissions($permissionsFromJwt);

    expect($user->getMcpPermissions())->toBe(['0' => 'bookmarks:read', '1' => 'lists:write']);
    expect($user->hasMcpPermission('bookmarks:read'))->toBeTrue();
});

test('full subscriber role has access to all tools', function (): void {
    $user = User::factory()->create(['workos_id' => 'user_123']);

    // Subscriber gets all permissions
    $user->setMcpRole('subscriber');
    $user->setMcpPermissions([
        'bookmarks:read',
        'bookmarks:write',
        'bookmarks:delete',
        'lists:read',
        'lists:write',
        'lists:delete',
        'tags:read',
        'tags:write',
    ]);

    Auth::setUser($user);

    $listTool = new ListAllListsTool;
    $createTool = new CreateBookmarkTool;
    $deleteTool = new DeleteBookmarkTool;

    expect($listTool->shouldRegister())->toBeTrue();
    expect($createTool->shouldRegister())->toBeTrue();
    expect($deleteTool->shouldRegister())->toBeTrue();
});

test('free member role only has read access', function (): void {
    $user = User::factory()->create(['workos_id' => 'user_123']);

    // Free member only gets read permissions
    $user->setMcpRole('member');
    $user->setMcpPermissions([
        'bookmarks:read',
        'lists:read',
        'tags:read',
    ]);

    Auth::setUser($user);

    $listTool = new ListAllListsTool;
    $createTool = new CreateBookmarkTool;
    $deleteTool = new DeleteBookmarkTool;

    expect($listTool->shouldRegister())->toBeTrue();
    expect($createTool->shouldRegister())->toBeFalse();
    expect($deleteTool->shouldRegister())->toBeFalse();
});
