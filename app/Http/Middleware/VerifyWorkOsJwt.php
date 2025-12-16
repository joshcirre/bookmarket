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

class VerifyWorkOsJwt
{
    /**
     * Handle an incoming request.
     *
     * Validates JWT tokens issued by WorkOS AuthKit and attaches
     * the authenticated user to the request. Also extracts role and
     * permissions claims from the token for RBAC.
     *
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return $this->unauthorizedResponse('No token provided');
        }

        try {
            $keys = $this->getJwks();
            $decoded = JWT::decode($token, $keys);

            // Attach user to request based on WorkOS user ID (sub claim)
            $user = User::query()->where('workos_id', $decoded->sub)->first();

            if (! $user) {
                return $this->unauthorizedResponse('User not found for sub: '.$decoded->sub);
            }

            // Extract role and permissions from JWT claims (set by WorkOS AuthKit RBAC)
            // WorkOS includes these claims when user authenticates with organization context
            $role = $decoded->role ?? null;
            $permissions = $decoded->permissions ?? [];
            $orgId = $decoded->org_id ?? null;

            // OAuth Connect may use 'scope' claim instead of 'permissions'
            // Scopes are space-separated string, permissions are an array
            $scope = $decoded->scope ?? null;
            if (empty($permissions) && $scope) {
                $permissions = is_string($scope) ? explode(' ', $scope) : (array) $scope;
            }

            Log::debug('MCP JWT claims', [
                'user_id' => $user->id,
                'workos_id' => $decoded->sub,
                'role' => $role,
                'permissions' => $permissions,
                'org_id' => $orgId,
                'scope' => $scope,
                'all_claims' => array_keys((array) $decoded),
            ]);

            // OAuth Connect tokens may not include role/permissions even with org_id
            // Fetch from WorkOS API if missing
            if ($orgId && empty($permissions)) {
                Log::info('Fetching role/permissions from WorkOS API (not in JWT)');
                [$role, $permissions] = $this->fetchRoleFromWorkOs($decoded->sub, $orgId);
            }

            $user->setMcpRole($role);
            $user->setMcpPermissions($permissions);

            // Set the user on Laravel's auth system so MCP Request->user() works
            Auth::setUser($user);

            return $next($request);
        } catch (\Exception) {
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
     * OAuth Connect tokens may not include role/permissions claims,
     * so we fall back to fetching from the API.
     *
     * @return array{0: string|null, 1: array<string>}
     */
    protected function fetchRoleFromWorkOs(string $workosUserId, string $orgId): array
    {
        $cacheKey = "workos_role:{$workosUserId}:{$orgId}";

        return Cache::remember($cacheKey, 300, function () use ($workosUserId, $orgId): array {
            try {
                $response = Http::withToken(config('services.workos.secret'))
                    ->get('https://api.workos.com/user_management/organization_memberships', [
                        'user_id' => $workosUserId,
                        'organization_id' => $orgId,
                    ]);

                if (! $response->successful()) {
                    Log::warning('Failed to fetch WorkOS membership', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return [null, []];
                }

                $memberships = $response->json('data', []);
                if (empty($memberships)) {
                    return [null, []];
                }

                $role = $memberships[0]['role']['slug'] ?? null;
                Log::info('Fetched role from WorkOS API', ['role' => $role]);

                // Map role to permissions (defined in WorkOS Dashboard)
                $permissions = $this->getPermissionsForRole($role);

                return [$role, $permissions];
            } catch (\Exception $e) {
                Log::error('Exception fetching WorkOS membership', ['error' => $e->getMessage()]);

                return [null, []];
            }
        });
    }

    /**
     * Get permissions for a role.
     *
     * These mappings should match what's configured in WorkOS Dashboard.
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
