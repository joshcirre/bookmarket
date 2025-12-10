<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Bookmarks\CreateBookmarkTool;
use App\Mcp\Tools\Bookmarks\DeleteBookmarkTool;
use App\Mcp\Tools\Bookmarks\GetBookmarkTool;
use App\Mcp\Tools\Bookmarks\MoveBookmarkTool;
use App\Mcp\Tools\Bookmarks\ReorderBookmarksTool;
use App\Mcp\Tools\Bookmarks\UpdateBookmarkTool;
use App\Mcp\Tools\Lists\CreateListTool;
use App\Mcp\Tools\Lists\DeleteListTool;
use App\Mcp\Tools\Lists\GetListTool;
use App\Mcp\Tools\Lists\ListAllListsTool;
use App\Mcp\Tools\Lists\UpdateListTool;
use App\Mcp\Tools\Search\SearchBookmarksTool;
use App\Mcp\Tools\Tags\CleanupTagsTool;
use App\Mcp\Tools\Tags\ListTagsTool;
use App\Mcp\Tools\Tags\SyncBookmarkTagsTool;
use Laravel\Mcp\Server;

class BookmarketServer extends Server
{
    /**
     * The MCP server's name.
     */
    protected string $name = 'Bookmarket';

    /**
     * The MCP server's version.
     */
    protected string $version = '1.0.0';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = <<<'MARKDOWN'
        Bookmarket is a bookmark management application. Use these tools to manage
        the authenticated user's bookmark lists and bookmarks.

        ## Available Operations

        ### Lists
        - `list_all_lists` - Get all bookmark lists for the user
        - `get_list` - Get a specific list with its bookmarks
        - `create_list` - Create a new bookmark list
        - `update_list` - Update a list's title, description, or visibility
        - `delete_list` - Delete a bookmark list

        ### Bookmarks
        - `create_bookmark` - Add a new bookmark to a list
        - `get_bookmark` - Get details of a specific bookmark
        - `update_bookmark` - Update a bookmark's details
        - `delete_bookmark` - Remove a bookmark
        - `move_bookmark` - Move a bookmark to a different list
        - `reorder_bookmarks` - Change the order of bookmarks in a list

        ### Search & Tags
        - `search_bookmarks` - Search across all bookmarks by title, URL, or description
        - `list_tags` - Get all tags used by the user (ALWAYS call this before creating bookmarks to reuse existing tags)
        - `sync_bookmark_tags` - Update the tags on a bookmark
        - `cleanup_tags` - Find and merge duplicate or similar tags

        ## Tips
        - Always check if a list exists before adding bookmarks to it
        - Use search to find bookmarks across all lists
        - Tags help organize bookmarks across different lists
        - IMPORTANT: Before creating/updating bookmarks, call list_tags to see existing tags and reuse them instead of creating duplicates
        - Use cleanup_tags periodically to find and merge duplicate tags
    MARKDOWN;

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        // Lists
        ListAllListsTool::class,
        GetListTool::class,
        CreateListTool::class,
        UpdateListTool::class,
        DeleteListTool::class,

        // Bookmarks
        CreateBookmarkTool::class,
        GetBookmarkTool::class,
        UpdateBookmarkTool::class,
        DeleteBookmarkTool::class,
        MoveBookmarkTool::class,
        ReorderBookmarksTool::class,

        // Search
        SearchBookmarksTool::class,

        // Tags
        ListTagsTool::class,
        SyncBookmarkTagsTool::class,
        CleanupTagsTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Resource>>
     */
    protected array $resources = [
        //
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Prompt>>
     */
    protected array $prompts = [
        //
    ];
}
