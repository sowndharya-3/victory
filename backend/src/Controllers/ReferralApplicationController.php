<?php

namespace App\Controllers;

use App\Database\Db;
use App\Http\ApiException;
use App\Orders\AddressValidator;
use App\Support\RateLimiter;

/** Public partner sign-up. Stored in referral_applications; an admin approval creates the referral_partners row. */
class ReferralApplicationController
{
    public const STATUSES = ['Student', 'Scholarship', 'Faculty', 'Institution'];
    /** JSON key => [column, max length, required]; partner edits use the subset that exists on referral_partners. */
    private const FIELDS = [
        'name' => ['name', 150, true],
        'qualification' => ['qualification', 255, false],
        'place' => ['place', 100, false],
        'district' => ['district', 100, false],
        'status' => ['status', 20, true],
        'phone' => ['phone', 20, true],
        'whatsapp' => ['whatsapp', 20, true],
        'upiId' => ['upi_id', 100, false],
        'gmail' => ['gmail', 190, true],
    ];
    public const PARTNER_FIELDS = ['name', 'phone', 'whatsapp', 'upiId', 'gmail'];
    private const MAX_BODY = 16384;

    public function create(): void
    {
        // Public endpoint: cap the body before decoding (a real application is well under 2 KB).
        $in = fopen('php://input', 'rb');
        $raw = (string) stream_get_contents($in, self::MAX_BODY + 1);
        fclose($in);
        if (strlen($raw) > self::MAX_BODY) {
            throw new ApiException('Request body too large', 413, 'payload_too_large');
        }
        $data = json_decode($raw, true);
        $row = self::validate(is_array($data) ? $data : [], array_keys(self::FIELDS), false);
        // Counted after validation: typo fixes are free, only well-formed submissions use the 5/hour budget.
        // REMOTE_ADDR is nginx's peer address (no X-Forwarded-For trust), so it cannot be spoofed by a header.
        RateLimiter::hit('referral-apply:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 3600);

        $db = Db::connection();
        // Fast path; uq_open_application (migration 008) is the real guard against simultaneous submissions.
        $dup = $db->prepare('SELECT 1 FROM referral_applications WHERE open_gmail = ? LIMIT 1');
        $dup->execute([$row['gmail']]);
        $duplicate = new ApiException('An application with this Gmail is already pending or approved', 409, 'duplicate_application');
        if ($dup->fetchColumn()) {
            throw $duplicate;
        }
        $cols = array_keys($row);
        try {
            $db->prepare('INSERT INTO referral_applications (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')')
                ->execute(array_values($row));
        } catch (\PDOException $e) {
            throw Db::isDuplicateKey($e, 'uq_open_application') ? $duplicate : $e;
        }
        http_response_code(201);
        echo json_encode(['id' => (int) $db->lastInsertId(), 'applicationStatus' => 'pending']);
    }

    /**
     * Validates $keys of $data (all of them, or only those present when $partial) and returns column => value.
     * Unknown keys are rejected when $partial (edits must not touch referral_code, active, ...).
     * @return array<string, ?string>
     */
    public static function validate(array $data, array $keys, bool $partial): array
    {
        $out = [];
        $errors = [];
        if ($partial) {
            foreach (array_diff(array_keys($data), $keys) as $unknown) {
                $errors[(string) $unknown] = 'Not editable';
            }
        }
        foreach ($keys as $key) {
            [$col, $max, $required] = self::FIELDS[$key];
            if ($partial && !array_key_exists($key, $data)) {
                continue;
            }
            $v = $data[$key] ?? null;
            if ($v !== null && !is_string($v)) {
                $errors[$key] = 'Must be text';
                continue;
            }
            $v = AdminOrderController::clean((string) $v);
            if ($v === null) {
                $errors[$key] = 'Must be valid text without control characters or line breaks';
                continue;
            }
            if ($v === '') {
                if ($required) {
                    $errors[$key] = 'Required';
                } else {
                    $out[$col] = null;
                }
                continue;
            }
            if (mb_strlen($v) > $max) {
                $errors[$key] = "Must be at most $max characters";
                continue;
            }
            switch ($key) {
                case 'status':
                    if (!in_array($v, self::STATUSES, true)) {
                        $errors[$key] = 'Must be one of ' . implode(', ', self::STATUSES);
                    }
                    break;
                case 'phone':
                case 'whatsapp':
                    $v = AddressValidator::mobile($v);
                    if ($v === null) {
                        $errors[$key] = 'Must be a valid 10-digit Indian mobile number';
                    }
                    break;
                case 'upiId':
                    // A UPI VPA (name@bank) or the 10-digit mobile linked to GPay / PhonePe.
                    if (preg_match('/^[A-Za-z0-9._-]{2,64}@[A-Za-z][A-Za-z0-9]{1,31}\z/', $v)) {
                        break;
                    }
                    $v = AddressValidator::mobile($v);
                    if ($v === null) {
                        $errors[$key] = 'Must be a UPI ID like name@bank or a 10-digit mobile number';
                    }
                    break;
                case 'gmail':
                    $v = strtolower($v);
                    if (!filter_var($v, FILTER_VALIDATE_EMAIL)) {
                        $errors[$key] = 'Must be a valid email address';
                    }
                    break;
            }
            $out[$col] = $v;
        }
        if (!$errors && $partial && !$out) {
            $errors['body'] = 'Nothing to update; editable: ' . implode(', ', $keys);
        }
        if ($errors) {
            throw new ApiException('Validation failed', 400, 'validation_error', $errors);
        }
        return $out;
    }
}
