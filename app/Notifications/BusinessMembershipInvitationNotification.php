<?php

namespace App\Notifications;

use App\Models\BusinessMembershipInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BusinessMembershipInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly BusinessMembershipInvitation $invitation,
        public readonly string $plainToken,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.url'), '/').'/accept-business-invitation?token='.urlencode($this->plainToken);

        return (new MailMessage)
            ->subject('Invitation to join '.$this->invitation->business->name)
            ->greeting('You have been invited to join a business')
            ->line('You were invited to join '.$this->invitation->business->name.' as '.$this->invitation->role.'.')
            ->line('This invitation expires '.$this->invitation->expires_at->toDayDateTimeString().'.')
            ->action('Review invitation', $url)
            ->line('If you were not expecting this invitation, you can ignore this email.');
    }
}
