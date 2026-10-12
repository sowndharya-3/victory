<?php

namespace App\Controllers;

use App\Database\Db;

class HealthController
{
    public function check(): void
    {
        Db::connection()->query('SELECT 1');
        echo json_encode(['status' => 'ok']);
    }
}
