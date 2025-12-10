<?php

namespace App\Policies;

use App\Enums\ListVisibility;
use App\Models\BookmarkList;
use App\Models\User;

class BookmarkListPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     * Note: $user can be null for public/unlisted lists.
     */
    public function view(?User $user, BookmarkList $bookmarkList): bool
    {
        // Public and unlisted lists are viewable by anyone
        if ($bookmarkList->visibility !== ListVisibility::Private) {
            return true;
        }

        // Private lists require authentication and ownership
        return $user instanceof \App\Models\User && $user->id === $bookmarkList->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, BookmarkList $bookmarkList): bool
    {
        return $user->id === $bookmarkList->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, BookmarkList $bookmarkList): bool
    {
        return $user->id === $bookmarkList->user_id;
    }

    /**
     * Determine whether the user can add bookmarks to the list.
     */
    public function addBookmark(User $user, BookmarkList $bookmarkList): bool
    {
        return $user->id === $bookmarkList->user_id;
    }
}
