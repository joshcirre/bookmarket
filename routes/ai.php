<?php

use App\Mcp\Servers\BookmarketServer;
use Laravel\Mcp\Facades\Mcp;

// Bookmarket MCP server with WorkOS JWT authentication
Mcp::web('/mcp', BookmarketServer::class)
    ->middleware('workos.jwt');
