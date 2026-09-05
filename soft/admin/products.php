<?php 
require_once __DIR__.'/../includes/layout.php';
role('superadmin');

$p = db();

// Handle Product Deletion with Confirmation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_product') {
    $delId = (int)$_POST['product_id'];
    if ($delId > 0) {
        $p->prepare('DELETE FROM products WHERE id = ?')->execute([$delId]);
        header('Location: products.php?msg=deleted');
        exit;
    }
}

$q = $p->query("
    SELECT p.*, s.name as shop_name,
        (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) as variant_count,
        (SELECT GROUP_CONCAT(CONCAT(v.variant_name, ' (₹', FORMAT(v.price, 0), ')') SEPARATOR ' | ') FROM product_variants v WHERE v.product_id = p.id) as variant_list
    FROM products p 
    LEFT JOIN shops s ON s.id=p.shop_id 
    ORDER BY p.id DESC
");
$rows = $q->fetchAll();

start('All Products Catalog (Admin)');
?>

<?php if (isset($_GET['msg'])): ?>
    <?php if ($_GET['msg'] === 'updated'): ?>
        <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 12px 16px; border-radius: 10px; margin-bottom: 20px;">
            ✓ Product updated successfully!
        </div>
    <?php elseif ($_GET['msg'] === 'deleted'): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 12px 16px; border-radius: 10px; margin-bottom: 20px;">
            🗑️ Product deleted successfully.
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff;">System Product Inventory</h3>
        <p class="muted" style="margin-top: 4px;">Super admin oversight & control over all store products and Flipkart-style RAM/Storage variants</p>
    </div>
    <a class="btn" href="product-create.php"><i data-lucide="plus-circle"></i> Add New Product</a>
</div>

<div class="card" style="padding: 0; overflow-x: auto;">
    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <thead>
            <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                <th style="padding: 12px;">#</th>
                <th style="padding: 12px;">Shop</th>
                <th style="padding: 12px;">Product Name & Variants</th>
                <th style="padding: 12px;">Brand / Category</th>
                <th style="padding: 12px;">Selling Price</th>
                <th style="padding: 12px;">Stock</th>
                <th style="padding: 12px;">Status</th>
                <th style="padding: 12px; text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($rows)): ?>
                <tr><td colspan="8" style="text-align: center; padding: 20px;" class="muted">No products found in system.</td></tr>
            <?php else: ?>
                <?php foreach($rows as $idx => $r): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 12px;"><?=$idx + 1?></td>
                        <td style="padding: 12px;"><strong><?=e($r['shop_name'] ?: 'Demo Store')?></strong></td>
                        <td style="padding: 12px;">
                            <strong><?=e($r['name'])?></strong>
                            <?php if(!empty($r['model'])): ?>
                                <span style="font-size:0.75rem; color:var(--text-muted); display:block;"><?=e($r['model'])?></span>
                            <?php endif; ?>
                            <?php if (!empty($r['variant_count']) && $r['variant_count'] > 0): ?>
                                <div style="margin-top: 4px;">
                                    <span class="badge" style="font-size: 0.72rem; background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4);" title="<?=e($r['variant_list'])?>">
                                        🏷️ <?=e($r['variant_count'])?> Variants Configured
                                    </span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px;"><?=e($r['brand'] ?: 'General')?><br><span class="badge badge-info"><?=e($r['category'] ?: 'Mobile')?></span></td>
                        <td style="padding: 12px;"><strong style="color:var(--primary);"><?=money($r['selling_price'])?></strong></td>
                        <td style="padding: 12px;"><strong><?=e($r['stock'])?></strong> units</td>
                        <td style="padding: 12px;"><span class="badge <?=$r['status']==='active'?'badge-success':'badge-warning'?>"><?=e($r['status'])?></span></td>
                        <td style="padding: 12px; text-align: right;">
                            <div style="display: flex; gap: 8px; justify-content: flex-end; align-items: center;">
                                <a href="product-edit.php?id=<?=$r['id']?>" class="btn" style="padding: 4px 10px; font-size: 0.78rem; background: var(--primary);">
                                    ✏️ Edit
                                </a>
                                <form method="POST" onsubmit="return confirm('⚠️ Are you sure you want to delete product \'<?=e(addslashes($r['name']))?>\'? This action cannot be undone.');" style="display: inline;">
                                    <input type="hidden" name="action" value="delete_product">
                                    <input type="hidden" name="product_id" value="<?=$r['id']?>">
                                    <button type="submit" class="btn" style="padding: 4px 10px; font-size: 0.78rem; background: rgba(239,68,68,0.2); color: var(--danger); border: 1px solid var(--danger);">
                                        🗑️ Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php render_end(); ?>
