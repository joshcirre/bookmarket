<?php

namespace App\Mcp\Tools\Search;

use App\Models\Bookmark;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use App\Mcp\Tools\RbacTool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Read-only tool - available to all authenticated users by default.
 */
#[IsReadOnly]
class SearchBookmarksTool extends RbacTool
{
    /**
     * The tool's description.
     */
    protected string $description = 'Search across all bookmarks by title, URL, description, or domain.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'query' => 'required|string|min:2|max:255',
            'list_id' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:100',
        ], [
            'query.required' => 'You must provide a search query.',
            'query.min' => 'The search query must be at least 2 characters.',
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();
        $query = $validated['query'];
        $limit = $validated['limit'] ?? 25;

        $bookmarksQuery = Bookmark::with(['bookmarkList', 'tags'])
            ->where('user_id', $user->id)
            ->where(function ($q) use ($query): void {
                $q->where('title', 'like', sprintf('%%%s%%', $query))
                    ->orWhere('url', 'like', sprintf('%%%s%%', $query))
                    ->orWhere('description', 'like', sprintf('%%%s%%', $query))
                    ->orWhere('domain', 'like', sprintf('%%%s%%', $query))
                    ->orWhere('notes', 'like', sprintf('%%%s%%', $query));
            });

        // Optionally filter by list
        if (isset($validated['list_id'])) {
            $bookmarksQuery->where('bookmark_list_id', $validated['list_id']);
        }

        $bookmarks = $bookmarksQuery->latest()
            ->limit($limit)
            ->get();

        return Response::structured([
            'query' => $query,
            'results_count' => $bookmarks->count(),
            'bookmarks' => $bookmarks->map(fn ($b): array => [
                'id' => $b->id,
                'title' => $b->title,
                'url' => $b->url,
                'description' => $b->description,
                'domain' => $b->domain,
                'list' => [
                    'id' => $b->bookmarkList?->id,
                    'title' => $b->bookmarkList?->title,
                ],
                'tags' => $b->tags->pluck('name')->toArray(),
                'created_at' => $b->created_at?->toIso8601String(),
            ])->all(),
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
            'query' => $schema->string()
                ->description('The search query. Searches title, URL, description, domain, and notes.')
                ->required(),

            'list_id' => $schema->integer()
                ->description('Optional list ID to limit search to a specific list.'),

            'limit' => $schema->integer()
                ->description('Maximum number of results to return. Default 25, max 100.')
                ->default(25),
        ];
    }
}
