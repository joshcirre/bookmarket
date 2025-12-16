<?php

namespace App\Mcp\Tools\Bookmarks;

use App\Models\Bookmark;
use App\Models\BookmarkList;
use App\Models\Tag;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use App\Mcp\Tools\RbacTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class CreateBookmarkTool extends RbacTool
{
    /**
     * The tool's description.
     */
    protected string $description = 'Create a new bookmark in a specified list. IMPORTANT: Before creating, use list_tags to see existing tags and reuse them when appropriate instead of creating duplicates.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'list_id' => 'required|integer',
            'url' => 'required|url|max:2048',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:5000',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ], [
            'list_id.required' => 'You must specify a list_id to add the bookmark to.',
            'url.required' => 'You must provide a URL for the bookmark.',
            'url.url' => 'The URL must be a valid URL.',
            'title.required' => 'You must provide a title for the bookmark.',
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

        $bookmark = Bookmark::query()->create([
            'bookmark_list_id' => $list->id,
            'user_id' => $user->id,
            'url' => $validated['url'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        // Attach tags if provided
        if (! empty($validated['tags'])) {
            $tagIds = collect($validated['tags'])
                ->map(fn ($name): Tag => Tag::findOrCreateByName(trim((string) $name)))
                ->pluck('id')
                ->toArray();
            $bookmark->tags()->sync($tagIds);
        }

        $bookmark->load('tags');

        return Response::structured([
            'message' => 'Bookmark created successfully.',
            'bookmark' => [
                'id' => $bookmark->id,
                'list_id' => $bookmark->bookmark_list_id,
                'url' => $bookmark->url,
                'title' => $bookmark->title,
                'description' => $bookmark->description,
                'notes' => $bookmark->notes,
                'domain' => $bookmark->domain,
                'position' => $bookmark->position,
                'tags' => $bookmark->tags->pluck('name')->toArray(),
                'created_at' => $bookmark->created_at?->toIso8601String(),
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
                ->description('The ID of the list to add the bookmark to.')
                ->required(),

            'url' => $schema->string()
                ->description('The URL of the bookmark.')
                ->required(),

            'title' => $schema->string()
                ->description('The title of the bookmark.')
                ->required(),

            'description' => $schema->string()
                ->description('A brief description of the bookmark.'),

            'notes' => $schema->string()
                ->description('Personal notes about the bookmark.'),

            'tags' => $schema->array()
                ->description('Array of tag names (strings). IMPORTANT: Use list_tags first to see existing tags and reuse them instead of creating duplicates.'),
        ];
    }
}
