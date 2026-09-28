<?php

declare(strict_types=1);

require __DIR__ . '/src/DB.php';
require __DIR__ . '/src/Controller.php';

use Cat\Controller;
use Cat\DB;

$controller = new Controller(new DB());

$controller->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
