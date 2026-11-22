<?php

namespace App\Notifications;

use App\Models\TourLead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class NewTourLeadNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public TourLead $lead) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $lead = $this->lead->loadMissing('tour');

        $message = (new MailMessage)
            ->subject("New enquiry from {$lead->tour->title}")
            ->greeting('New tour enquiry')
            ->line("{$lead->name} sent a message from your tour “{$lead->tour->title}”.")
            ->line("Email: {$lead->email}");

        if ($lead->phone) {
            $message->line("Phone: {$lead->phone}");
        }

        if ($lead->message) {
            $message->line('Message: '.str($lead->message)->limit(500));
        }

        return $message
            ->action('View enquiries', route('analytics.index', ['tab' => 'leads']))
            ->replyTo($lead->email, $lead->name);
    }
}
