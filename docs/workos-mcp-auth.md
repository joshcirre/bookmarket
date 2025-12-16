# WorkOS AuthKit vs Laravel Passport for MCP Authentication

A guide for Laravel developers choosing between WorkOS AuthKit and Laravel Passport for MCP (Model Context Protocol) authentication.

## The Scenario

You're building a Laravel app that:
1. Already uses **WorkOS AuthKit** for user authentication (e.g., GitHub OAuth)
2. Wants to add **MCP tools** so AI agents (like Claude) can interact with your app
3. Needs to decide how to authenticate those AI agents

## Two Approaches

### Option A: Laravel Passport (Standard Approach)

Laravel MCP's documentation recommends Passport:

```php
// routes/ai.php
Mcp::oauthRoutes();
Mcp::web('/mcp', BookmarketServer::class)
    ->middleware('auth:api');
```

**What this requires:**
- Install Laravel Passport (`composer require laravel/passport`)
- Run migrations (creates 5+ OAuth tables)
- Generate encryption keys (`php artisan passport:keys`)
- Publish and customize the authorization view
- Configure Passport in `AppServiceProvider`

### Option B: WorkOS AuthKit (Custom Approach)

Use WorkOS as your OAuth authorization server directly:

```php
// routes/ai.php
Mcp::web('/mcp', BookmarketServer::class)
    ->middleware('workos.jwt');
```

**What this requires:**
- One middleware class (~50 lines)
- Two routes for OAuth discovery metadata
- Enable Dynamic Client Registration in WorkOS Dashboard
- No new database tables
- No additional packages (just `firebase/php-jwt`)

---

## Honest Comparison

### Setup Time

| Approach | If everything goes right | Realistically |
|----------|-------------------------|---------------|
| Passport | ~5 minutes | ~10 minutes |
| WorkOS-only | ~15 minutes | 30+ minutes |

**Passport is faster to set up.** It's designed for this exact use case - just `composer require`, `passport:install`, add a trait, and you're done.

**WorkOS-only has more gotchas.** During our implementation, we hit several issues:
- JWKS endpoint is `/oauth2/jwks`, not `/sso/jwks` (returns 404)
- Route names must match what Laravel MCP expects (`mcp.oauth.protected-resource`)
- Must use `Auth::setUser()` not `$request->setUserResolver()` for MCP compatibility
- Debugging involves two systems (your app + WorkOS)

The WorkOS-only benefit is **ongoing simplicity** (one auth system, no extra tables), not faster initial setup.

### What's the SAME with Both Approaches

**User identity is the same either way.** With Passport, the flow is:

1. MCP client requests authorization
2. User redirected to your Laravel app
3. Laravel checks if user is logged in → if not, redirects to WorkOS (GitHub OAuth)
4. User authenticates via WorkOS, comes back logged in
5. User sees Passport authorization screen: "Allow Claude Code to access your bookmarks?"
6. User approves → Passport issues token **linked to that same WorkOS user**

So **"single identity"** isn't a differentiator - both approaches use the same user. The difference is **who issues and manages the MCP tokens**.

### Actual Trade-offs

| Aspect | WorkOS Only | WorkOS + Passport |
|--------|-------------|-------------------|
| Web login | WorkOS | WorkOS |
| MCP token issuer | WorkOS (JWT) | Passport |
| User identity | Same user | Same user |
| Token storage | WorkOS cloud (stateless) | Your database |
| Token visibility | Limited (see below) | Full - query `oauth_access_tokens` |
| Token revocation | Unclear | Easy - update DB or build admin UI |
| New DB tables | None | 5+ Passport tables |
| Setup complexity | Custom middleware | `Mcp::oauthRoutes()` |
| Packages needed | `firebase/php-jwt` | `laravel/passport` |

### Token Visibility: An Honest Look

**What WorkOS Dashboard shows:**
- OAuth clients (MCP applications) that have registered via Dynamic Client Registration
- You'll see entries like "Claude Code (bookmarket)"
- **But:** You don't see which users authorized which clients, or when

**What Passport gives you:**
- `oauth_access_tokens` table with:
  - `user_id` - exactly which user
  - `client_id` - which MCP client
  - `created_at` - when authorized
  - `revoked` - easy revocation

**Bottom line:** Passport actually gives you MORE visibility into token grants, not less.

---

## Why Choose WorkOS Only?

### 1. Simplicity - One Less System

With Passport alongside WorkOS, you maintain:
- WorkOS for web authentication
- Passport for MCP token issuance
- Two systems to understand and debug

With WorkOS only:
- One authentication provider for everything
- Fewer moving parts
- Less code to maintain

### 2. No Additional Database Tables

Passport creates 5+ tables. WorkOS JWTs are stateless - nothing to store.

### 3. One Less Package

No `laravel/passport` dependency. Fewer updates to track.

### 4. Future Enterprise Features

If you ever need SSO, directory sync, or compliance features, WorkOS has them built-in.

