<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyWorkOsJwt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
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

    // The Laravel WorkOS SDK caches JWK with this key
    Cache::put('workos:jwk', [
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

test('cached user role is used on subsequent requests', function (): void {
    // Pre-populate the cache with role and permissions
    $cacheKey = 'workos_user_role:user_123:org_456';
    Cache::put($cacheKey, ['free-tier', ['bookmarks:read', 'lists:read', 'tags:read']], 300);

    $middleware = new VerifyWorkOsJwt;
    $method = new ReflectionMethod($middleware, 'fetchRoleAndPermissions');

    [$role, $permissions] = $method->invoke($middleware, 'user_123', 'org_456');

    expect($role)->toBe('free-tier');
    expect($permissions)->toBe(['bookmarks:read', 'lists:read', 'tags:read']);
});

test('cached organization roles are used for permission lookup', function (): void {
    // Pre-populate the cache with organization roles
    $rolesCacheKey = 'workos_org_roles:org_456';
    Cache::put($rolesCacheKey, [
        'free-tier' => ['bookmarks:read', 'lists:read'],
        'subscriber' => ['bookmarks:read', 'bookmarks:write', 'lists:read', 'lists:write'],
    ], 3600);

    $middleware = new VerifyWorkOsJwt;
    $method = new ReflectionMethod($middleware, 'fetchRolePermissions');

    $permissions = $method->invoke($middleware, 'org_456', 'subscriber');

    expect($permissions)->toBe(['bookmarks:read', 'bookmarks:write', 'lists:read', 'lists:write']);
});

test('returns empty permissions for unknown role', function (): void {
    // Pre-populate the cache with organization roles
    $rolesCacheKey = 'workos_org_roles:org_456';
    Cache::put($rolesCacheKey, [
        'free-tier' => ['bookmarks:read'],
    ], 3600);

    $middleware = new VerifyWorkOsJwt;
    $method = new ReflectionMethod($middleware, 'fetchRolePermissions');

    $permissions = $method->invoke($middleware, 'org_456', 'unknown-role');

    expect($permissions)->toBe([]);
});
