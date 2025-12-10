<?php

namespace App\Mcp\Tools\Bookmarks;

use App\Models\Bookmark;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetBookmarkTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = 'Get details of a specific bookmark.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'bookmark_id' => 'required|integer',
        ], [
            'bookmark_id.required' => 'You must provide a bookmark_id.',
        ]);

        $user = $request->user();
        $bookmark = Bookmark::with('tags', 'bookmarkList')
            ->where('user_id', $user->id)
            ->find($validated['bookmark_id']);

        if (! $bookmark) {
            return Response::error('Bookmark not found. Make sure the bookmark_id belongs to your account.');
        }

        return Response::structured([
            'bookmark' => [
                'id' => $bookmark->id,
                'list_id' => $bookmark->bookmark_list_id,
                'list_title' => $bookmark->bookmarkList->title,
                'url' => $bookmark->url,
                'title' => $bookmark->title,
                'description' => $bookmark->description,
                'notes' => $bookmark->notes,
                'domain' => $bookmark->domain,
                'favicon_url' => $bookmark->favicon_url,
                'position' => $bookmark->position,
                'tags' => $bookmark->tags->pluck('name')->toArray(),
                'created_at' => $bookmark->created_at->toIso8601String(),
                'updated_at' => $bookmark->updated_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'bookmark_id' => $schema->integer()
                ->description('The ID of the bookmark to retrieve.')
                ->required(),
        ];
    }
}
