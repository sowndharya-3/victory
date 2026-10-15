<?php

namespace App\Controllers;

use App\Database\Db;
use App\Http\ApiException;
use App\Middleware\AuthMiddleware;

class MeController
{
    public function show(): void
    {
        $payload = AuthMiddleware::requireRole(['customer', 'admin']);
        $db = Db::connection();

        if ($payload['role'] === 'admin') {
            $stmt = $db->prepare('SELECT id, username FROM admins WHERE id = :id');
            $stmt->execute(['id' => $payload['sub']]);
            $admin = $stmt->fetch();
            if (!$admin) {
                throw new ApiException('Admin not found', 404, 'not_found');
            }
            echo json_encode(['role' => 'admin', 'id' => (int)$admin['id'], 'username' => $admin['username']]);
            return;
        }

        $stmt = $db->prepare('SELECT id, name, email, phone FROM users WHERE id = :id');
        $stmt->execute(['id' => $payload['sub']]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new ApiException('User not found', 404, 'not_found');
        }
        echo json_encode([
            'role' => 'customer',
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'phone' => $user['phone'],
        ]);
    }
}
