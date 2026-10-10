<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ApiPasswordResetNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.url'), '/').'/reset-password?token='.urlencode($this->token).'&email='.urlencode($notifiable->getEmailForPasswordReset());

        return (new MailMessage)
            ->subject('Reset your Thrift Collector Platform password')
            ->greeting('Hello '.$notifiable->name.',')
            ->line('We received a request to reset your account password.')
            ->action('Reset password', $url)
            ->line('This link expires according to the password reset policy. If you did not request this, you can ignore this email.');
    }
}
