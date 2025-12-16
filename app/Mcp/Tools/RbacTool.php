<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server\Tool;

/**
 * Base class for MCP tools that require RBAC permission checks.
 *
 * Tools extending this class use WorkOS AuthKit's built-in Roles and Permissions.
 * Permissions are extracted from the JWT token at authentication time (no API calls).
 *
 * Each tool specifies required permission(s) via the $requiredPermission property.
 * If no permission is specified, the tool is available to all authenticated users.
 */
abstract class RbacTool extends Tool
{
    /**
     * The permission required to use this tool.
     *
     * Uses colon-separated format: 'resource:action' (e.g., 'bookmarks:delete').
     * Set to null for tools available to all authenticated users.
     */
    protected ?string $requiredPermission = null;

    /**
     * Determine if this tool should be registered (visible) for the current user.
     *
     * This method is called by Laravel MCP when building the tool list.
     * If it returns false, the tool won't appear in tools/list responses.
     */
    public function shouldRegister(): bool
    {
        // If no permission required, always register for authenticated users
        if ($this->requiredPermission === null) {
            return true;
        }

        /** @var \App\Models\User|null $user */
        $user = Auth::user();

        // No authenticated user - don't register the tool
        if (! $user) {
            return false;
        }

        // Check if user has the required permission from their JWT
        return $user->hasMcpPermission($this->requiredPermission);
    }
}
