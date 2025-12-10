<?php

namespace App\Mcp\Tools\Bookmarks;

use App\Models\Bookmark;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateBookmarkTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = 'Update an existing bookmark.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'bookmark_id' => 'required|integer',
            'url' => 'nullable|url|max:2048',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:5000',
        ], [
            'bookmark_id.required' => 'You must provide a bookmark_id to update.',
            'url.url' => 'The URL must be a valid URL.',
        ]);

        $user = $request->user();
        $bookmark = Bookmark::where('user_id', $user->id)
            ->find($validated['bookmark_id']);

        if (! $bookmark) {
            return Response::error('Bookmark not found. Make sure the bookmark_id belongs to your account.');
        }

        $updates = [];

        if (isset($validated['url'])) {
            $updates['url'] = $validated['url'];
            $updates['domain'] = parse_url($validated['url'], PHP_URL_HOST);
        }

        if (isset($validated['title'])) {
            $updates['title'] = $validated['title'];
        }

        if (array_key_exists('description', $validated)) {
            $updates['description'] = $validated['description'];
        }

        if (array_key_exists('notes', $validated)) {
            $updates['notes'] = $validated['notes'];
        }

        if (empty($updates)) {
            return Response::error('No updates provided. Specify at least one of: url, title, description, notes.');
        }

        $bookmark->update($updates);
        $bookmark->refresh();

        return Response::structured([
            'message' => 'Bookmark updated successfully.',
            'bookmark' => [
                'id' => $bookmark->id,
                'list_id' => $bookmark->bookmark_list_id,
                'url' => $bookmark->url,
                'title' => $bookmark->title,
                'description' => $bookmark->description,
                'notes' => $bookmark->notes,
                'domain' => $bookmark->domain,
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
                ->description('The ID of the bookmark to update.')
                ->required(),

            'url' => $schema->string()
                ->description('The new URL for the bookmark.'),

            'title' => $schema->string()
                ->description('The new title for the bookmark.'),

            'description' => $schema->string()
                ->description('The new description. Pass empty string to clear.'),

            'notes' => $schema->string()
                ->description('The new notes. Pass empty string to clear.'),
        ];
    }
}
