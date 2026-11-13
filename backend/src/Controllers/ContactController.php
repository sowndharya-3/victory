<?php

namespace App\Controllers;

use App\Http\ApiException;
use App\Mail\SmtpMailer;
use App\Support\ClientIp;
use App\Support\RateLimiter;

/** Public contact form: validated, rate limited per client IP, mailed to CONTACT_TO_EMAIL. Nothing is stored. */
class ContactController
{
    private const MAX_BODY = 65536;
    private const LIMIT = 5;
    private const WINDOW = 3600;

    public function submit(): void
    {
        $in = fopen('php://input', 'rb');
        $raw = (string) stream_get_contents($in, self::MAX_BODY + 1);
        fclose($in);
        if (strlen($raw) > self::MAX_BODY) {
            throw new ApiException('Request body too large', 413, 'payload_too_large');
        }
        $data = json_decode($raw, true);
        try {
            self::handle(is_array($data) ? $data : [], fn () => SmtpMailer::fromEnv(), ClientIp::bucket());
        } catch (ApiException $e) {
            if ($e->errorCode === 'rate_limited') {
                header('Retry-After: ' . self::WINDOW);
            }
            throw $e;
        }
        echo json_encode(['ok' => true]);
    }

    /**
     * @param callable():\App\Mail\Mailer $mailer built only when a message is really sent (a misconfigured server still answers 502, never 500)
     * @throws ApiException 400 validation_error | 429 rate_limited | 502 email_failed
     */
    public static function handle(array $data, callable $mailer, string $ipBucket): void
    {
        // Honeypot: a real visitor never fills the hidden "website" field. Answer like a success, send nothing.
        if (isset($data['website']) && (!is_string($data['website']) || trim($data['website']) !== '')) {
            return;
        }
        $v = self::validate($data);
        // Counted after validation: typo fixes are free, only well-formed submissions use the budget.
        RateLimiter::hit('contact:' . $ipBucket, self::LIMIT, self::WINDOW);

        $subject = '[Website enquiry] ' . ($v['subject'] !== '' ? $v['subject'] : 'New message') . ' - ' . $v['name'];
        $body = "New enquiry from the website contact form\n\nName: {$v['name']}\nEmail: {$v['email']}\nPhone: " . ($v['phone'] ?: '-')
            . "\nSubject: " . ($v['subject'] ?: '-') . "\n\n{$v['message']}\n";
        try {
            $mailer()->send($subject, $body, $v['email'], $v['name']);
        } catch (\Throwable $e) {
            // Class + reason only (no addresses, body or credentials): the SMTP client never puts those in a message.
            error_log('contact: email delivery failed (' . get_class($e) . ': ' . $e->getMessage() . ')');
            throw new ApiException('Could not send your message. Please try again later or email us directly.', 502, 'email_failed');
        }
    }

    /** @return array{name:string,email:string,phone:string,subject:string,message:string} header fields have every control char removed */
    public static function validate(array $d): array
    {
        $errors = [];
        $text = function (string $key, bool $required, int $min, int $max, bool $multiline = false) use ($d, &$errors): string {
            $raw = $d[$key] ?? '';
            if (!is_string($raw)) {
                $errors[$key] = 'Must be text';
                return '';
            }
            // Header fields: CR/LF and any other control char become a space (blocks header injection); the message keeps \n and \t.
            $raw = str_replace("\r\n", "\n", $raw);
            $s = trim((string) ($multiline ? preg_replace('/[^\P{C}\n\t]+/u', '', $raw) : preg_replace('/\p{C}+/u', ' ', $raw)));
            if ($s === '' && !$required) {
                return '';
            }
            $len = mb_strlen($s);
            if ($len < $min || $len > $max) {
                $errors[$key] = "Must be $min-$max characters";
            }
            return $s;
        };
        $name = $text('name', true, 2, 100);
        $message = $text('message', true, 10, 5000, true);
        $subject = $text('subject', false, 1, 150);
        $email = $text('email', true, 3, 254);
        if (!isset($errors['email']) && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Enter a valid email address';
        }
        $phone = $text('phone', false, 7, 20);
        if ($phone !== '' && !isset($errors['phone']) && !preg_match('/^[0-9+ ]{7,20}$/', $phone)) {
            $errors['phone'] = 'Use digits, + and spaces only (7-20 characters)';
        }
        if ($errors) {
            throw new ApiException('Validation failed', 400, 'validation_error', $errors);
        }
        return compact('name', 'email', 'phone', 'subject', 'message');
    }
}
