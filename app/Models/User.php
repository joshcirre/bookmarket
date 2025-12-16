<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    /**
     * The user's role from the MCP JWT token (e.g., 'member', 'subscriber').
     * Only set during MCP requests, not persisted to database.
     */
    protected ?string $mcpRole = null;

    /**
     * The user's permissions from the MCP JWT token (e.g., ['bookmarks:read', 'lists:write']).
     * Only set during MCP requests, not persisted to database.
     *
     * @var array<string>
     */
    protected array $mcpPermissions = [];

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

    /**
     * Set the user's MCP role from the JWT token.
     */
    public function setMcpRole(?string $role): void
    {
        $this->mcpRole = $role;
    }

    /**
     * Get the user's MCP role.
     */
    public function getMcpRole(): ?string
    {
        return $this->mcpRole;
    }

    /**
     * Set the user's MCP permissions from the JWT token.
     *
     * @param  array<string>|object  $permissions
     */
    public function setMcpPermissions(array|object $permissions): void
    {
        // Handle both array and stdClass from JWT decode
        $this->mcpPermissions = is_array($permissions) ? $permissions : (array) $permissions;
    }

    /**
     * Get the user's MCP permissions.
     *
     * @return array<string>
     */
    public function getMcpPermissions(): array
    {
        return $this->mcpPermissions;
    }

    /**
     * Check if the user has a specific MCP permission.
     */
    public function hasMcpPermission(string $permission): bool
    {
        return in_array($permission, $this->mcpPermissions, true);
    }

    /**
     * Check if the user has any of the given MCP permissions.
     *
     * @param  array<string>  $permissions
     */
    public function hasAnyMcpPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasMcpPermission($permission)) {
                return true;
            }
        }

        return false;
    }
}
