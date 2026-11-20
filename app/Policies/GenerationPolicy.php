<?php

namespace App\Policies;

use App\Models\Generation;
use App\Models\User;

class GenerationPolicy
{
    /**
     * Only the user who created a generation may see it (and its image).
     */
    public function view(User $user, Generation $generation): bool
    {
        return $generation->user_id === $user->id;
    }
}
