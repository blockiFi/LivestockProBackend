<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminLoginCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $name,
        public readonly string $code,
        public readonly int $minutes,
        public readonly ?string $ip,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your Farm Central admin sign-in code: {$this->code}");
    }

    public function content(): Content
    {
        $name = e($this->name);
        $code = e($this->code);
        $ip = $this->ip ? ' from IP address ' . e($this->ip) : '';

        return new Content(htmlString: <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;color:#3B4045;max-width:480px">
  <p>Hi {$name},</p>
  <p>Use this code to finish signing in to the Farm Central admin console{$ip}:</p>
  <p style="font-size:28px;font-weight:700;letter-spacing:6px;color:#1E5A36;margin:24px 0">{$code}</p>
  <p>The code expires in {$this->minutes} minutes. If you did not try to sign in, change your password and tell your team.</p>
</div>
HTML);
    }
}
