<?php
require_once __DIR__ . '/../config/config.php';
$p = db();
$tables = $p->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $count = $p->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo str_pad($t, 35) . ": " . $count . "\n";
}
