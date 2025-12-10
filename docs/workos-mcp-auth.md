# WorkOS AuthKit for MCP Authentication

A guide for Laravel developers considering WorkOS AuthKit as their OAuth provider for MCP (Model Context Protocol) servers, instead of Laravel Passport.

## The Scenario

You're building a Laravel app that:
1. Already uses **WorkOS AuthKit** for user authentication (e.g., GitHub OAuth)
2. Wants to add **MCP tools** so AI agents (like Claude) can interact with your app
3. Needs to decide how to authenticate those AI agents

## Two Approaches

### Option A: Laravel Passport (Default Laravel MCP Approach)

Laravel MCP's documentation recommends Passport:

```php
// routes/ai.php
Mcp::oauthRoutes();
Mcp::web('/mcp/bookmarket', BookmarketServer::class)
    ->middleware('auth:api');
```

**What this requires:**
- Install Laravel Passport (`composer require laravel/passport`)
- Run migrations (creates 5+ OAuth tables)
- Generate encryption keys (`php artisan passport:keys`)
- Publish and customize the authorization view
- Configure Passport in `AppServiceProvider`

### Option B: WorkOS AuthKit (What We Built)

Use WorkOS as your OAuth authorization server directly:

```php
// routes/ai.php
Mcp::web('/mcp/bookmarket', BookmarketServer::class)
    ->middleware('workos.jwt');
```

**What this requires:**
- One middleware class (~50 lines)
- Two routes for OAuth discovery metadata
- Enable Dynamic Client Registration in WorkOS Dashboard
- No new database tables
- No additional packages (just `firebase/php-jwt`)

---

## Why WorkOS AuthKit for MCP?

### 1. Single Identity System

With Passport, you'd have two authentication systems:
- WorkOS for your web app (GitHub OAuth login)
- Passport for MCP/API access

**The problem:** Users might wonder why they have two different ways to authenticate, or worse, need separate credentials.

With WorkOS for both:
- User logs in via GitHub (WorkOS) on your web app
- User authorizes AI agent via GitHub (WorkOS) for MCP
- Same identity, same flow, no confusion

### 2. Zero Database Tables

| Approach | New Tables Required |
|----------|---------------------|
| Passport | `oauth_clients`, `oauth_access_tokens`, `oauth_refresh_tokens`, `oauth_auth_codes`, `oauth_personal_access_clients` |
| WorkOS | None - stateless JWT validation |

WorkOS issues JWTs that you validate against their public JWKS endpoint. No token storage needed.

### 3. Built-in Token Management

**With Passport:**
- Tokens live in your `oauth_access_tokens` table
- Need to build admin UI to view/revoke tokens
- No audit trail unless you build it

**With WorkOS:**
- See all sessions in WorkOS Dashboard
- View which clients (AI agents) have access
- Revoke access with one click
- Built-in audit logs

### 4. Enterprise-Ready Features

WorkOS includes features you'd have to build yourself with Passport:
- SSO (SAML, OIDC) if you need it later
- Directory sync
- Audit logs
- Compliance certifications

### 5. WorkOS Maintains the OAuth Server

The MCP spec evolves. With WorkOS:
- They keep up with spec changes
- You just validate JWTs

With Passport:
- You're responsible for OAuth compliance
- May need to update as MCP spec changes

---

## What We Had to Build

### 1. JWT Verification Middleware

`app/Http/Middleware/VerifyWorkOsJwt.php`:

```php
class VerifyWorkOsJwt
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthorizedResponse('No token provided');
        }

        try {
            // Fetch JWKS from WorkOS (cached for 1 hour)
            $keys = $this->getJwks();

            // Validate and decode the JWT
            $decoded = JWT::decode($token, $keys);

            // Find user by WorkOS ID (sub claim)
            $user = User::where('workos_id', $decoded->sub)->first();

            if (! $user) {
                return $this->unauthorizedResponse('User not found');
            }

            // Set user on Laravel's auth system
            Auth::setUser($user);

            return $next($request);
        } catch (\Exception) {
            return $this->unauthorizedResponse('Invalid token');
        }
    }

    protected function getJwks(): array
    {
        return Cache::remember('workos_jwks', 3600, function () {
            $jwksUri = config('services.workos.authkit_domain') . '/oauth2/jwks';
            $response = file_get_contents($jwksUri);
            return JWK::parseKeySet(json_decode($response, true));
        });
    }
}
```

