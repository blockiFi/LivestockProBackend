<?php

namespace App\Mail;

use App\Models\Notification;
use App\Notifications\NotificationTypeRegistry;
use App\Services\Notifications\NotificationTemplateData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Renders a notification through its category template.
 *
 * The subject and body come entirely from the notification record plus the
 * derived template variables, so copy changes never require touching code.
 */
class NotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public Notification $notification)
    {
    }

    public function envelope(): Envelope
    {
        $registry = app(NotificationTypeRegistry::class);
        $prefix = $this->subjectPrefix($registry->label($this->notification->type));

        return new Envelope(
            subject: trim($prefix . $this->notification->title),
        );
    }

    public function content(): Content
    {
        $registry = app(NotificationTypeRegistry::class);
        $template = $registry->template($this->notification->type);

        if (!view()->exists($template)) {
            $template = config('notifications.defaults.template');
        }

        return new Content(
            view: $template,
            with: app(NotificationTemplateData::class)->build($this->notification),
        );
    }

    protected function subjectPrefix(string $label): string
    {
        // Avoid "Task assigned: Task assigned" style duplication.
        if (str_starts_with(mb_strtolower($this->notification->title), mb_strtolower($label))) {
            return '';
        }

        return $label . ': ';
    }
}
