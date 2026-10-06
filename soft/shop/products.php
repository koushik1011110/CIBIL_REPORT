<?php 
require_once __DIR__.'/../includes/layout.php';
role('shop_admin', 'superadmin', 'staff');

$p = db();
$u = u();
$isSuperAdmin = ($u['role'] === 'superadmin');

// Fetch master catalog active products for store owners to select
$q = $p->query("
    SELECT p.*, 
        (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) as variant_count,
        (SELECT GROUP_CONCAT(CONCAT(v.variant_name, ' (₹', FORMAT(v.price, 0), ')') SEPARATOR ' | ') FROM product_variants v WHERE v.product_id = p.id) as variant_list
    FROM products p 
    WHERE p.status = 'active'
    ORDER BY p.id DESC
");
$rows = $q->fetchAll();

start('Master Products Catalog');
?>

<?php if (isset($_GET['err']) && $_GET['err'] === 'admin_only'): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 12px 16px; border-radius: 10px; margin-bottom: 20px;">
        ⚠️ Access Restricted: Products are managed centrally by Super Admin. Store owners can select products for billing and financing.
    </div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'updated'): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 12px 16px; border-radius: 10px; margin-bottom: 20px;">
        ✓ Product updated successfully!
    </div>
<?php endif; ?>

<div class="card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div>
        <div style="display: flex; align-items: center; gap: 8px;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff;">📦 Master Products Catalog</h3>
            <span class="badge" style="background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); font-size: 0.72rem;">Super Admin Managed</span>
        </div>
        <p class="muted" style="margin-top: 4px;">Centralized products & variants. Select any product below to create POS billing invoices or customer EMI financing.</p>
    </div>
    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
        <a class="btn" href="pos.php" style="background: var(--primary);"><i data-lucide="shopping-cart"></i> Open POS Terminal</a>
        <a class="btn" href="credit-check.php" style="background: rgba(255,255,255,0.08);"><i data-lucide="shield-check"></i> Customer EMI Financing</a>
        <?php if ($isSuperAdmin): ?>
            <a class="btn" href="../admin/product-create.php" style="background: rgba(16, 185, 129, 0.2); color: var(--success); border: 1px solid var(--success);"><i data-lucide="plus-circle"></i> Add (Super Admin)</a>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="padding: 0; overflow-x: auto;">
    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <thead>
            <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                <th style="padding: 12px;">#</th>
                <th style="padding: 12px;">Product Name & Variants</th>
                <th style="padding: 12px;">Brand / Category</th>
                <th style="padding: 12px;">SKU & HSN</th>
                <th style="padding: 12px;">Selling Price</th>
                <th style="padding: 12px;">Status</th>
                <th style="padding: 12px; text-align: right;">Select Product</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($rows)): ?>
                <tr><td colspan="7" style="text-align: center; padding: 20px;" class="muted">No products available in the master catalog. Please contact Super Admin.</td></tr>
            <?php else: ?>
                <?php foreach($rows as $idx => $r): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 12px;"><?=$idx + 1?></td>
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
                        <td style="padding: 12px;"><span style="font-family:monospace;"><?=e($r['sku'])?></span><br><span style="font-size:0.72rem; color:var(--text-muted);">HSN: <?=e($r['hsn_code'] ?: '8517')?></span></td>
                        <td style="padding: 12px;"><strong style="color:var(--primary);"><?=money($r['selling_price'])?></strong></td>
                        <td style="padding: 12px;"><span class="badge <?=$r['status']==='active'?'badge-success':'badge-warning'?>"><?=e($r['status'])?></span></td>
                        <td style="padding: 12px; text-align: right;">
                            <div style="display: flex; gap: 8px; justify-content: flex-end; align-items: center;">
                                <a href="pos.php" class="btn" style="padding: 5px 12px; font-size: 0.78rem; background: var(--primary);">
                                    <i data-lucide="shopping-cart"></i> Bill in POS
                                </a>
                                <a href="credit-check.php" class="btn" style="padding: 5px 12px; font-size: 0.78rem; background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3);">
                                    <i data-lucide="shield-check"></i> Finance
                                </a>
                                <?php if ($isSuperAdmin): ?>
                                    <a href="../admin/product-edit.php?id=<?=$r['id']?>" class="btn" style="padding: 5px 10px; font-size: 0.78rem; background: rgba(255,255,255,0.1);">
                                        ✏️ Edit
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<?php render_end(); ?>
