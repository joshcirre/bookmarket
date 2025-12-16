<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
            // These are only present when user has an organization membership
            $role = $decoded->role ?? null;
            $permissions = $decoded->permissions ?? [];

            Log::debug('MCP JWT claims', [
                'user_id' => $user->id,
                'workos_id' => $decoded->sub,
                'role' => $role,
                'permissions' => $permissions,
                'org_id' => $decoded->org_id ?? null,
            ]);

            if (empty($permissions)) {
                Log::warning('MCP JWT has no permissions - tools will be hidden', [
                    'user_id' => $user->id,
                    'hint' => 'Create permissions and roles in WorkOS Dashboard, then re-authenticate',
                ]);
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
