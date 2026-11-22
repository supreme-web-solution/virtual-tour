<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Team notes. Every member can read them; editors and above can write;
 * authors edit their own notes; owners and admins can delete any note.
 */
class CommentController extends Controller
{
    public function __construct(protected ActivityLogger $activity) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'subject_type' => ['required', Rule::in(array_keys(Comment::SUBJECTS))],
            'subject_id' => ['required', 'string', 'max:26'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $subject = $this->subject($data['subject_type'], $data['subject_id'], $user);
        abort_unless((bool) $user->roleIn((int) $subject->getAttribute('workspace_id'))?->canEdit(), 403);

        $comment = new Comment(['body' => trim($data['body'])]);
        $comment->workspace_id = (int) $subject->getAttribute('workspace_id');
        $comment->user_id = $user->id;
        $comment->commentable()->associate($subject);
        $comment->save();

        $name = (string) ($subject->getAttribute('name') ?? $subject->getAttribute('title'));
        $this->activity->log($comment->workspace_id, $user, 'comment.added', "Added a note on {$name}", $subject, [
            'excerpt' => Str::limit($comment->body, 120),
        ]);

        return back();
    }

    public function update(Request $request, Comment $comment): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($comment->user_id === $user->id && $user->roleIn($comment->workspace_id) !== null, 403);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $comment->update(['body' => trim($data['body']), 'edited_at' => now()]);

        return back();
    }

    public function destroy(Request $request, Comment $comment): RedirectResponse
    {
        $user = $this->user($request);
        $role = $user->roleIn($comment->workspace_id);
        abort_unless($role !== null && ($comment->user_id === $user->id || $role->canManage()), 403);

        $comment->delete();

        return back();
    }

    /**
     * The commented item, which must belong to the user's current workspace.
     */
    protected function subject(string $type, string $ulid, User $user): Model
    {
        $class = Comment::SUBJECTS[$type];

        return $class::query()
            ->where('ulid', $ulid)
            ->where('workspace_id', $user->current_workspace_id)
            ->firstOrFail();
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
