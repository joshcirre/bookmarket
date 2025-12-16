<?php

namespace App\Mcp\Tools\Lists;

use App\Enums\ListVisibility;
use App\Models\BookmarkList;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rules\Enum;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use App\Mcp\Tools\RbacTool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
class CreateListTool extends RbacTool
{
    /**
     * The tool's description.
     */
    protected string $description = 'Create a new bookmark list for the authenticated user.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'visibility' => ['nullable', new Enum(ListVisibility::class)],
        ], [
            'title.required' => 'You must provide a title for the list.',
            'title.max' => 'The title cannot exceed 255 characters.',
            'description.max' => 'The description cannot exceed 1000 characters.',
        ]);

        /** @var \App\Models\User $user */
        $user = $request->user();

        $list = BookmarkList::query()->create([
            'user_id' => $user->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'visibility' => $validated['visibility'] ?? ListVisibility::Private->value,
        ]);

        return Response::structured([
            'message' => 'List created successfully.',
            'list' => [
                'id' => $list->id,
                'title' => $list->title,
                'slug' => $list->slug,
                'description' => $list->description,
                'visibility' => $list->visibility->value,
                'bookmarks_count' => 0,
                'created_at' => $list->created_at?->toIso8601String(),
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
            'title' => $schema->string()
                ->description('The title of the list.')
                ->required(),

            'description' => $schema->string()
                ->description('An optional description for the list.'),

            'visibility' => $schema->string()
                ->enum(['public', 'private', 'unlisted'])
                ->description('The visibility of the list. Defaults to "private".')
                ->default('private'),
        ];
    }
}