### 2. OAuth Discovery Routes

MCP clients need to discover your OAuth endpoints. Two routes in `routes/web.php`:

```php
// Tell MCP clients where to authenticate
Route::get('/.well-known/oauth-protected-resource/{path?}', fn () => response()->json([
    'resource' => config('app.url'),
    'authorization_servers' => [config('services.workos.authkit_domain')],
    'bearer_methods_supported' => ['header'],
]))->name('mcp.oauth.protected-resource');

// Proxy OAuth metadata to WorkOS
Route::get('/.well-known/oauth-authorization-server/{path?}', function () {
    $authkitDomain = config('services.workos.authkit_domain');
    return response()->json([
        'issuer' => $authkitDomain,
        'authorization_endpoint' => $authkitDomain . '/oauth2/authorize',
        'token_endpoint' => $authkitDomain . '/oauth2/token',
        'registration_endpoint' => $authkitDomain . '/oauth2/register',
        'jwks_uri' => $authkitDomain . '/oauth2/jwks',
        // ... other OAuth metadata
    ]);
})->name('mcp.oauth.authorization-server');
```

**Important:** The route names (`mcp.oauth.protected-resource` and `mcp.oauth.authorization-server`) must match what Laravel MCP expects.

### 3. WorkOS Dashboard Configuration

In WorkOS Dashboard:
1. Go to **Connect** > **Configuration**
2. Enable **Dynamic Client Registration** (required for MCP clients to self-register)
3. Note your **AuthKit Domain** (e.g., `https://your-subdomain.authkit.app`)

### 4. Environment Configuration

```env
WORKOS_AUTHKIT_DOMAIN=https://your-subdomain.authkit.app
```

```php
// config/services.php
'workos' => [
    'client_id' => env('WORKOS_CLIENT_ID'),
    'api_key' => env('WORKOS_API_KEY'),
    'redirect_url' => env('WORKOS_REDIRECT_URL'),
    'authkit_domain' => env('WORKOS_AUTHKIT_DOMAIN'),
],
```

---

## The User Experience

### Adding MCP to Claude Code

```bash
claude mcp add bookmarket --transport http https://your-app.com/mcp/bookmarket
```

### What Happens

1. Claude Code requests access to your MCP server
2. Your server returns 401 with `WWW-Authenticate` header pointing to OAuth metadata
3. Claude Code discovers WorkOS as the authorization server
4. Browser opens to WorkOS AuthKit (GitHub OAuth)
5. User logs in (or is already logged in)
6. User approves the AI agent's access
7. WorkOS issues JWT to Claude Code
8. Claude Code includes JWT in all MCP requests
9. Your middleware validates JWT and attaches the user

**No manual token copying. No separate credentials. Just click approve.**

---

## Comparison Summary

| Feature | Passport | WorkOS AuthKit |
|---------|----------|----------------|
| Database tables | 5+ new tables | None |
| Token storage | Your database | WorkOS cloud |
| Authorization UI | Build yourself | Built-in |
| Key management | You manage | WorkOS manages |
| Token visibility | Query database | WorkOS Dashboard |
| Token revocation | Build admin UI | One-click in dashboard |
| Audit logs | Build yourself | Built-in |
| Setup time | ~30 minutes | ~15 minutes |
| Identity system | Separate from web auth | Same as web auth |

---

## When to Use Passport Instead

Passport might be better if:
- You're not already using WorkOS for authentication
- You need custom OAuth scopes/permissions
- You want to self-host everything
- You're already using Passport for other APIs

---

## Key Takeaway

> "If you're already using WorkOS for authentication, using it for MCP too means one login for users, no duplicate OAuth systems, and you get token management for free."

The extra setup (middleware + discovery routes) is a one-time thing. After that, you get a simpler architecture and better visibility into who's accessing your app via AI agents.
