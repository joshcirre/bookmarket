<?php

namespace App\Mcp\Tools\Bookmarks;

use App\Mcp\Tools\RbacTool;
use App\Models\Bookmark;
use App\Models\BookmarkList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class ReorderBookmarksTool extends RbacTool
{
    protected ?string $requiredPermission = 'bookmarks:write';

    /**
     * The tool's description.
     */
    protected string $description = 'Reorder bookmarks within a list by providing an ordered array of bookmark IDs.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'list_id' => 'required|integer',
            'bookmark_ids' => 'required|array|min:1',
            'bookmark_ids.*' => 'required|integer',
        ], [
            'list_id.required' => 'You must provide a list_id.',
            'bookmark_ids.required' => 'You must provide an array of bookmark_ids in the desired order.',
            'bookmark_ids.array' => 'bookmark_ids must be an array of bookmark IDs.',
            'bookmark_ids.min' => 'You must provide at least one bookmark_id.',
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

        $bookmarkIds = $validated['bookmark_ids'];

        // Verify all bookmarks exist and belong to this list
        $bookmarks = Bookmark::query()->where('bookmark_list_id', $list->id)
            ->whereIn('id', $bookmarkIds)
            ->get()
            ->keyBy('id');

        $missingIds = array_diff($bookmarkIds, $bookmarks->keys()->all());
        if ($missingIds !== []) {
            return Response::error(
                'Some bookmark IDs were not found in this list: '.implode(', ', $missingIds)
            );
        }

        // Update positions based on the order provided
        foreach ($bookmarkIds as $position => $bookmarkId) {
            $bookmarks[$bookmarkId]->update(['position' => $position + 1]);
        }

        return Response::structured([
            'message' => 'Bookmarks reordered successfully.',
            'list_id' => $list->id,
            'new_order' => collect($bookmarkIds)->map(fn ($id, $index): array => [
                'bookmark_id' => $id,
                'position' => $index + 1,
                'title' => $bookmarks[$id]->title,
            ])->values()->all(),
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
                ->description('The ID of the list containing the bookmarks.')
                ->required(),

            'bookmark_ids' => $schema->array()
                ->description('Array of bookmark IDs (integers) in the desired order. Position 0 will have position 1, etc.')
                ->required(),
        ];
    }
}
