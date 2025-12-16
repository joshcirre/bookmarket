<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Add newly registered users to the default WorkOS organization.
 *
 * This is required for WorkOS AuthKit RBAC - users must belong to an
 * organization to have roles and permissions included in their JWT.
 *
 * Note: This runs synchronously during registration to ensure the user
 * is added to the org before their first authenticated request.
 */
class AddUserToDefaultOrganization
{
    /**
     * Handle the event.
     */
    public function handle(Registered $event): void
    {
        /** @var User $user */
        $user = $event->user;

        $organizationId = config('services.workos.default_organization_id');

        if (! $organizationId) {
            Log::warning('WORKOS_DEFAULT_ORGANIZATION_ID not configured, skipping org membership', [
                'user_id' => $user->id,
                'workos_id' => $user->workos_id,
            ]);

            return;
        }

        if (! $user->workos_id) {
            Log::warning('User has no workos_id, skipping org membership', [
                'user_id' => $user->id,
            ]);

            return;
        }

        try {
            $response = Http::withToken(config('services.workos.secret'))
                ->post('https://api.workos.com/user_management/organization_memberships', [
                    'organization_id' => $organizationId,
                    'user_id' => $user->workos_id,
                    'role_slug' => 'free-tier',
                ]);

            if ($response->successful()) {
                Log::info('Added user to default organization', [
                    'user_id' => $user->id,
                    'workos_id' => $user->workos_id,
                    'organization_id' => $organizationId,
                    'membership_id' => $response->json('id'),
                ]);
            } else {
                // Check if user is already a member (not an error)
                if ($response->status() === 400 && str_contains($response->body(), 'already a member')) {
                    Log::info('User already a member of default organization', [
                        'user_id' => $user->id,
                        'workos_id' => $user->workos_id,
                    ]);

                    return;
                }

                Log::error('Failed to add user to default organization', [
                    'user_id' => $user->id,
                    'workos_id' => $user->workos_id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Exception adding user to default organization', [
                'user_id' => $user->id,
                'workos_id' => $user->workos_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
