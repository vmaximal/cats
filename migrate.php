<?php

declare(strict_types=1);

$db = new PDO('sqlite:' . __DIR__ . '/database.db', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$db->exec('PRAGMA foreign_keys = ON');
$db->exec(file_get_contents(__DIR__ . '/schema.sql'));

echo "done\n";
