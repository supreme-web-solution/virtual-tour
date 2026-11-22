<?php

namespace App\Policies;

use App\Models\AiGeneration;
use App\Models\User;

class AiGenerationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roleIn($user->current_workspace_id) !== null;
    }

    public function view(User $user, AiGeneration $generation): bool
    {
        return $user->roleIn($generation->workspace_id) !== null;
    }

    /**
     * Starting generations spends workspace credits, so viewers cannot.
     */
    public function create(User $user): bool
    {
        return (bool) $user->roleIn($user->current_workspace_id)?->canEdit();
    }

    public function update(User $user, AiGeneration $generation): bool
    {
        return (bool) $user->roleIn($generation->workspace_id)?->canEdit();
    }
}
