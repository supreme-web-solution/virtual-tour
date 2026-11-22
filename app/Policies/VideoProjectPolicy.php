<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VideoProject;

/**
 * Shots, clips, music and exports are authorized through their project.
 */
class VideoProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roleIn($user->current_workspace_id) !== null;
    }

    public function view(User $user, VideoProject $project): bool
    {
        return $user->roleIn($project->workspace_id) !== null;
    }

    public function create(User $user): bool
    {
        return (bool) $user->roleIn($user->current_workspace_id)?->canEdit();
    }

    /**
     * Editing, generating (which spends credits), rendering and publishing.
     */
    public function update(User $user, VideoProject $project): bool
    {
        return (bool) $user->roleIn($project->workspace_id)?->canEdit();
    }

    public function delete(User $user, VideoProject $project): bool
    {
        $role = $user->roleIn($project->workspace_id);

        return (bool) $role?->canManage()
            || ($role?->canEdit() && $project->created_by === $user->id);
    }
}
