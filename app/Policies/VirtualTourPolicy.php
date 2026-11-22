<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VirtualTour;

/**
 * Scenes and hotspots are authorized through their tour.
 */
class VirtualTourPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roleIn($user->current_workspace_id) !== null;
    }

    public function view(User $user, VirtualTour $tour): bool
    {
        return $user->roleIn($tour->workspace_id) !== null;
    }

    public function create(User $user): bool
    {
        return (bool) $user->roleIn($user->current_workspace_id)?->canEdit();
    }

    /**
     * Editing covers the tour's settings, scenes, hotspots and publishing.
     */
    public function update(User $user, VirtualTour $tour): bool
    {
        return (bool) $user->roleIn($tour->workspace_id)?->canEdit();
    }

    public function publish(User $user, VirtualTour $tour): bool
    {
        return $this->update($user, $tour);
    }

    /**
     * Editors may delete tours they created; owners and admins may delete any.
     */
    public function delete(User $user, VirtualTour $tour): bool
    {
        $role = $user->roleIn($tour->workspace_id);

        return (bool) $role?->canManage()
            || ($role?->canEdit() && $tour->created_by === $user->id);
    }
}
