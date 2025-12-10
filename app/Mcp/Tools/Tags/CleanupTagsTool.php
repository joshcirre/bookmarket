<?php

namespace App\Mcp\Tools\Tags;

use App\Models\Bookmark;
use App\Models\Tag;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class CleanupTagsTool extends Tool
{
    /**
     * The tool's description.
     */
    protected string $description = 'Find and merge duplicate or similar tags. Use preview mode first to see what would be merged, then confirm to apply changes.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'preview' => 'nullable|boolean',
            'merge' => 'nullable|array',
            'merge.*.keep' => 'required_with:merge|string|max:50',
            'merge.*.remove' => 'required_with:merge|array',
            'merge.*.remove.*' => 'string|max:50',
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();
        $preview = $validated['preview'] ?? true;

        // If explicit merge instructions provided, execute them
        if (! empty($validated['merge'])) {
            return $this->executeMerge($user, $validated['merge']);
        }

        // Otherwise, find potential duplicates
        return $this->findDuplicates($user, $preview);
    }

    /**
     * Find potential duplicate tags.
     */
    private function findDuplicates(\App\Models\User $user, bool $preview): \Laravel\Mcp\ResponseFactory
    {
        // Get all tags used by this user's bookmarks
        $userTags = DB::table('tags')
            ->join('taggables', 'tags.id', '=', 'taggables.tag_id')
            ->join('bookmarks', function ($join) use ($user): void {
                $join->on('taggables.taggable_id', '=', 'bookmarks.id')
                    ->where('taggables.taggable_type', '=', Bookmark::class)
                    ->where('bookmarks.user_id', '=', $user->id);
            })
            ->select('tags.id', 'tags.name', 'tags.slug', DB::raw('COUNT(*) as bookmarks_count'))
            ->groupBy('tags.id', 'tags.name', 'tags.slug')
            ->orderBy('tags.name')
            ->get();

        // Group by normalized slug to find potential duplicates
        $groups = [];
        foreach ($userTags as $tag) {
            $normalizedSlug = Str::slug($tag->name);
            if (! isset($groups[$normalizedSlug])) {
                $groups[$normalizedSlug] = [];
            }

            $groups[$normalizedSlug][] = [
                'id' => $tag->id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'bookmarks_count' => $tag->bookmarks_count,
            ];
        }

        // Filter to only groups with duplicates
        $duplicates = [];
        foreach ($groups as $slug => $tags) {
            if (count($tags) > 1) {
                // Sort by bookmarks_count desc so most used is first (recommended to keep)
                usort($tags, fn (array $a, array $b): int => $b['bookmarks_count'] <=> $a['bookmarks_count']);
                $duplicates[] = [
                    'normalized_slug' => $slug,
                    'tags' => $tags,
                    'suggested_keep' => $tags[0]['name'],
                    'suggested_remove' => array_map(fn (array $t): string => $t['name'], array_slice($tags, 1)),
                ];
            }
        }

        if ($duplicates === []) {
            return Response::structured([
                'message' => 'No duplicate tags found. Your tags are clean!',
                'duplicates' => [],
            ]);
        }

        return Response::structured([
            'message' => sprintf('Found %d groups of potential duplicate tags.', count($duplicates)),
            'instructions' => $preview
                ? 'Review the suggestions below. To merge, call this tool again with merge parameter specifying which tags to keep and remove.'
                : 'Use the merge parameter to specify which tags to merge.',
            'duplicates' => $duplicates,
            'example_merge' => [
                'merge' => [
                    [
                        'keep' => $duplicates[0]['suggested_keep'],
                        'remove' => $duplicates[0]['suggested_remove'],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Execute tag merges.
     *
     * @param  array<int, array{keep: string, remove: array<int, string>}>  $merges
     */
    private function executeMerge(\App\Models\User $user, array $merges): \Laravel\Mcp\ResponseFactory
    {
        $results = [];

        foreach ($merges as $merge) {
            $keepName = trim($merge['keep']);
            $removeNames = array_map(trim(...), $merge['remove']);

            // Find or create the tag to keep
            $keepTag = Tag::findOrCreateByName($keepName);

            // Find tags to remove
            $removeTags = Tag::query()
                ->whereIn('slug', array_map(fn (string $name): string => Str::slug($name), $removeNames))
                ->get();

            if ($removeTags->isEmpty()) {
                $results[] = [
                    'keep' => $keepName,
                    'status' => 'skipped',
                    'message' => 'No matching tags found to remove.',
                ];

                continue;
            }

            $bookmarksUpdated = 0;

            // Get user's bookmarks that have any of the tags to remove
            $userBookmarkIds = Bookmark::query()
                ->where('user_id', $user->id)
                ->pluck('id')
                ->toArray();

            foreach ($removeTags as $removeTag) {
                // Get bookmarks with this tag that belong to the user
                $bookmarksWithRemoveTag = DB::table('taggables')
                    ->where('tag_id', $removeTag->id)
                    ->where('taggable_type', Bookmark::class)
                    ->whereIn('taggable_id', $userBookmarkIds)
                    ->pluck('taggable_id');

                foreach ($bookmarksWithRemoveTag as $bookmarkId) {
                    /** @var Bookmark|null $bookmark */
                    $bookmark = Bookmark::query()->find($bookmarkId);
                    if (! $bookmark) {
                        continue;
                    }

                    // Add keep tag if not already present
                    if (! $bookmark->tags->contains('id', $keepTag->id)) {
                        $bookmark->tags()->attach($keepTag->id);
                    }

                    // Remove the old tag
                    $bookmark->tags()->detach($removeTag->id);
                    $bookmarksUpdated++;
                }
            }

            // Clean up orphaned tags (tags with no taggables)
            $orphanedTagIds = $removeTags->pluck('id')->toArray();
            foreach ($orphanedTagIds as $tagId) {
                $hasOtherUsages = DB::table('taggables')
                    ->where('tag_id', $tagId)
                    ->exists();

                if (! $hasOtherUsages) {
                    Tag::query()->where('id', $tagId)->delete();
                }
            }

            $results[] = [
                'keep' => $keepName,
                'merged' => $removeTags->pluck('name')->toArray(),
                'bookmarks_updated' => $bookmarksUpdated,
                'status' => 'success',
            ];
        }

        return Response::structured([
            'message' => 'Tag cleanup completed.',
            'results' => $results,
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
            'preview' => $schema->boolean()
                ->description('If true (default), shows potential duplicates without making changes. Set to false and provide merge to apply changes.')
                ->default(true),

            'merge' => $schema->array()
                ->description('Array of merge operations. Each object should have "keep" (string - tag name to keep) and "remove" (array of strings - tag names to merge into keep tag).'),
        ];
    }
}
