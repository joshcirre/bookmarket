<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Bookmark extends Model
{
    /** @use HasFactory<\Database\Factories\BookmarkFactory> */
    use HasFactory;

    protected $fillable = [
        'bookmark_list_id',
        'user_id',
        'url',
        'title',
        'description',
        'notes',
        'favicon_url',
        'domain',
        'position',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Bookmark $bookmark): void {
            if (empty($bookmark->domain)) {
                $bookmark->domain = parse_url($bookmark->url, PHP_URL_HOST);
            }

            if (empty($bookmark->position)) {
                $maxPosition = static::query()->where('bookmark_list_id', $bookmark->bookmark_list_id)
                    ->max('position') ?? 0;
                $bookmark->position = $maxPosition + 1;
            }
        });

        static::created(function (Bookmark $bookmark): void {
            $bookmark->bookmarkList->increment('bookmarks_count');
        });

        static::deleting(function (Bookmark $bookmark): void {
            $bookmark->tags()->detach();
        });

        static::deleted(function (Bookmark $bookmark): void {
            $bookmark->bookmarkList->decrement('bookmarks_count');
        });
    }

    /**
     * Get the list this bookmark belongs to.
     */
    public function bookmarkList(): BelongsTo
    {
        return $this->belongsTo(BookmarkList::class);
    }

    /**
     * Get the user who created this bookmark.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the tags for this bookmark.
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Get the favicon URL or a default.
     */
    protected function getFaviconAttribute(?string $value): string
    {
        return $value ?? 'https://www.google.com/s2/favicons?domain='.$this->domain.'&sz=32';
    }
}