---

## Why Choose Passport?

### 1. First-Class Laravel MCP Support

```php
Mcp::oauthRoutes();  // That's it - all OAuth endpoints configured
```

vs. custom middleware and discovery routes with WorkOS.

### 2. Better Token Visibility

See exactly which tokens exist:
```sql
SELECT users.email, oauth_clients.name, oauth_access_tokens.created_at
FROM oauth_access_tokens
JOIN users ON users.id = oauth_access_tokens.user_id
JOIN oauth_clients ON oauth_clients.id = oauth_access_tokens.client_id;
```

### 3. Easy Revocation

```php
// Revoke a specific token
$token->revoke();

// Revoke all tokens for a user
$user->tokens()->update(['revoked' => true]);
```

### 4. Custom Scopes

Fine-grained permissions:
```php
Passport::tokensCan([
    'bookmarks:read' => 'Read your bookmarks',
    'bookmarks:write' => 'Create and modify bookmarks',
]);
```

### 5. Self-Hosted

All token data stays in your database. No external dependencies for token validation.

---

## What We Built (WorkOS Approach)

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

            // Set user on Laravel's auth system (required for MCP)
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

**Important:** The route names must match what Laravel MCP expects.

### 3. WorkOS Dashboard Configuration

1. Go to **Connect** > **Configuration**
2. Enable **Dynamic Client Registration**
3. Note your **AuthKit Domain** (e.g., `https://your-subdomain.authkit.app`)

---

## This App Has Both!

To demonstrate the difference, this app implements both approaches:

| Endpoint | Auth Method | Description |
|----------|-------------|-------------|
| `/mcp` | WorkOS JWT | Custom WorkOS integration |
| `/mcp/passport` | Passport | Standard Laravel MCP approach |

Both endpoints serve the same tools - you can compare the setup and behavior.

---

## The User Experience

### Adding MCP to Claude Code

```bash
# WorkOS approach
claude mcp add bookmarket --transport http https://your-app.com/mcp

# Passport approach
claude mcp add bookmarket-passport --transport http https://your-app.com/mcp/passport
```

### What Happens (Both Approaches)

1. Claude Code requests access to your MCP server
2. Your server returns 401 with `WWW-Authenticate` header
3. Claude Code discovers the authorization server
4. Browser opens for authentication
5. User logs in via GitHub (WorkOS) if not already
6. User approves the AI agent's access
7. Token issued to Claude Code
8. Claude Code includes token in all MCP requests

**No manual token copying. Same GitHub login. Just click approve.**

---

## Key Takeaway

> "Passport is the faster, easier setup. WorkOS-only is more work upfront but gives you ongoing simplicity - one auth system instead of two."

**Choose Passport if:**
- You want the quickest path to working MCP auth (~5-10 min)
- You want to see exactly which users have authorized which AI agents
- You want easy token revocation via database
- You're comfortable with 5+ extra database tables

**Choose WorkOS only if:**
- You're already all-in on WorkOS and want one auth system
- You don't need token visibility/revocation features
- You prefer no extra database tables
- You're okay with a longer initial setup

**Both approaches use the same WorkOS GitHub login** - users don't see a difference. The question is whether you want Passport managing MCP tokens (more visibility, more tables) or WorkOS managing everything (simpler, less visibility).

For this app, we implemented both at `/mcp` (WorkOS) and `/mcp/passport` (Passport) so you can compare them side-by-side.

---

## Tool-Level Access Control with WorkOS Roles & Permissions

Beyond authentication (who can access your MCP server), you may need **authorization** (what tools specific users can use). WorkOS AuthKit's built-in Roles & Permissions feature enables this without external API calls.

### Why Tool-Level RBAC?

Consider these scenarios:

- **Freemium SaaS**: Free users get read-only tools, paid users get all tools
- **Team Permissions**: Admins can delete, regular members can only create
- **Beta Features**: New tools rolled out gradually to specific roles

### How It Works

WorkOS Roles & Permissions are:

1. **Configured in the WorkOS Dashboard** under Roles & Permissions
2. **Baked into the JWT** at authentication time - no runtime API calls
3. **Checked locally** by reading the `permissions` claim from the token

This is simpler than WorkOS FGA (Fine-Grained Authorization) which requires external API calls for each permission check.

### Setting Up Permissions in WorkOS Dashboard

1. Go to **Roles and Permissions** in your WorkOS Dashboard
2. Create permissions using `resource:action` format:
   - `bookmarks:read`, `bookmarks:write`, `bookmarks:delete`
   - `lists:read`, `lists:write`, `lists:delete`
   - `tags:read`, `tags:write`

3. Create roles and assign permissions:
   - **member** (default): `bookmarks:read`, `lists:read`, `tags:read`
   - **subscriber**: All permissions

### Setting Up the Default Organization

Users must belong to an organization to receive role/permission claims in their JWT.

