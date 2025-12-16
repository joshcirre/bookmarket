<?php

namespace App\Mcp\Tools\Tags;

use App\Mcp\Tools\RbacTool;
use App\Models\Bookmark;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Read-only tool - available to all authenticated users by default.
 */
#[IsReadOnly]
class ListTagsTool extends RbacTool
{
    protected ?string $requiredPermission = 'tags:read';

    /**
     * The tool's description.
     */
    protected string $description = 'List all existing tags with bookmark counts. IMPORTANT: Always call this before creating/updating bookmarks to see available tags and reuse them instead of creating duplicates.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        // Get all tags that are attached to the user's bookmarks
        $tags = DB::table('tags')
            ->join('taggables', 'tags.id', '=', 'taggables.tag_id')
            ->join('bookmarks', function ($join) use ($user): void {
                $join->on('taggables.taggable_id', '=', 'bookmarks.id')
                    ->where('taggables.taggable_type', '=', Bookmark::class)
                    ->where('bookmarks.user_id', '=', $user->id);
            })
            ->select('tags.id', 'tags.name', 'tags.slug', DB::raw('COUNT(*) as bookmarks_count'))
            ->groupBy('tags.id', 'tags.name', 'tags.slug')
            ->orderBy('bookmarks_count', 'desc')
            ->get();

        return Response::structured([
            'tags' => $tags->map(fn ($tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'bookmarks_count' => $tag->bookmarks_count,
            ])->all(),
            'total' => $tags->count(),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
