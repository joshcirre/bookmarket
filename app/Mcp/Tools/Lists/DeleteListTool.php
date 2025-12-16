<?php

namespace App\Mcp\Tools\Lists;

use App\Models\BookmarkList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use App\Mcp\Tools\RbacTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class DeleteListTool extends RbacTool
{
    /**
     * The tool's description.
     */
    protected string $description = 'Delete a bookmark list and all its bookmarks. This action cannot be undone.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'list_id' => 'required|integer',
            'confirm' => 'required|boolean|accepted',
        ], [
            'list_id.required' => 'You must provide a list_id to delete.',
            'confirm.required' => 'You must confirm the deletion by setting confirm to true.',
            'confirm.accepted' => 'You must confirm the deletion by setting confirm to true.',
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        /** @var BookmarkList|null $list */
        $list = BookmarkList::query()->where('user_id', $user->id)
            ->where('id', $validated['list_id'])
            ->first();

        if (! $list) {
            return Response::error('List not found. Make sure the list_id belongs to your account.');
        }

        $title = $list->title;
        $bookmarksCount = $list->bookmarks_count;

        // Delete all bookmarks in the list first (triggers model events)
        $list->bookmarks()->each(fn ($bookmark) => $bookmark->delete());

        // Delete the list
        $list->delete();

        return Response::structured([
            'message' => sprintf('List "%s" and its %s bookmark(s) have been deleted.', $title, $bookmarksCount),
            'deleted' => [
                'list_id' => $validated['list_id'],
                'title' => $title,
                'bookmarks_deleted' => $bookmarksCount,
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
            'list_id' => $schema->integer()
                ->description('The ID of the list to delete.')
                ->required(),

            'confirm' => $schema->boolean()
                ->description('Must be true to confirm deletion. This prevents accidental deletions.')
                ->required(),
        ];
    }
}
