<?php

namespace App\Controllers;

use App\Auth\Jwt;
use App\Auth\Password;
use App\Database\Db;
use App\Http\ApiException;
use App\Support\RateLimiter;
use App\Validation\Validator;

class AdminController
{
    public function login(): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        Validator::required($data, ['username', 'password']);

        // Stricter than customer login; failed attempts only, per account (hashed, existing or not) and per IP.
        $account = sha1(strtolower(trim(is_string($data['username']) ? $data['username'] : '')));
        $admin = RateLimiter::failuresOnly(
            ['admin-login-user:' . $account => 5, 'admin-login-ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') => 15],
            900,
            function () use ($data) {
                $stmt = Db::connection()->prepare('SELECT id, username, password_hash FROM admins WHERE username = :username');
                $stmt->execute(['username' => $data['username']]);
                $admin = $stmt->fetch();
                $passwordMatches = Password::verifyAgainstUserOrDummy($data['password'], $admin['password_hash'] ?? null);
                if (!$admin || !$passwordMatches) {
                    throw new ApiException('Invalid username or password', 401, 'invalid_credentials');
                }
                return $admin;
            }
        );

        $token = Jwt::encode(['sub' => (int)$admin['id'], 'role' => 'admin'], 2 * 60 * 60);

        echo json_encode([
            'token' => $token,
            'admin' => ['id' => (int)$admin['id'], 'username' => $admin['username']],
        ]);
    }
}
