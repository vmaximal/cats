<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use Maxim\Cats\Controller;
use Maxim\Cats\DB;

$controller = new Controller(new DB());

$controller->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
