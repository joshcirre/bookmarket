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

## Advanced: Tool-Level Access Control with WorkOS FGA

Beyond authentication (who can access your MCP server), you may need **authorization** (what tools specific users can use). WorkOS Fine-Grained Authorization (FGA) enables this.

### Why Tool-Level RBAC?

Consider these scenarios:

- **Freemium SaaS**: Free users get `search_bookmarks` and `list_tags`, paid users get all tools
- **Team Permissions**: Admins can `delete_bookmark`, regular members can only `create_bookmark`
- **Compliance**: Audit-sensitive tools restricted to specific roles
- **Beta Features**: New tools rolled out gradually to specific users

### How It Works

WorkOS FGA uses a relationship-based model. You define:

1. **Resource types** (e.g., `mcp_tool`)
2. **Relations** (e.g., `can_execute`)
3. **Warrants** - the actual permission grants (e.g., "user X can execute tool Y")

Then, before each tool executes, check if the user has permission.

### WorkOS FGA Schema

Define your authorization model in WorkOS Dashboard → FGA → Schema:

```
type user

type mcp_tool
  relation can_execute [user]
```

### Granting Permissions

Use the WorkOS API to create warrants (permissions):

```bash
# Grant user permission to execute create_bookmark tool
curl "https://api.workos.com/fga/v1/warrants" \
  -X POST \
  -H "Authorization: Bearer sk_your_api_key" \
  -H "Content-Type: application/json" \
  -d '[{
    "op": "create",
    "resource_type": "mcp_tool",
    "resource_id": "create-bookmark-tool",
    "relation": "can_execute",
    "subject": {
      "resource_type": "user",
      "resource_id": "user_01ABC123"
    }
  }]'
```

### Checking Permissions

Before a tool executes, query WorkOS FGA:

```bash
curl "https://api.workos.com/fga/v1/check" \
  -X POST \
  -H "Authorization: Bearer sk_your_api_key" \
  -H "Content-Type: application/json" \
  -d '{
    "checks": [{
      "resource_type": "mcp_tool",
      "resource_id": "create-bookmark-tool",
      "relation": "can_execute",
      "subject": {
        "resource_type": "user",
        "resource_id": "user_01ABC123"
      }
    }]
  }'
```

Response:
```json
{
  "result": "authorized"
}
```

### Laravel Implementation

Laravel MCP tools have a `shouldRegister()` method that controls whether a tool appears in the tool list. We leverage this for RBAC:

#### 1. Create a WorkOS FGA Service

`app/Services/WorkOsFga.php`:

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WorkOsFga
{
    /**
     * Check if a user can execute a specific MCP tool.
     */
    public function canExecuteTool(string $userId, string $toolName): bool
    {
        // Cache permission checks for 60 seconds to reduce API calls
        $cacheKey = "fga:tool:{$toolName}:user:{$userId}";

        return Cache::remember($cacheKey, 60, function () use ($userId, $toolName) {
            $response = Http::withToken(config('services.workos.api_key'))
                ->post('https://api.workos.com/fga/v1/check', [
                    'checks' => [[
                        'resource_type' => 'mcp_tool',
                        'resource_id' => $toolName,
                        'relation' => 'can_execute',
                        'subject' => [
                            'resource_type' => 'user',
                            'resource_id' => $userId,
                        ],
                    ]],
                ]);

            if ($response->failed()) {
                // Log error and fail open or closed based on your security needs
                report(new \RuntimeException('FGA check failed: ' . $response->body()));
                return false; // Fail closed - deny if FGA is unavailable
            }

            return $response->json('result') === 'authorized';
        });
    }

    /**
     * Batch check multiple tools at once (more efficient).
     *
     * @param  array<string>  $toolNames
     * @return array<string, bool>
     */
    public function canExecuteTools(string $userId, array $toolNames): array
    {
        $checks = array_map(fn ($toolName) => [
            'resource_type' => 'mcp_tool',
            'resource_id' => $toolName,
            'relation' => 'can_execute',
            'subject' => [
                'resource_type' => 'user',
                'resource_id' => $userId,
            ],
        ], $toolNames);

        $response = Http::withToken(config('services.workos.api_key'))
            ->post('https://api.workos.com/fga/v1/check', [
                'checks' => $checks,
            ]);

        if ($response->failed()) {
            return array_fill_keys($toolNames, false);
        }

        $results = [];
        foreach ($response->json('results', []) as $index => $result) {
            $results[$toolNames[$index]] = $result['result'] === 'authorized';
        }

        return $results;
    }

    /**
     * Grant a user permission to execute a tool.
     */
    public function grantToolAccess(string $userId, string $toolName): bool
    {
        $response = Http::withToken(config('services.workos.api_key'))
            ->post('https://api.workos.com/fga/v1/warrants', [[
                'op' => 'create',
                'resource_type' => 'mcp_tool',
                'resource_id' => $toolName,
                'relation' => 'can_execute',
                'subject' => [
                    'resource_type' => 'user',
                    'resource_id' => $userId,
                ],
            ]]);

        if ($response->successful()) {
            Cache::forget("fga:tool:{$toolName}:user:{$userId}");
        }

        return $response->successful();
    }

    /**
     * Revoke a user's permission to execute a tool.
     */
    public function revokeToolAccess(string $userId, string $toolName): bool
    {
        $response = Http::withToken(config('services.workos.api_key'))
            ->post('https://api.workos.com/fga/v1/warrants', [[
                'op' => 'delete',
                'resource_type' => 'mcp_tool',
                'resource_id' => $toolName,
                'relation' => 'can_execute',
                'subject' => [
                    'resource_type' => 'user',
                    'resource_id' => $userId,
                ],
            ]]);

        if ($response->successful()) {
            Cache::forget("fga:tool:{$toolName}:user:{$userId}");
        }

        return $response->successful();
    }
}
```

#### 2. Add `shouldRegister()` to Your Tools

Each tool can implement `shouldRegister()` to check FGA permissions:

```php
<?php

