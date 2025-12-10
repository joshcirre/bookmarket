<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (User $user): void {
            if (empty($user->username)) {
                $user->username = static::generateUniqueUsername($user->name);
            }
        });
    }

    /**
     * Generate a unique username from a name.
     */
    public static function generateUniqueUsername(string $name): string
    {
        $baseUsername = Str::slug($name) ?: 'user';
        $username = $baseUsername;
        $counter = 1;

        while (static::query()->where('username', $username)->exists()) {
            $username = $baseUsername.'-'.$counter;
            $counter++;
        }

        return $username;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'username',
        'bio',
        'workos_id',
        'avatar',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'workos_id',
        'remember_token',
    ];

    /**
     * Get the user's initials.
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->map(fn (string $name) => Str::of($name)->substr(0, 1))
            ->implode('');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's bookmark lists.
     */
    public function bookmarkLists(): HasMany
    {
        return $this->hasMany(BookmarkList::class);
    }

    /**
     * Get the user's public bookmark lists.
     */
    public function publicBookmarkLists(): HasMany
    {
        return $this->bookmarkLists()->where('visibility', 'public');
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'username';
    }

    /**
     * Get the public profile URL.
     */
    protected function getProfileUrlAttribute(): string
    {
        return route('profile.show', $this->username);
    }
}
