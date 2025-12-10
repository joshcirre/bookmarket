<?php

namespace App\Mcp\Tools\Lists;

use App\Models\BookmarkList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetListTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = 'Get a specific bookmark list with its bookmarks.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'list_id' => 'required|integer',
        ], [
            'list_id.required' => 'You must provide a list_id to get the list.',
        ]);

        $user = $request->user();
        $list = BookmarkList::with('bookmarks.tags')
            ->where('user_id', $user->id)
            ->find($validated['list_id']);

        if (! $list) {
            return Response::error('List not found. Make sure the list_id belongs to your account.');
        }

        return Response::structured([
            'list' => [
                'id' => $list->id,
                'title' => $list->title,
                'slug' => $list->slug,
                'description' => $list->description,
                'visibility' => $list->visibility->value,
                'bookmarks_count' => $list->bookmarks_count,
                'created_at' => $list->created_at->toIso8601String(),
                'updated_at' => $list->updated_at->toIso8601String(),
                'bookmarks' => $list->bookmarks->map(fn ($b) => [
                    'id' => $b->id,
                    'title' => $b->title,
                    'url' => $b->url,
                    'description' => $b->description,
                    'notes' => $b->notes,
                    'domain' => $b->domain,
                    'favicon_url' => $b->favicon_url,
                    'position' => $b->position,
                    'tags' => $b->tags->pluck('name')->toArray(),
                    'created_at' => $b->created_at->toIso8601String(),
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
            'list_id' => $schema->integer()
                ->description('The ID of the list to retrieve.')
                ->required(),
        ];
    }
}
