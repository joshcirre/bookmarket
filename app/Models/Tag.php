<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    /** @use HasFactory<\Database\Factories\TagFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Tag $tag): void {
            if (empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    /**
     * Get the bookmarks that have this tag.
     */
    public function bookmarks(): MorphToMany
    {
        return $this->morphedByMany(Bookmark::class, 'taggable');
    }

    /**
     * Find or create a tag by name.
     */
    public static function findOrCreateByName(string $name): static
    {
        $slug = Str::slug($name);

        return static::query()->firstOrCreate(['slug' => $slug], ['name' => $name]);
    }
}
