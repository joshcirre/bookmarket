<?php

declare(strict_types=1);

use App\Mcp\Servers\BookmarketServer;
use App\Mcp\Tools\Bookmarks\CreateBookmarkTool;
use App\Mcp\Tools\Lists\ListAllListsTool;
use App\Models\User;
use App\Services\WorkOsFga;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    // Clear any cached FGA responses
    cache()->flush();
});

test('tools are available when FGA is not configured', function (): void {
    // Ensure FGA is not configured
    config(['services.workos.fga_api_key' => null]);

    $fga = new WorkOsFga;
    expect($fga->isConfigured())->toBeFalse();

    $user = User::factory()->create(['workos_id' => 'user_123']);

    // Tools should be available when FGA is not configured
    $response = BookmarketServer::actingAs($user)
        ->tool(ListAllListsTool::class, []);

    $response->assertOk();
});

test('tools are available when user has FGA permission', function (): void {
    config(['services.workos.fga_api_key' => 'sk_test_key']);

    Http::fake([
        'api.workos.com/fga/v1/check' => Http::response([
            'result' => 'authorized',
        ], 200),
    ]);

    $fga = new WorkOsFga;

    expect($fga->canExecuteTool('user_123', 'create-bookmark-tool'))->toBeTrue();
});

test('tools are denied when user lacks FGA permission', function (): void {
    config(['services.workos.fga_api_key' => 'sk_test_key']);

    Http::fake([
        'api.workos.com/fga/v1/check' => Http::response([
            'result' => 'not_authorized',
        ], 200),
    ]);

    $fga = new WorkOsFga;

    expect($fga->canExecuteTool('user_123', 'delete-bookmark-tool'))->toBeFalse();
});

test('FGA permissions are cached', function (): void {
    config(['services.workos.fga_api_key' => 'sk_test_key']);

    Http::fake([
        'api.workos.com/fga/v1/check' => Http::response([
            'result' => 'authorized',
        ], 200),
    ]);

    $fga = new WorkOsFga;

    // First call - should hit the API
    $fga->canExecuteTool('user_123', 'test-tool');
    $fga->canExecuteTool('user_123', 'test-tool');
    $fga->canExecuteTool('user_123', 'test-tool');

    // Should only have made one HTTP request due to caching
    Http::assertSentCount(1);
});

test('batch permission check works', function (): void {
    config(['services.workos.fga_api_key' => 'sk_test_key']);

    Http::fake([
        'api.workos.com/fga/v1/check' => Http::response([
            'results' => [
                ['result' => 'authorized'],
                ['result' => 'not_authorized'],
                ['result' => 'authorized'],
            ],
        ], 200),
    ]);

    $fga = new WorkOsFga;
    $results = $fga->canExecuteTools('user_123', [
        'create-bookmark-tool',
        'delete-bookmark-tool',
        'list-all-lists-tool',
    ]);

    expect($results)->toBe([
        'create-bookmark-tool' => true,
        'delete-bookmark-tool' => false,
        'list-all-lists-tool' => true,
    ]);
});

test('grant tool access creates warrant', function (): void {
    config(['services.workos.fga_api_key' => 'sk_test_key']);

    Http::fake([
        'api.workos.com/fga/v1/warrants' => Http::response([], 200),
    ]);

    $fga = new WorkOsFga;
    $result = $fga->grantToolAccess('user_123', 'create-bookmark-tool');

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.workos.com/fga/v1/warrants'
            && $request['0']['op'] === 'create'
            && $request['0']['resource_type'] === 'mcp_tool'
            && $request['0']['resource_id'] === 'create-bookmark-tool'
            && $request['0']['relation'] === 'can_execute'
            && $request['0']['subject']['resource_type'] === 'user'
            && $request['0']['subject']['resource_id'] === 'user_123';
    });
});

test('revoke tool access deletes warrant', function (): void {
    config(['services.workos.fga_api_key' => 'sk_test_key']);

    Http::fake([
        'api.workos.com/fga/v1/warrants' => Http::response([], 200),
    ]);

    $fga = new WorkOsFga;
    $result = $fga->revokeToolAccess('user_123', 'delete-bookmark-tool');

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.workos.com/fga/v1/warrants'
            && $request['0']['op'] === 'delete'
            && $request['0']['resource_type'] === 'mcp_tool'
            && $request['0']['resource_id'] === 'delete-bookmark-tool';
    });
});

test('FGA failure fails open by default', function (): void {
    config(['services.workos.fga_api_key' => 'sk_test_key']);

    Http::fake([
        'api.workos.com/fga/v1/check' => Http::response([
            'error' => 'Internal Server Error',
        ], 500),
    ]);

    $fga = new WorkOsFga;

    // Should fail open (return true) when FGA API is unavailable
    expect($fga->canExecuteTool('user_123', 'test-tool'))->toBeTrue();
});

test('tools without FGA check are always available', function (): void {
    config(['services.workos.fga_api_key' => 'sk_test_key']);

    // Even with FGA configured, ListAllListsTool should check FGA
    // because it extends RbacTool with requiresFgaCheck = true by default
    Http::fake([
        'api.workos.com/fga/v1/check' => Http::response([
            'result' => 'authorized',
        ], 200),
    ]);

    $user = User::factory()->create(['workos_id' => 'user_123']);

    // This will trigger shouldRegister() which checks FGA
    $response = BookmarketServer::actingAs($user)
        ->tool(ListAllListsTool::class, []);

    $response->assertOk();
});

test('shouldRegister returns false when user has no workos_id', function (): void {
    config(['services.workos.fga_api_key' => 'sk_test_key']);

    // Create user without workos_id
    $user = User::factory()->create(['workos_id' => null]);

    $tool = new CreateBookmarkTool;
    $fga = new WorkOsFga;

    // Use reflection to test shouldRegister behavior
    \Illuminate\Support\Facades\Auth::setUser($user);

    // The tool should not register because user has no workos_id
    expect($tool->shouldRegister($fga))->toBeFalse();
});

test('grant multiple tools access works', function (): void {
    config(['services.workos.fga_api_key' => 'sk_test_key']);

    Http::fake([
        'api.workos.com/fga/v1/warrants' => Http::response([], 200),
    ]);

    $fga = new WorkOsFga;
    $result = $fga->grantToolsAccess('user_123', [
        'create-bookmark-tool',
        'update-bookmark-tool',
        'delete-bookmark-tool',
    ]);

    expect($result)->toBeTrue();

    Http::assertSent(function ($request) {
        $body = $request->data();

        return count($body) === 3
            && $body[0]['resource_id'] === 'create-bookmark-tool'
            && $body[1]['resource_id'] === 'update-bookmark-tool'
            && $body[2]['resource_id'] === 'delete-bookmark-tool';
    });
});
