<?php

namespace App\Middleware;

use App\Auth\Jwt;
use App\Http\ApiException;

class AuthMiddleware
{
    public static function requireRole(array $allowedRoles): array
    {
        $header = self::authorizationHeader($_SERVER, self::requestHeaders());
        if (!preg_match('/^Bearer\s+(.+)$/', $header, $matches)) {
            throw new ApiException('Missing or malformed Authorization header', 401, 'unauthorized');
        }

        $payload = Jwt::decode($matches[1]);

        if (!in_array($payload['role'] ?? null, $allowedRoles, true)) {
            throw new ApiException('Insufficient permissions', 403, 'forbidden');
        }

        return $payload;
    }

    /**
     * The raw Authorization header wherever the server put it: nginx/FPM set HTTP_AUTHORIZATION; Apache with
     * PHP-FPM/CGI strips it unless .htaccess copies it, and after a rewrite it arrives as
     * REDIRECT_HTTP_AUTHORIZATION; mod_php exposes it only via apache_request_headers() (name case varies).
     */
    public static function authorizationHeader(array $server, array $headers): string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (is_string($server[$key] ?? null) && $server[$key] !== '') {
                return $server[$key];
            }
        }
        foreach ($headers as $name => $value) {
            if (is_string($name) && strcasecmp($name, 'Authorization') === 0 && is_string($value)) {
                return $value;
            }
        }
        return '';
    }

    private static function requestHeaders(): array
    {
        if (function_exists('apache_request_headers')) {
            return apache_request_headers() ?: [];
        }
        return function_exists('getallheaders') ? (getallheaders() ?: []) : [];
    }
}
