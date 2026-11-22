<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Editor = 'editor';
    case Viewer = 'viewer';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Owners and admins manage the workspace's content and members.
     */
    public function canManage(): bool
    {
        return in_array($this, [self::Owner, self::Admin], true);
    }

    /**
     * Everyone except viewers can create and edit content.
     */
    public function canEdit(): bool
    {
        return $this !== self::Viewer;
    }
}
