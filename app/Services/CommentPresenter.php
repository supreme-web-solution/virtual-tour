<?php

namespace App\Services;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Team notes for a page: newest last, with what the viewer may do.
 */
class CommentPresenter
{
    /**
     * @return array{subject: array{type: string, id: string}, items: list<array<string, mixed>>, can_comment: bool}
     */
    public function for(Model $subject, User $viewer): array
    {
        $type = (string) array_search($subject::class, Comment::SUBJECTS, true);
        $workspaceId = (int) $subject->getAttribute('workspace_id');
        $role = $viewer->roleIn($workspaceId);

        $items = Comment::where('commentable_type', $subject->getMorphClass())
            ->where('commentable_id', $subject->getKey())
            ->with('user:id,name')
            ->oldest('id')
            ->limit(200)
            ->get()
            ->map(fn (Comment $comment): array => [
                'id' => $comment->ulid,
                'body' => $comment->body,
                'author' => $comment->user->name ?? __('Former member'),
                'is_mine' => $comment->user_id === $viewer->id,
                'can_delete' => $comment->user_id === $viewer->id || (bool) $role?->canManage(),
                'edited' => $comment->edited_at !== null,
                'created_at' => $comment->created_at?->toIso8601String(),
            ]);

        return [
            'subject' => ['type' => $type, 'id' => (string) $subject->getAttribute('ulid')],
            'items' => array_values($items->all()),
            'can_comment' => (bool) $role?->canEdit(),
        ];
    }
}
