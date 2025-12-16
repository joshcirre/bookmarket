<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WorkOS Fine-Grained Authorization (FGA) service for MCP tool access control.
 *
 * This service provides methods to check, grant, and revoke permissions
 * for users to execute specific MCP tools using WorkOS FGA.
 */
class WorkOsFga
{
    private const FGA_BASE_URL = 'https://api.workos.com/fga/v1';

    private const CACHE_TTL = 60; // seconds

    /**
     * Check if a user can execute a specific MCP tool.
     */
    public function canExecuteTool(string $userId, string $toolName): bool
    {
        if (! $this->isConfigured()) {
            // If FGA is not configured, allow all tools (fail open)
            return true;
        }

        $cacheKey = $this->getCacheKey($toolName, $userId);

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId, $toolName): bool {
            try {
                $response = Http::withToken($this->getApiKey())
                    ->timeout(5)
                    ->post(self::FGA_BASE_URL.'/check', [
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
                    Log::warning('WorkOS FGA check failed', [
                        'status' => $response->status(),
                        'body' => $response->body(),
                        'user_id' => $userId,
                        'tool' => $toolName,
                    ]);

                    // Fail open - if FGA is unavailable, allow access
                    // Change to `return false` if you want to fail closed
                    return true;
                }

                /** @var array{result?: string} $data */
                $data = $response->json();

                return ($data['result'] ?? '') === 'authorized';
            } catch (ConnectionException $e) {
                Log::warning('WorkOS FGA connection failed', [
                    'error' => $e->getMessage(),
                    'user_id' => $userId,
                    'tool' => $toolName,
                ]);

                return true; // Fail open
            }
        });
    }

    /**
     * Batch check multiple tools at once (more efficient for listing available tools).
     *
     * @param  array<string>  $toolNames
     * @return array<string, bool>
     */
    public function canExecuteTools(string $userId, array $toolNames): array
    {
        if (! $this->isConfigured()) {
            return array_fill_keys($toolNames, true);
        }

        if ($toolNames === []) {
            return [];
        }

        // Check cache first for each tool
        $results = [];
        $uncachedTools = [];

        foreach ($toolNames as $toolName) {
            $cacheKey = $this->getCacheKey($toolName, $userId);
            $cached = Cache::get($cacheKey);

            if ($cached !== null) {
                $results[$toolName] = $cached;
            } else {
                $uncachedTools[] = $toolName;
            }
        }

        // If all were cached, return early
        if ($uncachedTools === []) {
            return $results;
        }

        // Batch check uncached tools
        $checks = array_map(fn (string $toolName): array => [
            'resource_type' => 'mcp_tool',
            'resource_id' => $toolName,
            'relation' => 'can_execute',
            'subject' => [
                'resource_type' => 'user',
                'resource_id' => $userId,
            ],
        ], $uncachedTools);

        try {
            $response = Http::withToken($this->getApiKey())
                ->timeout(5)
                ->post(self::FGA_BASE_URL.'/check', [
                    'checks' => $checks,
                ]);

            if ($response->failed()) {
                Log::warning('WorkOS FGA batch check failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                // Fail open for all uncached tools
                foreach ($uncachedTools as $toolName) {
                    $results[$toolName] = true;
                }

                return $results;
            }

            /** @var array{results?: array<array{result?: string}>} $data */
            $data = $response->json();
            $checkResults = $data['results'] ?? [];

            foreach ($uncachedTools as $index => $toolName) {
                $isAuthorized = ($checkResults[$index]['result'] ?? '') === 'authorized';
                $results[$toolName] = $isAuthorized;

                // Cache the result
                Cache::put(
                    $this->getCacheKey($toolName, $userId),
                    $isAuthorized,
                    self::CACHE_TTL
                );
            }
        } catch (ConnectionException $e) {
            Log::warning('WorkOS FGA batch check connection failed', [
                'error' => $e->getMessage(),
            ]);

            // Fail open for all uncached tools
            foreach ($uncachedTools as $toolName) {
                $results[$toolName] = true;
            }
        }

        return $results;
    }

    /**
     * Grant a user permission to execute a tool.
     */
    public function grantToolAccess(string $userId, string $toolName): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::withToken($this->getApiKey())
                ->timeout(5)
                ->post(self::FGA_BASE_URL.'/warrants', [[
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
                $this->clearCache($toolName, $userId);

                return true;
            }

            Log::warning('WorkOS FGA grant failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'user_id' => $userId,
                'tool' => $toolName,
            ]);

            return false;
        } catch (ConnectionException $e) {
            Log::warning('WorkOS FGA grant connection failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Grant a user permission to execute multiple tools.
     *
     * @param  array<string>  $toolNames
     */
    public function grantToolsAccess(string $userId, array $toolNames): bool
    {
        if (! $this->isConfigured() || $toolNames === []) {
            return false;
        }

        $warrants = array_map(fn (string $toolName): array => [
            'op' => 'create',
            'resource_type' => 'mcp_tool',
            'resource_id' => $toolName,
            'relation' => 'can_execute',
            'subject' => [
                'resource_type' => 'user',
                'resource_id' => $userId,
            ],
        ], $toolNames);

        try {
            $response = Http::withToken($this->getApiKey())
                ->timeout(5)
                ->post(self::FGA_BASE_URL.'/warrants', $warrants);

            if ($response->successful()) {
                foreach ($toolNames as $toolName) {
                    $this->clearCache($toolName, $userId);
                }

                return true;
            }

            Log::warning('WorkOS FGA batch grant failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return false;
        } catch (ConnectionException $e) {
            Log::warning('WorkOS FGA batch grant connection failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Revoke a user's permission to execute a tool.
     */
    public function revokeToolAccess(string $userId, string $toolName): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::withToken($this->getApiKey())
                ->timeout(5)
                ->post(self::FGA_BASE_URL.'/warrants', [[
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
                $this->clearCache($toolName, $userId);

                return true;
            }

            Log::warning('WorkOS FGA revoke failed', [
                'status' => $response->status(),
                'body' => $response->body(),
                'user_id' => $userId,
                'tool' => $toolName,
            ]);

            return false;
        } catch (ConnectionException $e) {
            Log::warning('WorkOS FGA revoke connection failed', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if WorkOS FGA is configured.
     */
    public function isConfigured(): bool
    {
        $apiKey = config('services.workos.fga_api_key');

        return is_string($apiKey) && $apiKey !== '';
    }

    /**
     * Get the cache key for a tool/user permission check.
     */
    private function getCacheKey(string $toolName, string $userId): string
    {
        return "workos_fga:tool:{$toolName}:user:{$userId}";
    }

    /**
     * Clear the cache for a tool/user permission.
     */
    private function clearCache(string $toolName, string $userId): void
    {
        Cache::forget($this->getCacheKey($toolName, $userId));
    }

    /**
     * Get the WorkOS API key.
     */
    private function getApiKey(): string
    {
        /** @var string */
        return config('services.workos.fga_api_key', '');
    }
}
