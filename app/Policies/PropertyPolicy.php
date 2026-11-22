<?php

namespace App\Policies;

use App\Models\Property;
use App\Models\User;

class PropertyPolicy
{
    /**
     * Any member of the current workspace may list its properties.
     */
    public function viewAny(User $user): bool
    {
        return $user->roleIn($user->current_workspace_id) !== null;
    }

    public function view(User $user, Property $property): bool
    {
        return $user->roleIn($property->workspace_id) !== null;
    }

    public function create(User $user): bool
    {
        return (bool) $user->roleIn($user->current_workspace_id)?->canEdit();
    }

    public function update(User $user, Property $property): bool
    {
        return (bool) $user->roleIn($property->workspace_id)?->canEdit();
    }

    public function duplicate(User $user, Property $property): bool
    {
        return $this->update($user, $property);
    }

    /**
     * Editors may delete properties they created; owners and admins may delete any.
     */
    public function delete(User $user, Property $property): bool
    {
        $role = $user->roleIn($property->workspace_id);

        return (bool) $role?->canManage()
            || ($role?->canEdit() && $property->created_by === $user->id);
    }
}
