<?php

namespace App\Mcp\Tools\Tags;

use App\Models\Bookmark;
use App\Models\Tag;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use App\Mcp\Tools\RbacTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class SyncBookmarkTagsTool extends RbacTool
{
    /**
     * The tool's description.
     */
    protected string $description = 'Update the tags on a bookmark. IMPORTANT: Use list_tags first to see existing tags and reuse them instead of creating duplicates.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'bookmark_id' => 'required|integer',
            'tags' => 'required|array',
            'tags.*' => 'required|string|max:50',
        ], [
            'bookmark_id.required' => 'You must provide a bookmark_id.',
            'tags.required' => 'You must provide an array of tags. Use an empty array to remove all tags.',
            'tags.*.max' => 'Each tag name cannot exceed 50 characters.',
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

        // Find or create each tag and collect their IDs
        $tagIds = collect($validated['tags'])
            ->map(fn ($name): \App\Models\Tag => Tag::findOrCreateByName(trim((string) $name)))
            ->pluck('id')
            ->toArray();

        // Sync the tags (this replaces all existing tags)
        $bookmark->tags()->sync($tagIds);

        // Reload the bookmark with tags
        $bookmark->load('tags');

        return Response::structured([
            'message' => 'Tags updated successfully.',
            'bookmark' => [
                'id' => $bookmark->id,
                'title' => $bookmark->title,
                'tags' => $bookmark->tags->map(fn ($tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                ])->toArray(),
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
                ->description('The ID of the bookmark to update tags on.')
                ->required(),

            'tags' => $schema->array()
                ->description('Array of tag names (strings). This replaces all existing tags. Use empty array [] to remove all tags. IMPORTANT: Use list_tags first to see existing tags and reuse them.')
                ->required(),
        ];
    }
}
