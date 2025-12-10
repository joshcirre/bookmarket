<?php

namespace App\Mcp\Tools\Lists;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListAllListsTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = 'Get all bookmark lists for the authenticated user.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $request->user();
        $includeBookmarks = $request->get('include_bookmarks', false);

        $query = $user->bookmarkLists()->orderBy('created_at', 'desc');

        if ($includeBookmarks) {
            $query->with('bookmarks.tags');
        }

        $lists = $query->get();

        return Response::structured([
            'lists' => $lists->map(fn ($list) => [
                'id' => $list->id,
                'title' => $list->title,
                'slug' => $list->slug,
                'description' => $list->description,
                'visibility' => $list->visibility->value,
                'bookmarks_count' => $list->bookmarks_count,
                'created_at' => $list->created_at->toIso8601String(),
                'updated_at' => $list->updated_at->toIso8601String(),
                ...($includeBookmarks ? [
                    'bookmarks' => $list->bookmarks->map(fn ($b) => [
                        'id' => $b->id,
                        'title' => $b->title,
                        'url' => $b->url,
                        'description' => $b->description,
                        'domain' => $b->domain,
                        'tags' => $b->tags->pluck('name')->toArray(),
                    ])->toArray(),
                ] : []),
            ])->toArray(),
            'total' => $lists->count(),
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
            'include_bookmarks' => $schema->boolean()
                ->description('Include bookmarks in each list. Defaults to false for performance.')
                ->default(false),
        ];
    }
}
