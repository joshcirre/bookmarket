<?php

namespace App\Mcp\Tools\Bookmarks;

use App\Models\Bookmark;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use App\Mcp\Tools\RbacTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteBookmarkTool extends RbacTool
{
    /**
     * The tool's description.
     */
    protected string $description = 'Delete a bookmark. This action cannot be undone.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'bookmark_id' => 'required|integer',
        ], [
            'bookmark_id.required' => 'You must provide a bookmark_id to delete.',
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        /** @var Bookmark|null $bookmark */
        $bookmark = Bookmark::query()->where('user_id', $user->id)
            ->where('id', $validated['bookmark_id'])
            ->first();

        if (! $bookmark) {
            return Response::error('Bookmark not found. Make sure the bookmark_id belongs to your account.');
        }

        $title = $bookmark->title;
        $url = $bookmark->url;
        $bookmarkId = $bookmark->id;

        $bookmark->delete();

        return Response::structured([
            'message' => sprintf('Bookmark "%s" has been deleted.', $title),
            'deleted' => [
                'bookmark_id' => $bookmarkId,
                'title' => $title,
                'url' => $url,
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
                ->description('The ID of the bookmark to delete.')
                ->required(),
        ];
    }
}
