<?php

namespace App\Mail;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StaffInvitation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Invitation $invitation,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You have been invited to '.$this->invitation->tenant->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.staff-invitation',
            with: [
                'acceptUrl' => route('invitations.accept', $this->invitation->token),
                'workspace' => $this->invitation->tenant->name,
                'inviter' => $this->invitation->inviter->name,
                'expires' => $this->invitation->expires_at,
            ],
        );
    }
}
