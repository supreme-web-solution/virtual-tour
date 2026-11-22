<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class ActivityLogger
{
    /**
     * Record an important user or system action.
     *
     * @param  array<string, mixed>  $properties
     */
    public function log(
        ?int $workspaceId,
        ?User $user,
        string $action,
        string $description,
        ?Model $subject = null,
        array $properties = [],
    ): ActivityLog {
        return ActivityLog::create([
            'workspace_id' => $workspaceId,
            'user_id' => $user?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'properties' => $properties === [] ? null : $properties,
        ]);
    }
}
