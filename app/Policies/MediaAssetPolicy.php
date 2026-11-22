<?php

namespace App\Policies;

use App\Models\MediaAsset;
use App\Models\User;

class MediaAssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->roleIn($user->current_workspace_id) !== null;
    }

    public function view(User $user, MediaAsset $asset): bool
    {
        return $user->roleIn($asset->workspace_id) !== null;
    }

    public function create(User $user): bool
    {
        return (bool) $user->roleIn($user->current_workspace_id)?->canEdit();
    }

    public function update(User $user, MediaAsset $asset): bool
    {
        return (bool) $user->roleIn($asset->workspace_id)?->canEdit();
    }

    /**
     * Editors may delete their own uploads; owners and admins may delete any asset.
     */
    public function delete(User $user, MediaAsset $asset): bool
    {
        $role = $user->roleIn($asset->workspace_id);

        return (bool) $role?->canManage()
            || ($role?->canEdit() && $asset->uploaded_by === $user->id);
    }
}
