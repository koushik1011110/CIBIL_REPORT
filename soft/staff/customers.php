<?php 
require_once __DIR__.'/../includes/layout.php';
role('staff');
$p = db();
$q = $p->prepare('SELECT * FROM customers WHERE shop_id=? ORDER BY id DESC');
$q->execute([(int)u()['shop_id']]);
$rows = $q->fetchAll();

start('Customers');
?>
<div class="card" style="display:flex; justify-content:space-between; align-items:center;">
    <div>
        <h3 style="margin:0; font-size:1.1rem; color:#fff;">Customer Management</h3>
        <p class="muted" style="margin-top:2px;">Track registered customers & their portal login credentials</p>
    </div>
    <a class="btn" href="customer-create.php">+ Add Customer</a>
</div>
<br>
<div class="card" style="padding:0; overflow-x:auto;">
    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <thead>
            <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                <th style="padding:12px;">Name</th>
                <th style="padding:12px;">Mobile / Login User</th>
                <th style="padding:12px;">Email</th>
                <th style="padding:12px;">PAN</th>
                <th style="padding:12px;">Portal Login Pass</th>
                <th style="padding:12px;">Score</th>
                <th style="padding:12px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($rows)): ?>
                <tr><td colspan="7" style="text-align:center; padding:16px;" class="muted">No customers registered yet.</td></tr>
            <?php else: ?>
                <?php foreach($rows as $r): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding:12px;"><strong><?=e($r['name'])?></strong></td>
                        <td style="padding:12px;"><strong style="color:#60a5fa;"><?=e($r['mobile'])?></strong></td>
                        <td style="padding:12px;"><?=e($r['email'] ?: '-')?></td>
                        <td style="padding:12px;"><?=e($r['pan'] ?: '-')?></td>
                        <td style="padding:12px;"><code style="background:rgba(59,130,246,0.15); padding:4px 8px; border-radius:6px; color:#fff;">123456</code></td>
                        <td style="padding:12px;"><?=e($r['credit_score'] ?: '-')?></td>
                        <td style="padding:12px;">
                            <a class="btn" style="padding:4px 10px; font-size:0.8rem; background:var(--primary);" href="customer-edit.php?id=<?=$r['id']?>">✏️ Edit</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php render_end(); ?>
