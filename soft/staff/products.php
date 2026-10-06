<?php 
require_once __DIR__.'/../includes/layout.php';
role('staff');

$p = db();
$q = $p->query("SELECT * FROM products WHERE status='active' ORDER BY id DESC");
$rows = $q->fetchAll();

start('Master Products Catalog');
?>
<div class="card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff;">📦 Master Products Catalog</h3>
        <p class="muted" style="margin-top: 4px;">Products managed by Super Admin. Available for POS billing and financing.</p>
    </div>
    <div style="display: flex; gap: 8px;">
        <a class="btn" href="pos.php"><i data-lucide="shopping-cart"></i> POS Terminal</a>
        <a class="btn" style="background: rgba(255,255,255,0.08);" href="credit-check.php"><i data-lucide="shield-check"></i> Credit Check</a>
    </div>
</div>

<div class="card" style="padding: 0; overflow-x: auto;">
    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <thead>
            <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                <th style="padding: 12px;">#</th>
                <th style="padding: 12px;">Product Name</th>
                <th style="padding: 12px;">Brand / Category</th>
                <th style="padding: 12px;">Selling Price</th>
                <th style="padding: 12px;">Status</th>
                <th style="padding: 12px; text-align: right;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($rows)): ?>
                <tr><td colspan="6" style="text-align: center; padding: 20px;" class="muted">No products found.</td></tr>
            <?php else: ?>
                <?php foreach($rows as $idx => $r): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 12px;"><?=$idx + 1?></td>
                        <td style="padding: 12px;"><strong><?=e($r['name'])?></strong></td>
                        <td style="padding: 12px;"><?=e($r['brand'] ?: 'General')?> <span class="badge badge-info"><?=e($r['category'] ?: 'Mobile')?></span></td>
                        <td style="padding: 12px;"><strong style="color:var(--primary);"><?=money($r['selling_price'])?></strong></td>
                        <td style="padding: 12px;"><span class="badge <?=$r['status']==='active'?'badge-success':'badge-warning'?>"><?=e($r['status'])?></span></td>
                        <td style="padding: 12px; text-align: right;">
                            <a href="pos.php" class="btn" style="padding: 4px 10px; font-size: 0.78rem; background: var(--primary);">
                                <i data-lucide="shopping-cart"></i> Select in POS
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php render_end(); ?>
