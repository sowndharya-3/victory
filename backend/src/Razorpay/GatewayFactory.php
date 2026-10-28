<?php

namespace App\Razorpay;

class GatewayFactory
{
    public static function make(): Gateway
    {
        $cfg = Config::load();
        return $cfg['mode'] === 'fake' ? new FakeGateway() : new RazorpayGateway($cfg['keyId'], $cfg['keySecret']);
    }
}
