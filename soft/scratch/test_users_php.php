<?php
require_once __DIR__ . '/../config/config.php';
$p = db();
$users = $p->query("SELECT id, name, email, role, password FROM users")->fetchAll();
foreach ($users as $u) {
    $ok = password_verify('password', $u['password']) ? 'PASSWORD OK' : 'PASSWORD FAILED';
    echo "ID: {$u['id']} | {$u['role']} | {$u['name']} | {$u['email']} => $ok\n";
}
