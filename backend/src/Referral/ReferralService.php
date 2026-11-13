<?php

namespace App\Referral;

use App\Database\Db;

class ReferralService
{
    /** @return array{id:int,name:string,code:string}|null  null for unknown, inactive or self-referral; never throws */
    public static function resolve(?string $code, string $buyerEmail): ?array
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '') {
            return null;
        }
        try {
            $stmt = Db::connection()->prepare('SELECT id, name, referral_code, gmail FROM referral_partners WHERE referral_code = ? AND active = 1');
            $stmt->execute([$code]);
            $p = $stmt->fetch();
        } catch (\Throwable) {
            return null;
        }
        if (!$p || strcasecmp(trim($p['gmail']), trim($buyerEmail)) === 0) {
            return null;
        }
        return ['id' => (int) $p['id'], 'name' => $p['name'], 'code' => $p['referral_code']];
    }
}
