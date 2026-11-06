<?php

namespace App\Mail;

interface Mailer
{
    /** Sends one plain-text message to the configured admin address. Throws on any delivery failure. */
    public function send(string $subject, string $body, string $replyToEmail, string $replyToName): void;
}