1. Create a default organization in WorkOS Dashboard
2. Add to your `.env`:
   ```
   WORKOS_DEFAULT_ORGANIZATION_ID=org_01XXXXXXXXXXXXXXXXXX
   ```

3. New users are automatically added via the `AddUserToDefaultOrganization` listener

> **Note on Organizations**: For this demo, we use a single default organization that all users belong to. In a production B2B SaaS, you'd typically have separate organizations for each customer/team, with organization admins able to manage roles for their members. This single-org approach works well for B2C freemium models.

### JWT Claims

When a user authenticates, their JWT includes:

```json
{
  "sub": "user_01ABC123",
  "org_id": "org_01XYZ789",
  "role": "subscriber",
  "permissions": ["bookmarks:read", "bookmarks:write", "bookmarks:delete", "lists:read", "lists:write", "lists:delete", "tags:read", "tags:write"]
}
```

### Laravel Implementation

#### 1. Extract Permissions in JWT Middleware

The `VerifyWorkOsJwt` middleware extracts role and permissions from the JWT:

```php
// Extract role and permissions from JWT claims
$user->setMcpRole($decoded->role ?? null);
$user->setMcpPermissions($decoded->permissions ?? []);

Auth::setUser($user);
```

#### 2. User Model Methods

The User model has helper methods for permission checks:

```php
// Set from JWT
$user->setMcpPermissions(['bookmarks:read', 'bookmarks:write']);
$user->setMcpRole('subscriber');

// Check permissions
$user->hasMcpPermission('bookmarks:delete');  // false
$user->hasAnyMcpPermission(['bookmarks:read', 'lists:read']);  // true
$user->getMcpRole();  // 'subscriber'
```

#### 3. RbacTool Base Class

Tools extend `RbacTool` and declare their required permission:

```php
<?php

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server\Tool;

abstract class RbacTool extends Tool
{
    /**
     * The permission required to use this tool.
     * Set to null for tools available to all authenticated users.
     */
    protected ?string $requiredPermission = null;

    public function shouldRegister(): bool
    {
        if ($this->requiredPermission === null) {
            return true;
        }

        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return $user->hasMcpPermission($this->requiredPermission);
    }
}
```

#### 4. Tool Examples

```php
// Read-only tool - available to free tier
class ListAllListsTool extends RbacTool
{
    protected ?string $requiredPermission = 'lists:read';
}

// Write tool - requires subscriber
class CreateBookmarkTool extends RbacTool
{
    protected ?string $requiredPermission = 'bookmarks:write';
}

// Destructive tool - requires explicit delete permission
class DeleteBookmarkTool extends RbacTool
{
    protected ?string $requiredPermission = 'bookmarks:delete';
}
```

### Permission Matrix

| Tool | Permission | Free (member) | Paid (subscriber) |
|------|------------|---------------|-------------------|
| list-all-lists | `lists:read` | ✓ | ✓ |
| get-list | `lists:read` | ✓ | ✓ |
| get-bookmark | `bookmarks:read` | ✓ | ✓ |
| search-bookmarks | `bookmarks:read` | ✓ | ✓ |
| list-tags | `tags:read` | ✓ | ✓ |
| create-list | `lists:write` | ✗ | ✓ |
| update-list | `lists:write` | ✗ | ✓ |
| create-bookmark | `bookmarks:write` | ✗ | ✓ |
| update-bookmark | `bookmarks:write` | ✗ | ✓ |
| move-bookmark | `bookmarks:write` | ✗ | ✓ |
| sync-bookmark-tags | `tags:write` | ✗ | ✓ |
| delete-list | `lists:delete` | ✗ | ✓ |
| delete-bookmark | `bookmarks:delete` | ✗ | ✓ |
| cleanup-tags | `tags:write` | ✗ | ✓ |

### Upgrading a User

When a user subscribes, update their role via the WorkOS API:

```php
use Illuminate\Support\Facades\Http;

public function upgradeToSubscriber(User $user, string $membershipId): void
{
    Http::withToken(config('services.workos.secret'))
        ->put("https://api.workos.com/user_management/organization_memberships/{$membershipId}", [
            'role_slug' => 'subscriber',
        ]);
}
```

The next time the user authenticates, their JWT will include the new role and permissions.

### Trade-offs vs FGA

| Aspect | Roles & Permissions | FGA |
|--------|---------------------|-----|
| Setup | Dashboard configuration | Schema + API calls |
| Runtime API calls | None (in JWT) | 1 per check (cached) |
| Granularity | Per-role | Per-user per-resource |
| Real-time updates | Next auth only | Immediate |
| Cost | Included with AuthKit | Additional FGA pricing |

**Use Roles & Permissions (this approach) when:**
- Permissions are role-based (same for all users with that role)
- You can wait until next auth for permission changes
- You want simpler setup and no runtime API calls

**Use FGA when:**
- You need per-user per-resource permissions
- Permission changes must take effect immediately
- You need complex relationships (user → team → org → resource)
