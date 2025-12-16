<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyWorkOsJwt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
});

test('getPermissionsForRole returns correct permissions for free-tier', function (): void {
    $middleware = new VerifyWorkOsJwt;

    $method = new ReflectionMethod($middleware, 'getPermissionsForRole');

    $permissions = $method->invoke($middleware, 'free-tier');

    expect($permissions)->toBe([
        'bookmarks:read',
        'lists:read',
        'tags:read',
    ]);
});

test('getPermissionsForRole returns correct permissions for subscriber', function (): void {
    $middleware = new VerifyWorkOsJwt;

    $method = new ReflectionMethod($middleware, 'getPermissionsForRole');

    $permissions = $method->invoke($middleware, 'subscriber');

    expect($permissions)->toBe([
        'bookmarks:read',
        'bookmarks:write',
        'bookmarks:delete',
        'lists:read',
        'lists:write',
        'lists:delete',
        'tags:read',
        'tags:write',
    ]);
});

test('getPermissionsForRole returns empty array for unknown role', function (): void {
    $middleware = new VerifyWorkOsJwt;

    $method = new ReflectionMethod($middleware, 'getPermissionsForRole');

    $permissions = $method->invoke($middleware, 'unknown-role');

    expect($permissions)->toBe([]);
});

test('getPermissionsForRole returns empty array for null role', function (): void {
    $middleware = new VerifyWorkOsJwt;

    $method = new ReflectionMethod($middleware, 'getPermissionsForRole');

    $permissions = $method->invoke($middleware, null);

    expect($permissions)->toBe([]);
});

test('fetchRoleFromWorkOs returns role and permissions from WorkOS API', function (): void {
    Http::fake([
        'api.workos.com/user_management/organization_memberships*' => Http::response([
            'data' => [
                [
                    'id' => 'om_123',
                    'user_id' => 'user_abc',
                    'organization_id' => 'org_xyz',
                    'role' => [
                        'slug' => 'free-tier',
                        'name' => 'Free Tier',
                    ],
                ],
            ],
        ], 200),
    ]);

    $middleware = new VerifyWorkOsJwt;

    $method = new ReflectionMethod($middleware, 'fetchRoleFromWorkOs');

    [$role, $permissions] = $method->invoke($middleware, 'user_abc', 'org_xyz');

    expect($role)->toBe('free-tier');
    expect($permissions)->toBe([
        'bookmarks:read',
        'lists:read',
        'tags:read',
    ]);
});

test('fetchRoleFromWorkOs caches the result', function (): void {
    Http::fake([
        'api.workos.com/user_management/organization_memberships*' => Http::response([
            'data' => [
                [
                    'role' => [
                        'slug' => 'subscriber',
                    ],
                ],
            ],
        ], 200),
    ]);

    $middleware = new VerifyWorkOsJwt;
    $method = new ReflectionMethod($middleware, 'fetchRoleFromWorkOs');

    // First call
    [$role1, $permissions1] = $method->invoke($middleware, 'user_abc', 'org_xyz');

    // Second call should use cache
    [$role2, $permissions2] = $method->invoke($middleware, 'user_abc', 'org_xyz');

    // Should only have made one HTTP request
    Http::assertSentCount(1);

    expect($role1)->toBe('subscriber');
    expect($role2)->toBe('subscriber');
});

test('fetchRoleFromWorkOs returns empty when API fails', function (): void {
    Http::fake([
        'api.workos.com/user_management/organization_memberships*' => Http::response([
            'error' => 'Unauthorized',
        ], 401),
    ]);

    $middleware = new VerifyWorkOsJwt;
    $method = new ReflectionMethod($middleware, 'fetchRoleFromWorkOs');

    [$role, $permissions] = $method->invoke($middleware, 'user_abc', 'org_xyz');

    expect($role)->toBeNull();
    expect($permissions)->toBe([]);
});

test('fetchRoleFromWorkOs returns empty when no memberships found', function (): void {
    Http::fake([
        'api.workos.com/user_management/organization_memberships*' => Http::response([
            'data' => [],
        ], 200),
    ]);

    $middleware = new VerifyWorkOsJwt;
    $method = new ReflectionMethod($middleware, 'fetchRoleFromWorkOs');

    [$role, $permissions] = $method->invoke($middleware, 'user_abc', 'org_xyz');

    expect($role)->toBeNull();
    expect($permissions)->toBe([]);
});

test('middleware returns 401 when no token provided', function (): void {
    $middleware = new VerifyWorkOsJwt;

    $request = Request::create('/mcp', 'GET');

    $response = $middleware->handle($request, fn () => response('OK'));

    expect($response->getStatusCode())->toBe(401);
    expect($response->getData()->error)->toBe('No token provided');
    expect($response->headers->get('WWW-Authenticate'))->toContain('resource_metadata');
});

test('middleware returns 401 for invalid token', function (): void {
    $middleware = new VerifyWorkOsJwt;

    $request = Request::create('/mcp', 'GET');
    $request->headers->set('Authorization', 'Bearer invalid.token.here');

    // Need to mock the JWKS fetch so it doesn't fail on network
    Cache::put('workos_jwks', [
        'keys' => [
            [
                'kty' => 'RSA',
                'kid' => 'test-key',
                'use' => 'sig',
                'alg' => 'RS256',
                'n' => 'test',
                'e' => 'AQAB',
            ],
        ],
    ], 3600);

    $response = $middleware->handle($request, fn () => response('OK'));

    expect($response->getStatusCode())->toBe(401);
    expect($response->getData()->error)->toBe('Invalid token');
});
