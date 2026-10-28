<?php

namespace App\Controllers;

use App\Referral\ReferralService;
use App\Support\RateLimiter;

class ReferralController
{
    public function validate(string $code): void
    {
        RateLimiter::hit('referral-validate:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 30, 60);
        // Empty buyer email: no self-referral check at preview time (checked again at order creation).
        $partner = ReferralService::resolve(urldecode($code), '');
        if (!$partner) {
            echo json_encode(['valid' => false]);
            return;
        }
        echo json_encode(['valid' => true, 'partnerName' => mb_substr($partner['name'], 0, 1) . '***']);
    }
}