namespace App\Mcp\Tools\Bookmarks;

use App\Services\WorkOsFga;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server\Tool;

class CreateBookmarkTool extends Tool
{
    protected string $description = 'Create a new bookmark';

    public function shouldRegister(WorkOsFga $fga): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        return $fga->canExecuteTool($user->workos_id, $this->name());
    }

    public function handle(Request $request): Response
    {
        // Tool implementation...
    }
}
```

#### 3. Create a Base Tool Class (Optional)

For consistency, create a base class that all RBAC-enabled tools extend:

```php
<?php

namespace App\Mcp\Tools;

use App\Services\WorkOsFga;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server\Tool;

abstract class RbacTool extends Tool
{
    /**
     * Whether this tool requires FGA permission checks.
     * Override to false for tools that should always be available.
     */
    protected bool $requiresFgaCheck = true;

    public function shouldRegister(WorkOsFga $fga): bool
    {
        if (! $this->requiresFgaCheck) {
            return true;
        }

        $user = Auth::user();

        if (! $user || ! $user->workos_id) {
            return false;
        }

        return $fga->canExecuteTool($user->workos_id, $this->name());
    }
}
```

Then your tools simply extend `RbacTool`:

```php
class CreateBookmarkTool extends RbacTool
{
    // FGA check happens automatically via shouldRegister()
}

class ListTagsTool extends RbacTool
{
    // This tool is always available
    protected bool $requiresFgaCheck = false;
}
```

### Configuration

Add your WorkOS API key to `config/services.php`:

```php
'workos' => [
    'client_id' => env('WORKOS_CLIENT_ID'),
    'api_key' => env('WORKOS_API_KEY'),
    'authkit_domain' => env('WORKOS_AUTHKIT_DOMAIN'),
],
```

And `.env`:

```
WORKOS_API_KEY=sk_live_your_api_key
```

### Setting Up Permissions

You can manage permissions via:

1. **WorkOS Dashboard**: FGA → Warrants → Create
2. **API calls**: During user signup or plan upgrade
3. **Admin panel**: Build a UI that calls the FGA service

Example: Grant all tools to a new paid user:

```php
public function upgradeToPaid(User $user): void
{
    $fga = app(WorkOsFga::class);
    
    $tools = [
        'create-bookmark-tool',
        'delete-bookmark-tool',
        'create-list-tool',
        // ... etc
    ];

    foreach ($tools as $tool) {
        $fga->grantToolAccess($user->workos_id, $tool);
    }
}
```

### Trade-offs

| Aspect | Without FGA | With FGA |
|--------|-------------|----------|
| Setup complexity | Simpler | More complex |
| API calls | None | 1 per tool (cached) |
| Granularity | All or nothing | Per-tool control |
| Cost | Free | WorkOS FGA pricing |
| User experience | Same tools for everyone | Personalized tool list |

### When to Use FGA

**Use FGA if:**
- Different users should have access to different tools
- You have a freemium/tiered pricing model
- Compliance requires audit trails on who can do what
- You want to gradually roll out new tools

**Skip FGA if:**
- All authenticated users should have the same access
- You're prototyping and don't need granular permissions yet
- Cost is a concern (FGA has usage-based pricing)

### Alternative: Simple Role-Based Approach

If WorkOS FGA is overkill, you can implement simple RBAC using a `role` column on your users table:

```php
public function shouldRegister(): bool
{
    $user = Auth::user();
    
    return $user && in_array($user->role, ['admin', 'paid']);
}
```

This is simpler but less flexible than FGA's relationship-based model.
