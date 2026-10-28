<?php

namespace App\Razorpay;

use RuntimeException;

/** Lazy: env is read and validated only when load() is called, never at boot. */
class Config
{
    /** @return array{mode:string,keyId:string,keySecret:string,webhookSecret:string} */
    public static function load(): array
    {
        $mode = getenv('RAZORPAY_GATEWAY') ?: 'live';
        if (!in_array($mode, ['live', 'fake'], true)) {
            throw new RuntimeException('RAZORPAY_GATEWAY must be "live" or "fake"');
        }
        if ($mode === 'fake' && getenv('APP_ENV') === 'production') {
            throw new RuntimeException('RAZORPAY_GATEWAY=fake is forbidden when APP_ENV=production');
        }

        $cfg = [
            'mode' => $mode,
            'keyId' => (string) getenv('RAZORPAY_KEY_ID'),
            'keySecret' => (string) getenv('RAZORPAY_KEY_SECRET'),
            'webhookSecret' => (string) getenv('RAZORPAY_WEBHOOK_SECRET'),
        ];
        if ($mode === 'live') {
            foreach (['keyId' => 'RAZORPAY_KEY_ID', 'keySecret' => 'RAZORPAY_KEY_SECRET', 'webhookSecret' => 'RAZORPAY_WEBHOOK_SECRET'] as $k => $env) {
                if ($cfg[$k] === '' || stripos($cfg[$k], 'changeme') !== false) {
                    throw new RuntimeException("{$env} must be set to a real value when RAZORPAY_GATEWAY=live");
                }
            }
        }
        return $cfg;
    }
}
