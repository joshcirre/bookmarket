<?php

use App\Mcp\Servers\BookmarketServer;
use Laravel\Mcp\Facades\Mcp;

// Bookmarket MCP server with WorkOS JWT authentication
// No Mcp::oauthRoutes() needed - WorkOS AuthKit is our OAuth server
Mcp::web('/mcp/bookmarket', BookmarketServer::class)
    ->middleware('workos.jwt');
