<?php 
require_once __DIR__ . '/../includes/layout.php';
role('shop_admin');

$p = db();
$user = u();
$userId = (int)($user['id'] ?? 0);
$sid = (int)($user['shop_id'] ?? 0);

if ($sid <= 0 && $userId > 0) {
    $uStmt = $p->prepare('SELECT shop_id FROM users WHERE id = ?');
    $uStmt->execute([$userId]);
    $sid = (int)($uStmt->fetchColumn() ?: 0);
}

$stats = [
    ['Customers', (int)$p->query("SELECT COUNT(*) FROM customers WHERE shop_id = {$sid}")->fetchColumn()],
    ['Applications', (int)$p->query("SELECT COUNT(*) FROM finance_applications WHERE shop_id = {$sid}")->fetchColumn()],
    ['Finance', money($p->query("SELECT COALESCE(SUM(finance_amount), 0) FROM finance_applications WHERE shop_id = {$sid}")->fetchColumn())],
    ['Collections', money($p->query("SELECT COALESCE(SUM(p.amount), 0) FROM payments p JOIN finance_applications f ON f.id = p.finance_id WHERE f.shop_id = {$sid}")->fetchColumn())]
];

start('Shop Dashboard');
?>
<div class="grid">
    <?php foreach($stats as $s): ?>
        <div class="card">
            <div class="muted"><?=e($s[0])?></div>
            <div class="metric"><?=e($s[1])?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php render_end(); ?>
