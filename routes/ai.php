<?php

use App\Mcp\Servers\BookmarketServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Server Routes
|--------------------------------------------------------------------------
|
| This application demonstrates TWO approaches to MCP authentication:
|
| 1. WorkOS AuthKit (/mcp) - Custom JWT validation via WorkOS
| 2. Laravel Passport (/mcp/passport) - Standard Laravel MCP approach
|
| Both serve the same tools - compare the setup and behavior.
|
*/

// ============================================================================
// Option 1: WorkOS AuthKit (Custom Approach)
// ============================================================================
// Uses WorkOS as the OAuth authorization server directly.
// No Mcp::oauthRoutes() needed - WorkOS handles OAuth discovery.
// JWTs validated via WorkOS JWKS endpoint.
//
// Pros: No extra DB tables, single auth system
// Cons: Custom middleware required
// ============================================================================
Mcp::web('/mcp', BookmarketServer::class)
    ->middleware('workos.jwt');

// ============================================================================
// Option 2: Laravel Passport (Standard Approach)
// ============================================================================
// Uses Laravel Passport for OAuth token issuance.
// Mcp::oauthRoutes() registers OAuth discovery and client registration.
// User still authenticates via WorkOS (GitHub), Passport issues the token.
//
// Pros: First-class Laravel support, token visibility in DB
// Cons: 5+ extra DB tables, two auth systems to maintain
// ============================================================================
Mcp::oauthRoutes('/mcp/passport');

Mcp::web('/mcp/passport', BookmarketServer::class)
    ->middleware('auth:api');
