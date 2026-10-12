<?php

use App\Controllers\HealthController;

$router->get('/api/health', [new HealthController(), 'check']);
