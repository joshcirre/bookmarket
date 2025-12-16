<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class UpgradeUserRole extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:role
        {email : The email address of the user to update}
        {--role=subscriber : The role slug to assign (e.g., subscriber, member)}
        {--list : List membership info instead of updating}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update a user\'s WorkOS organization role (e.g., upgrade to subscriber)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email');
        $roleSlug = $this->option('role');
        $listOnly = $this->option('list');

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("User not found: {$email}");

            return self::FAILURE;
        }

        if (! $user->workos_id) {
            $this->error('User has no WorkOS ID. They may not have logged in via WorkOS yet.');

            return self::FAILURE;
        }

        $organizationId = config('services.workos.default_organization_id');

        if (! $organizationId) {
            $this->error('WORKOS_DEFAULT_ORGANIZATION_ID is not configured.');

            return self::FAILURE;
        }

        $this->info("User: {$user->name} ({$user->email})");
        $this->info("WorkOS ID: {$user->workos_id}");

        // Find the user's organization membership
        $membership = $this->findMembership($user->workos_id, $organizationId);

        if (! $membership) {
            $this->error('User is not a member of the default organization.');
            $this->info('Run the AddUserToDefaultOrganization listener or manually add them.');

            return self::FAILURE;
        }

        $this->info("Current role: {$membership['role']['slug']}");

        if ($listOnly) {
            $this->table(['Field', 'Value'], [
                ['Membership ID', $membership['id']],
                ['Organization ID', $membership['organization_id']],
                ['Role Name', $membership['role']['name'] ?? 'N/A'],
                ['Role Slug', $membership['role']['slug']],
                ['Created At', $membership['created_at']],
            ]);

            return self::SUCCESS;
        }

        if ($membership['role']['slug'] === $roleSlug) {
            $this->info("User already has the '{$roleSlug}' role. No changes needed.");

            return self::SUCCESS;
        }

        // Update the role
        if (! $this->confirm("Update role from '{$membership['role']['slug']}' to '{$roleSlug}'?")) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $updated = $this->updateMembershipRole($membership['id'], $roleSlug);

        if ($updated) {
            $this->info("Successfully updated {$user->email} to '{$roleSlug}' role.");
            $this->warn('Note: The user will need to re-authenticate to get a new JWT with updated permissions.');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    /**
     * Find a user's organization membership.
     *
     * @return array<string, mixed>|null
     */
    private function findMembership(string $workosUserId, string $organizationId): ?array
    {
        $response = Http::withToken(config('services.workos.secret'))
            ->get('https://api.workos.com/user_management/organization_memberships', [
                'user_id' => $workosUserId,
                'organization_id' => $organizationId,
            ]);

        if (! $response->successful()) {
            $this->error('Failed to fetch memberships: '.$response->body());

            return null;
        }

        $data = $response->json('data', []);

        return $data[0] ?? null;
    }

    /**
     * Update a membership's role.
     */
    private function updateMembershipRole(string $membershipId, string $roleSlug): bool
    {
        $response = Http::withToken(config('services.workos.secret'))
            ->put("https://api.workos.com/user_management/organization_memberships/{$membershipId}", [
                'role_slug' => $roleSlug,
            ]);

        if (! $response->successful()) {
            $this->error('Failed to update role: '.$response->body());

            return false;
        }

        return true;
    }
}
