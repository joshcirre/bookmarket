<?php

namespace App\Mcp\Tools\Lists;

use App\Enums\ListVisibility;
use App\Mcp\Tools\RbacTool;
use App\Models\BookmarkList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rules\Enum;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class UpdateListTool extends RbacTool
{
    protected ?string $requiredPermission = 'lists:write';

    /**
     * The tool's description.
     */
    protected string $description = 'Update an existing bookmark list.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'list_id' => 'required|integer',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:1000',
            'visibility' => ['nullable', new Enum(ListVisibility::class)],
        ], [
            'list_id.required' => 'You must provide a list_id to update.',
            'title.max' => 'The title cannot exceed 255 characters.',
            'description.max' => 'The description cannot exceed 1000 characters.',
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

        $updates = [];

        if (isset($validated['title'])) {
            $updates['title'] = $validated['title'];
        }

        if (array_key_exists('description', $validated)) {
            $updates['description'] = $validated['description'];
        }

        if (isset($validated['visibility'])) {
            $updates['visibility'] = $validated['visibility'];
        }

        if ($updates === []) {
            return Response::error('No updates provided. Specify at least one of: title, description, visibility.');
        }

        $list->update($updates);
        $list->refresh();

        return Response::structured([
            'message' => 'List updated successfully.',
            'list' => [
                'id' => $list->id,
                'title' => $list->title,
                'slug' => $list->slug,
                'description' => $list->description,
                'visibility' => $list->visibility->value,
                'bookmarks_count' => $list->bookmarks_count,
                'updated_at' => $list->updated_at?->toIso8601String(),
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
                ->description('The ID of the list to update.')
                ->required(),

            'title' => $schema->string()
                ->description('The new title for the list.'),

            'description' => $schema->string()
                ->description('The new description for the list. Pass empty string to clear.'),

            'visibility' => $schema->string()
                ->enum(['public', 'private', 'unlisted'])
                ->description('The new visibility setting for the list.'),
        ];
    }
}
