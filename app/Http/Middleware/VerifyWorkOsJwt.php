<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verify WorkOS AuthKit JWT tokens for MCP authentication.
 *
 * WorkOS MCP OAuth tokens include standard claims (sub, org_id, etc.) but do NOT
 * include RBAC claims (role, permissions) directly in the JWT. This is by design
 * per WorkOS MCP documentation - the supported scopes are standard OIDC scopes
 * (email, offline_access, openid, profile), not permission scopes.
 *
 * To implement RBAC for MCP tools, we:
 * 1. Extract the user (sub) and organization (org_id) from the JWT
 * 2. Fetch the user's role from WorkOS API using their organization membership
 * 3. Map the role to permissions locally
 *
 * @see https://workos.com/docs/authkit/mcp
 */
class VerifyWorkOsJwt
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            Log::warning('MCP request without bearer token', [
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            return $this->unauthorizedResponse('No token provided');
        }

        try {
            $keys = $this->getJwks();
            $decoded = JWT::decode($token, $keys);

            // Find user by WorkOS ID (sub claim)
            $user = User::query()->where('workos_id', $decoded->sub)->first();

            if (! $user) {
                Log::warning('MCP user not found', ['workos_id' => $decoded->sub]);

                return $this->unauthorizedResponse('User not found');
            }

            $orgId = $decoded->org_id ?? null;

            Log::debug('MCP JWT decoded', [
                'user_id' => $user->id,
                'workos_id' => $decoded->sub,
                'org_id' => $orgId,
                'claims' => array_keys((array) $decoded),
            ]);

            // Fetch role and permissions from WorkOS API
            // MCP OAuth tokens don't include RBAC claims, so we always fetch from API
            $role = null;
            $permissions = [];

            if ($orgId) {
                [$role, $permissions] = $this->fetchRoleFromWorkOs($decoded->sub, $orgId);
            } else {
                Log::warning('MCP token missing org_id - user has no organization context', [
                    'user_id' => $user->id,
                ]);
            }

            $user->setMcpRole($role);
            $user->setMcpPermissions($permissions);

            Log::info('MCP authentication successful', [
                'user_id' => $user->id,
                'role' => $role,
                'permissions' => $permissions,
                'permission_count' => count($permissions),
            ]);

            // Set the user on Laravel's auth system so MCP Request->user() works
            Auth::setUser($user);

            return $next($request);
        } catch (\Exception $e) {
            Log::error('MCP JWT verification failed', [
                'error' => $e->getMessage(),
                'token_preview' => substr($token, 0, 20).'...',
            ]);

            return $this->unauthorizedResponse('Invalid token');
        }
    }

    /**
     * Get the JWKS keys from WorkOS, with caching.
     *
     * @return array<string, \Firebase\JWT\Key>
     */
    protected function getJwks(): array
    {
        /** @var array{keys: array<int, array<string, mixed>>} $jwks */
        $jwks = Cache::remember('workos_jwks', 3600, function (): array {
            /** @var string $authkitDomain */
            $authkitDomain = config('services.workos.authkit_domain');
            $jwksUri = $authkitDomain.'/oauth2/jwks';

            Log::debug('Fetching JWKS from WorkOS', ['uri' => $jwksUri]);

            $response = file_get_contents($jwksUri);

            throw_if($response === false, \RuntimeException::class, 'Failed to fetch JWKS from WorkOS');

            /** @var array{keys: array<int, array<string, mixed>>} */
            return json_decode($response, true);
        });

        return JWK::parseKeySet($jwks);
    }

    /**
     * Fetch user's role and permissions from WorkOS API.
     *
     * Since MCP OAuth tokens don't include RBAC claims, we fetch the user's
     * organization membership to determine their role, then map it to permissions.
     *
     * Results are cached for 5 minutes to reduce API calls while still allowing
     * role changes to propagate relatively quickly.
     *
     * @return array{0: string|null, 1: array<string>}
     */
    protected function fetchRoleFromWorkOs(string $workosUserId, string $orgId): array
    {
        $cacheKey = "workos_role:{$workosUserId}:{$orgId}";

        return Cache::remember($cacheKey, 300, function () use ($workosUserId, $orgId): array {
            try {
                Log::debug('Fetching organization membership from WorkOS API', [
                    'workos_user_id' => $workosUserId,
                    'org_id' => $orgId,
                ]);

                $response = Http::withToken(config('services.workos.secret'))
                    ->get('https://api.workos.com/user_management/organization_memberships', [
                        'user_id' => $workosUserId,
                        'organization_id' => $orgId,
                    ]);

                if (! $response->successful()) {
                    Log::error('WorkOS API request failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return [null, []];
                }

                $memberships = $response->json('data', []);

                if (empty($memberships)) {
                    Log::warning('No organization membership found', [
                        'workos_user_id' => $workosUserId,
                        'org_id' => $orgId,
                    ]);

                    return [null, []];
                }

                $membership = $memberships[0];
                $role = $membership['role']['slug'] ?? null;

                Log::info('Found WorkOS organization membership', [
                    'membership_id' => $membership['id'] ?? null,
                    'role_slug' => $role,
                    'role_name' => $membership['role']['name'] ?? null,
                ]);

                $permissions = $this->getPermissionsForRole($role);

                return [$role, $permissions];
            } catch (\Exception $e) {
                Log::error('Exception fetching WorkOS membership', [
                    'error' => $e->getMessage(),
                    'workos_user_id' => $workosUserId,
                    'org_id' => $orgId,
                ]);

                return [null, []];
            }
        });
    }

    /**
     * Map a role slug to its permissions.
     *
     * These mappings should match the permissions configured in WorkOS Dashboard.
     * When you add or modify permissions in WorkOS, update this mapping accordingly.
     *
     * @return array<string>
     */
    protected function getPermissionsForRole(?string $role): array
    {
        return match ($role) {
            'free-tier' => [
                'bookmarks:read',
                'lists:read',
                'tags:read',
            ],
            'subscriber' => [
                'bookmarks:read',
                'bookmarks:write',
                'bookmarks:delete',
                'lists:read',
                'lists:write',
                'lists:delete',
                'tags:read',
                'tags:write',
            ],
            default => [],
        };
    }

    /**
     * Return an unauthorized response with OAuth metadata header.
     *
     * The WWW-Authenticate header includes the resource_metadata URL so MCP clients
     * can discover the authorization server and initiate the OAuth flow.
     */
    protected function unauthorizedResponse(string $error): Response
    {
        return response()->json(['error' => $error], 401)
            ->header(
                'WWW-Authenticate',
                'Bearer error="unauthorized", resource_metadata="'.url('/.well-known/oauth-protected-resource').'"'
            );
    }
}
