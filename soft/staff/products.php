<?php 
require_once __DIR__.'/../includes/layout.php';
role('staff');

$p = db();
$sid = (int)(u()['shop_id'] ?: 1);
$q = $p->prepare('SELECT * FROM products WHERE shop_id=? ORDER BY id DESC');
$q->execute([$sid]);
$rows = $q->fetchAll();

start('Products Catalog');
?>
<div class="card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff;">Shop Inventory Products</h3>
    </div>
    <a class="btn" href="../shop/product-create.php"><i data-lucide="plus-circle"></i> Add New Product</a>
</div>

<div class="card">
    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Product Name</th>
                <th>Brand / Category</th>
                <th>Selling Price</th>
                <th>Stock</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($rows)): ?>
                <tr><td colspan="6" style="text-align: center;">No products found.</td></tr>
            <?php else: ?>
                <?php foreach($rows as $idx => $r): ?>
                    <tr>
                        <td><?=$idx + 1?></td>
                        <td><strong><?=e($r['name'])?></strong></td>
                        <td><?=e($r['brand'])?> <span class="badge badge-info"><?=e($r['category'])?></span></td>
                        <td><strong style="color:var(--primary);"><?=money($r['selling_price'])?></strong></td>
                        <td><?=e($r['stock'])?> units</td>
                        <td><span class="badge <?=$r['status']==='active'?'badge-success':'badge-warning'?>"><?=e($r['status'])?></span></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php render_end(); ?>
