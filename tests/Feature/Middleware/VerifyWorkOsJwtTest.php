<?php

declare(strict_types=1);

use App\Http\Middleware\VerifyWorkOsJwt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

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

test('cached role is used on subsequent requests', function (): void {
    // Pre-populate the cache with a role
    $cacheKey = 'workos_role:user_123:org_456';
    Cache::put($cacheKey, ['free-tier', ['bookmarks:read', 'lists:read', 'tags:read']], 300);

    $middleware = new VerifyWorkOsJwt;
    $method = new ReflectionMethod($middleware, 'fetchRoleFromWorkOs');

    [$role, $permissions] = $method->invoke($middleware, 'user_123', 'org_456');

    expect($role)->toBe('free-tier');
    expect($permissions)->toBe(['bookmarks:read', 'lists:read', 'tags:read']);
});
