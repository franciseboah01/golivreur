<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SmsDailyAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $compteurJour,
        public int $seuilQuotidien,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "GoLivreur — Seuil SMS quotidien dépassé ({$this->compteurJour} / {$this->seuilQuotidien})",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: "<p>Le nombre quotidien de SMS envoyés ({$this->compteurJour}) a dépassé le seuil configuré ({$this->seuilQuotidien}).</p>",
        );
    }
}
