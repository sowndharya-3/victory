<?php

namespace App\Auth;

class Password
{
    // A fixed, valid bcrypt hash with no corresponding known plaintext — used
    // so a missing-user login always pays the same password_verify() cost as
    // a real user, closing the response-timing side channel that otherwise
    // lets an attacker enumerate valid emails/usernames by how fast a login
    // attempt fails (final-review finding).
    private const DUMMY_HASH = '$2y$10$7EqJtq98hPqEX7fNZaFWoOhi5CIjkE5/E0J6h7EBo2xWsZv.WRZQi';

    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public static function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }

    public static function verifyAgainstUserOrDummy(string $plain, ?string $hash): bool
    {
        return password_verify($plain, $hash ?? self::DUMMY_HASH);
    }
}
