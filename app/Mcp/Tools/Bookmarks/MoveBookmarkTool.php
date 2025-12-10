<?php

namespace App\Mcp\Tools\Bookmarks;

use App\Models\Bookmark;
use App\Models\BookmarkList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class MoveBookmarkTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = 'Move a bookmark from one list to another.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'bookmark_id' => 'required|integer',
            'target_list_id' => 'required|integer',
        ], [
            'bookmark_id.required' => 'You must provide a bookmark_id to move.',
            'target_list_id.required' => 'You must provide a target_list_id for the destination list.',
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        /** @var Bookmark|null $bookmark */
        $bookmark = Bookmark::with('bookmarkList')
            ->where('user_id', $user->id)
            ->where('id', $validated['bookmark_id'])
            ->first();

        if (! $bookmark) {
            return Response::error('Bookmark not found. Make sure the bookmark_id belongs to your account.');
        }

        /** @var BookmarkList|null $targetList */
        $targetList = BookmarkList::query()->where('user_id', $user->id)
            ->where('id', $validated['target_list_id'])
            ->first();

        if (! $targetList) {
            return Response::error('Target list not found. Make sure the target_list_id belongs to your account.');
        }

        if ($bookmark->bookmark_list_id === $targetList->id) {
            return Response::error('The bookmark is already in this list.');
        }

        $sourceList = $bookmark->bookmarkList;
        $sourceListTitle = $sourceList->title;

        // Update bookmark counts
        $sourceList->decrement('bookmarks_count');
        $targetList->increment('bookmarks_count');

        // Calculate new position in target list
        $maxPosition = Bookmark::query()->where('bookmark_list_id', $targetList->id)->max('position') ?? 0;

        $bookmark->update([
            'bookmark_list_id' => $targetList->id,
            'position' => $maxPosition + 1,
        ]);

        return Response::structured([
            'message' => sprintf('Bookmark moved from "%s" to "%s".', $sourceListTitle, $targetList->title),
            'bookmark' => [
                'id' => $bookmark->id,
                'title' => $bookmark->title,
                'from_list' => [
                    'id' => $sourceList->id,
                    'title' => $sourceListTitle,
                ],
                'to_list' => [
                    'id' => $targetList->id,
                    'title' => $targetList->title,
                ],
                'new_position' => $bookmark->position,
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
                ->description('The ID of the bookmark to move.')
                ->required(),

            'target_list_id' => $schema->integer()
                ->description('The ID of the list to move the bookmark to.')
                ->required(),
        ];
    }
}
