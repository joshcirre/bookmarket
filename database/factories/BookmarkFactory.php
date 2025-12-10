<?php

namespace Database\Factories;

use App\Models\BookmarkList;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Bookmark>
 */
class BookmarkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = fake()->url();

        return [
            'bookmark_list_id' => BookmarkList::factory(),
            'user_id' => User::factory(),
            'url' => $url,
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->sentence(),
            'notes' => fake()->optional()->paragraph(),
            'domain' => parse_url($url, PHP_URL_HOST),
        ];
    }

    /**
     * Create a bookmark with a specific URL.
     */
    public function withUrl(string $url): static
    {
        return $this->state(fn (array $attributes): array => [
            'url' => $url,
            'domain' => parse_url($url, PHP_URL_HOST),
        ]);
    }
}
