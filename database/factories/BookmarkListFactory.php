<?php

namespace Database\Factories;

use App\Enums\ListVisibility;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BookmarkList>
 */
class BookmarkListFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(),
            'visibility' => ListVisibility::Private,
        ];
    }

    /**
     * Indicate that the list is public.
     */
    public function public(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visibility' => ListVisibility::Public,
        ]);
    }

    /**
     * Indicate that the list is unlisted.
     */
    public function unlisted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visibility' => ListVisibility::Unlisted,
        ]);
    }

    /**
     * Indicate that the list is private.
     */
    public function private(): static
    {
        return $this->state(fn (array $attributes): array => [
            'visibility' => ListVisibility::Private,
        ]);
    }
}
