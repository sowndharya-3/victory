<?php

namespace App\Controllers;

use App\Auth\Jwt;
use App\Auth\Password;
use App\Database\Db;
use App\Http\ApiException;
use App\Validation\Validator;

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

        $stmt = $db->prepare(
            'INSERT INTO users (name, email, phone, password_hash) VALUES (:name, :email, :phone, :password_hash)'
        );
        $stmt->execute([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password_hash' => Password::hash($data['password']),
        ]);

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

        $db = Db::connection();
        $stmt = $db->prepare('SELECT id, name, email, password_hash FROM users WHERE email = :email');
        $stmt->execute(['email' => $data['email']]);
        $user = $stmt->fetch();

        if (!$user || !Password::verify($data['password'], $user['password_hash'])) {
            throw new ApiException('Invalid email or password', 401, 'invalid_credentials');
        }

        $token = Jwt::encode(['sub' => (int)$user['id'], 'role' => 'customer'], 7 * 24 * 60 * 60);

        echo json_encode([
            'token' => $token,
            'user' => ['id' => (int)$user['id'], 'name' => $user['name'], 'email' => $user['email']],
        ]);
    }
}
