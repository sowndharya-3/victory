<?php

namespace App\Controllers;

use App\Auth\Jwt;
use App\Auth\Password;
use App\Database\Db;
use App\Http\ApiException;
use App\Support\RateLimiter;
use App\Validation\Validator;
use PDOException;

class AuthController
{
    public function register(): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        Validator::required($data, ['name', 'email', 'password']);
        Validator::email($data['email']);
        Validator::minLength($data['password'], 8, 'password');

        $db = Db::connection();

        $stmt = $db->prepare('SELECT id FROM users WHERE email = :email');
        $stmt->execute(['email' => $data['email']]);
        if ($stmt->fetch()) {
            throw new ApiException('Email already registered', 409, 'email_taken');
        }

        // The SELECT above catches the common case with a clean error; this
        // try/catch is the real guard against the race where two requests
        // both pass that SELECT for the same email before either INSERTs
        // (final-review finding — the plan's own Review Focus called out
        // exactly this risk and the SELECT-only version left it half-open).
        try {
            $stmt = $db->prepare(
                'INSERT INTO users (name, email, phone, password_hash) VALUES (:name, :email, :phone, :password_hash)'
            );
            $stmt->execute([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password_hash' => Password::hash($data['password']),
            ]);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') {
                throw new ApiException('Email already registered', 409, 'email_taken');
            }
            throw $e;
        }

        $userId = (int)$db->lastInsertId();
        $token = Jwt::encode(['sub' => $userId, 'role' => 'customer'], 7 * 24 * 60 * 60);

        http_response_code(201);
        echo json_encode([
            'token' => $token,
            'user' => ['id' => $userId, 'name' => $data['name'], 'email' => $data['email']],
        ]);
    }

    public function login(): void
    {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        Validator::required($data, ['email', 'password']);

        // Failed attempts only, per account (hashed normalised email, existing or not) and per IP.
        $account = sha1(strtolower(trim(is_string($data['email']) ? $data['email'] : '')));
        $user = RateLimiter::failuresOnly(
            ['login-user:' . $account => 10, 'login-ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') => 30],
            900,
            function () use ($data) {
                $stmt = Db::connection()->prepare('SELECT id, name, email, password_hash FROM users WHERE email = :email');
                $stmt->execute(['email' => $data['email']]);
                $user = $stmt->fetch();
                $passwordMatches = Password::verifyAgainstUserOrDummy($data['password'], $user['password_hash'] ?? null);
                if (!$user || !$passwordMatches) {
                    throw new ApiException('Invalid email or password', 401, 'invalid_credentials');
                }
                return $user;
            }
        );

        $token = Jwt::encode(['sub' => (int)$user['id'], 'role' => 'customer'], 7 * 24 * 60 * 60);

        echo json_encode([
            'token' => $token,
            'user' => ['id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email']],
        ]);
    }
}
