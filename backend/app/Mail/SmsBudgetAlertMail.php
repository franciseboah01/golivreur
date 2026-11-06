<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SmsBudgetAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly int $compteur,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "🚨 GoLivreur — Seuil SMS atteint ({$this->compteur} SMS)",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: "<p>Le seuil de {$this->compteur} SMS/mois a été atteint.</p>",
        );
    }
}
