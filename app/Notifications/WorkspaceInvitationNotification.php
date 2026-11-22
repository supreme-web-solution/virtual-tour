<?php

namespace App\Notifications;

use App\Models\WorkspaceInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WorkspaceInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $token  Plain token; only its hash is stored.
     */
    public function __construct(public WorkspaceInvitation $invitation, public string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $invitation = $this->invitation->loadMissing(['workspace', 'inviter']);
        $inviter = $invitation->inviter->name ?? 'A colleague';

        return (new MailMessage)
            ->subject("You've been invited to {$invitation->workspace->name}")
            ->line("{$inviter} invited you to join “{$invitation->workspace->name}” as {$invitation->role->label()}.")
            ->action('Accept invitation', route('invitations.show', $this->token))
            ->line('This invitation expires on '.$invitation->expires_at->toFormattedDayDateString().'.')
            ->line('If you were not expecting it, you can ignore this email.');
    }
}
