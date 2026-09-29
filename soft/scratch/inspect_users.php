<?php
require_once __DIR__ . '/../config/config.php';
$p = db();
echo "=== SHOPS ===\n";
$shops = $p->query("SELECT * FROM shops")->fetchAll();
foreach ($shops as $s) {
    echo "ID: {$s['id']} | Name: {$s['name']} | Phone: {$s['phone']} | Email: {$s['email']}\n";
}
echo "\n=== USERS ===\n";
$users = $p->query("SELECT id, shop_id, name, email, role, status FROM users")->fetchAll();
foreach ($users as $u) {
    echo "ID: {$u['id']} | Role: {$u['role']} | Name: {$u['name']} | Email: {$u['email']}\n";
}
