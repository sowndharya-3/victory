<?php

namespace App\Razorpay;

use App\Support\Env;
use RuntimeException;

/**
 * Lazy: env is read and validated only when load() is called, never at boot.
 * Fails closed: the fake gateway (which accepts forged signatures) only runs when APP_ENV is exactly one of
 * Env::DEV_VALUES; outside dev the live secrets must be real. Messages name variables, never values.
 */
class Config
{
    /** @return array{mode:string,keyId:string,keySecret:string,webhookSecret:string} */
    public static function load(): array
    {
        $mode = getenv('RAZORPAY_GATEWAY') ?: 'live';
        if (!in_array($mode, ['live', 'fake'], true)) {
            throw new RuntimeException('RAZORPAY_GATEWAY must be "live" or "fake"');
        }
        $dev = Env::isDev();
        if ($mode === 'fake' && !$dev) {
            throw new RuntimeException('RAZORPAY_GATEWAY=fake is only allowed when APP_ENV is one of ' . implode('|', Env::DEV_VALUES));
        }

        $cfg = [
            'mode' => $mode,
            'keyId' => (string) getenv('RAZORPAY_KEY_ID'),
            'keySecret' => (string) getenv('RAZORPAY_KEY_SECRET'),
            'webhookSecret' => (string) getenv('RAZORPAY_WEBHOOK_SECRET'),
        ];
        if ($mode === 'live') {
            // Outside dev: Razorpay key secrets are 24 chars; the webhook secret is ours to choose, so demand 32.
            foreach (['keySecret' => ['RAZORPAY_KEY_SECRET', $dev ? 1 : 20], 'webhookSecret' => ['RAZORPAY_WEBHOOK_SECRET', $dev ? 1 : 32]] as $k => [$env, $min]) {
                if (Env::weakSecret($cfg[$k], $min)) {
                    throw new RuntimeException("$env must be a real value of at least $min characters (no placeholder) when RAZORPAY_GATEWAY=live");
                }
            }
            if (!preg_match('/^rzp_(test|live)_[A-Za-z0-9]{6,}\z/', $cfg['keyId']) || Env::weakSecret($cfg['keyId'], 1)) {
                throw new RuntimeException('RAZORPAY_KEY_ID must be a real rzp_test_ or rzp_live_ key id when RAZORPAY_GATEWAY=live');
            }
        }
        return $cfg;
    }
}
