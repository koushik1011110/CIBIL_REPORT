<?php
require_once __DIR__ . '/../config/config.php';
$p = db();
foreach(['finance_applications','emi_schedules','payments','shops','users','customers'] as $t){
    echo "=== $t ===\n";
    $cols = $p->query("DESCRIBE $t")->fetchAll(PDO::FETCH_ASSOC);
    foreach($cols as $c){
        echo "  {$c['Field']} ({$c['Type']})\n";
    }
}
