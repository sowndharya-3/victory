<?php

namespace App\Mail;

use RuntimeException;

/**
 * Minimal SMTP client (no composer deps): SMTP_SECURE tls = STARTTLS, ssl = implicit TLS, none = plain (dev/mailpit only).
 * AUTH LOGIN when SMTP_USER is set. From is always MAIL_FROM; the visitor goes into Reply-To only.
 * Exception messages carry the server's reply line at most, never a command (AUTH exchanges hold credentials).
 */
final class SmtpMailer implements Mailer
{
    private const TIMEOUT = 10;
    /** @var resource|null */
    private $sock = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $secure,
        private string $user,
        private string $pass,
        private string $from,
        private string $to,
    ) {
    }

    public static function fromEnv(): self
    {
        $m = new self(
            (string) getenv('SMTP_HOST'),
            (int) (getenv('SMTP_PORT') ?: 587),
            strtolower((string) (getenv('SMTP_SECURE') ?: 'tls')),
            (string) getenv('SMTP_USER'),
            (string) getenv('SMTP_PASS'),
            (string) getenv('MAIL_FROM'),
            (string) getenv('CONTACT_TO_EMAIL'),
        );
        if ($m->host === '' || !self::address($m->from) || !self::address($m->to) || !in_array($m->secure, ['tls', 'ssl', 'none'], true)) {
            throw new RuntimeException('mail is not configured (SMTP_HOST / MAIL_FROM / CONTACT_TO_EMAIL / SMTP_SECURE)');
        }
        return $m;
    }

    public function send(string $subject, string $body, string $replyToEmail, string $replyToName): void
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $replyToName));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->from,
            'To: ' . $this->to,
            'Reply-To: ' . ($name === '' ? '' : self::encode($name) . ' ') . '<' . $replyToEmail . '>',
            'Subject: ' . self::encode((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $subject)),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (substr((string) strrchr($this->from, '@'), 1) ?: 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64', // no line can start with "." and no bare CR/LF reaches the DATA stream
        ];
        $data = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");

        try {
            $this->sock = @stream_socket_client(($this->secure === 'ssl' ? 'ssl' : 'tcp') . "://{$this->host}:{$this->port}", $errno, $errstr, self::TIMEOUT);
            if (!$this->sock) {
                throw new RuntimeException("smtp connect failed ($errno)");
            }
            stream_set_timeout($this->sock, self::TIMEOUT);
            $this->expect(220);
            $this->command('EHLO ' . (gethostname() ?: 'localhost'), 250);
            if ($this->secure === 'tls') {
                $this->command('STARTTLS', 220);
                if (!@stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('smtp starttls failed');
                }
                $this->command('EHLO ' . (gethostname() ?: 'localhost'), 250);
            }
            if ($this->user !== '') {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->user), 334);
                $this->command(base64_encode($this->pass), 235);
            }
            $this->command("MAIL FROM:<{$this->from}>", 250);
            $this->command("RCPT TO:<{$this->to}>", 250);
            $this->command('DATA', 354);
            fwrite($this->sock, $data . ".\r\n");
            $this->expect(250);
            @fwrite($this->sock, "QUIT\r\n");
        } finally {
            if (is_resource($this->sock)) {
                fclose($this->sock);
            }
            $this->sock = null;
        }
    }

    private function command(string $line, int $expect): void
    {
        if (@fwrite($this->sock, $line . "\r\n") === false) {
            throw new RuntimeException('smtp write failed');
        }
        $this->expect($expect);
    }

    private function expect(int $code): void
    {
        do {
            $line = fgets($this->sock, 1024);
            if ($line === false) {
                throw new RuntimeException('smtp connection lost or timed out');
            }
        } while (isset($line[3]) && $line[3] === '-');
        if ((int) substr($line, 0, 3) !== $code) {
            throw new RuntimeException('smtp unexpected reply: ' . substr(trim($line), 0, 120));
        }
    }

    private static function address(string $a): bool
    {
        return strlen($a) <= 254 && filter_var($a, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** RFC 2047 encoded words (each well under 75 bytes); plain printable ASCII is left alone. */
    private static function encode(string $s): string
    {
        if (preg_match('/^[\x20-\x7E]*$/', $s)) {
            return $s;
        }
        $words = [];
        foreach (mb_str_split($s, 15, 'UTF-8') as $part) {
            $words[] = '=?UTF-8?B?' . base64_encode($part) . '?=';
        }
        return implode("\r\n ", $words);
    }
}
