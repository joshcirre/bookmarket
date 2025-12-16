<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Log;
use Laravel\WorkOS\WorkOS;
use WorkOS\Exception\WorkOSException;
use WorkOS\UserManagement;

/**
 * Add newly registered users to the default WorkOS organization.
 *
 * This is required for WorkOS AuthKit RBAC - users must belong to an
 * organization to have roles and permissions applied via MCP.
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
            // Use WorkOS SDK to create organization membership
            WorkOS::configure();
            $userManagement = new UserManagement;

            $membership = $userManagement->createOrganizationMembership(
                userId: $user->workos_id,
                organizationId: $organizationId,
                roleSlug: 'free-tier'
            );

            Log::info('Added user to default organization', [
                'user_id' => $user->id,
                'workos_id' => $user->workos_id,
                'organization_id' => $organizationId,
                'membership_id' => $membership->id,
            ]);
        } catch (WorkOSException $e) {
            // Check if user is already a member (not an error)
            if (str_contains($e->getMessage(), 'already a member')) {
                Log::info('User already a member of default organization', [
                    'user_id' => $user->id,
                    'workos_id' => $user->workos_id,
                ]);

                return;
            }

            Log::error('Failed to add user to default organization', [
                'user_id' => $user->id,
                'workos_id' => $user->workos_id,
                'error' => $e->getMessage(),
            ]);
        } catch (\Exception $e) {
            Log::error('Exception adding user to default organization', [
                'user_id' => $user->id,
                'workos_id' => $user->workos_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
