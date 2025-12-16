<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\WorkOsFga;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server\Tool;

/**
 * Base class for MCP tools that require RBAC permission checks.
 *
 * Tools extending this class will automatically check WorkOS FGA
 * to determine if the authenticated user can execute the tool.
 *
 * To make a tool always available regardless of FGA, set:
 * protected bool $requiresFgaCheck = false;
 */
abstract class RbacTool extends Tool
{
    /**
     * Whether this tool requires FGA permission checks.
     *
     * Set to false for tools that should always be available
     * to authenticated users (e.g., read-only or basic tools).
     */
    protected bool $requiresFgaCheck = true;

    /**
     * Determine if this tool should be registered (visible) for the current user.
     *
     * This method is called by Laravel MCP when building the tool list.
     * If it returns false, the tool won't appear in tools/list responses.
     */
    public function shouldRegister(WorkOsFga $fga): bool
    {
        // If FGA check is disabled for this tool, always register it
        if (! $this->requiresFgaCheck) {
            return true;
        }

        // If FGA is not configured, allow all tools
        if (! $fga->isConfigured()) {
            return true;
        }

        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        // No authenticated user - don't register the tool
        if (! $user) {
            return false;
        }

        // User doesn't have a WorkOS ID - can't check FGA
        if (! $user->workos_id) {
            return false;
        }

        // Check FGA for permission
        return $fga->canExecuteTool($user->workos_id, $this->name());
    }
}
