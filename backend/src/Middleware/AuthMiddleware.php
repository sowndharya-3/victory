<?php

namespace App\Middleware;

use App\Auth\Jwt;
use App\Http\ApiException;

class AuthMiddleware
{
    public static function requireRole(array $allowedRoles): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/', $header, $matches)) {
            throw new ApiException('Missing or malformed Authorization header', 401, 'unauthorized');
        }

        $payload = Jwt::decode($matches[1]);

        if (!in_array($payload['role'] ?? null, $allowedRoles, true)) {
            throw new ApiException('Insufficient permissions', 403, 'forbidden');
        }

        return $payload;
    }
}
