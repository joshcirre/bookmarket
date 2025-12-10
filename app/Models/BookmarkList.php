<?php

namespace App\Models;

use App\Enums\ListVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BookmarkList extends Model
{
    /** @use HasFactory<\Database\Factories\BookmarkListFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'slug',
        'description',
        'visibility',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => ListVisibility::class,
            'bookmarks_count' => 'integer',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (BookmarkList $list): void {
            if (empty($list->slug)) {
                $list->slug = static::generateUniqueSlug($list->title, $list->user_id);
            }
        });
    }

    /**
     * Generate a unique slug for the list within the user's lists.
     */
    public static function generateUniqueSlug(string $title, int $userId): string
    {
        $slug = Str::slug($title);
        $originalSlug = $slug;
        $counter = 1;

        while (static::query()->where('user_id', $userId)->where('slug', $slug)->exists()) {
            $slug = $originalSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Get the user that owns the list.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the bookmarks in this list.
     */
    public function bookmarks(): HasMany
    {
        return $this->hasMany(Bookmark::class)->orderBy('position');
    }

    /**
     * Check if the list is visible to the public.
     */
    public function isPublic(): bool
    {
        return $this->visibility === ListVisibility::Public;
    }

    /**
     * Check if the list is private.
     */
    public function isPrivate(): bool
    {
        return $this->visibility === ListVisibility::Private;
    }

    /**
     * Check if the list is unlisted (accessible via link).
     */
    public function isUnlisted(): bool
    {
        return $this->visibility === ListVisibility::Unlisted;
    }

    /**
     * Get the public URL for this list.
     */
    protected function getPublicUrlAttribute(): string
    {
        return route('profile.list', [$this->user->username, $this->slug]);
    }
}
