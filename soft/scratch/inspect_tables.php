<?php
require_once __DIR__ . '/../config/config.php';
$p = db();
foreach (['gateway_orders', 'reviews', 'settings', 'finance_rules'] as $t) {
    echo "=== $t ===\n";
    try {
        $cols = $p->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cols as $c) {
            echo "  {$c['Field']} ({$c['Type']})\n";
        }
    } catch (Exception $e) {
        echo "  Table missing: " . $e->getMessage() . "\n";
    }
}
