<?php

namespace App\Policies;

use App\Models\Bookmark;
use App\Models\User;

class BookmarkPolicy
{
    /**
     * Determine whether the user can view the model.
     * Delegates to the bookmark list's visibility.
     */
    public function view(?User $user, Bookmark $bookmark): bool
    {
        return (new BookmarkListPolicy)->view($user, $bookmark->bookmarkList);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Bookmark $bookmark): bool
    {
        return $user->id === $bookmark->bookmarkList->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Bookmark $bookmark): bool
    {
        return $user->id === $bookmark->bookmarkList->user_id;
    }
}
