<?php

use Illuminate\Support\Facades\Route;
use Laravel\WorkOS\Http\Middleware\ValidateSessionWithWorkOS;
use Livewire\Volt\Volt;

Route::get('/', fn (): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View => view('welcome'));

// OAuth Protected Resource Metadata (MCP spec)
// Named 'mcp.oauth.protected-resource' so Laravel MCP's AddWwwAuthenticateHeader middleware can find it
Route::get('/.well-known/oauth-protected-resource/{path?}', fn () => response()->json([
    'resource' => config('app.url'),
    'authorization_servers' => [config('services.workos.authkit_domain')],
    'bearer_methods_supported' => ['header'],
]))->where('path', '.*')->name('mcp.oauth.protected-resource');

// OAuth Authorization Server Metadata - proxy to WorkOS AuthKit
// Named 'mcp.oauth.authorization-server' for consistency with MCP conventions
Route::get('/.well-known/oauth-authorization-server/{path?}', function () {
    $authkitDomain = config('services.workos.authkit_domain');
    $organizationId = config('services.workos.default_organization_id');

    // Include organization_id in authorize URL so WorkOS includes role/permissions in JWT
    $authorizeUrl = $authkitDomain.'/oauth2/authorize';
    if ($organizationId) {
        $authorizeUrl .= '?organization_id='.$organizationId;
    }

    return response()->json([
        'issuer' => $authkitDomain,
        'authorization_endpoint' => $authorizeUrl,
        'token_endpoint' => $authkitDomain.'/oauth2/token',
        'registration_endpoint' => $authkitDomain.'/oauth2/register',
        'userinfo_endpoint' => $authkitDomain.'/oauth2/userinfo',
        'jwks_uri' => $authkitDomain.'/oauth2/jwks',
        'response_types_supported' => ['code'],
        'code_challenge_methods_supported' => ['S256'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
    ]);
})->where('path', '.*')->name('mcp.oauth.authorization-server');

Route::middleware([
    'auth',
    ValidateSessionWithWorkOS::class,
])->group(function (): void {
    // Dashboard redirects to lists
    Route::redirect('dashboard', '/lists')->name('dashboard');

    // Lists management
    Volt::route('lists', 'lists.index')->name('lists.index');
    Volt::route('lists/create', 'lists.create')->name('lists.create');
    Volt::route('lists/{list}', 'lists.show')->name('lists.show');
    Volt::route('lists/{list}/settings', 'lists.settings')->name('lists.settings');
});

// Public profile routes (no auth required)
Volt::route('@{username}', 'profile.show')->name('profile.show');
Volt::route('@{username}/{slug}', 'profile.list')->name('profile.list');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
