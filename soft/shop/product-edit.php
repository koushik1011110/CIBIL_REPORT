<?php 
require_once __DIR__.'/../includes/layout.php';
require_once __DIR__.'/../includes/onboarding_db_init.php';
role('shop_admin', 'superadmin', 'staff');

$p = db();
$u = u();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header('Location: products.php');
    exit;
}

// Fetch Product
$stmt = $p->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    header('Location: products.php');
    exit;
}

// Fetch Existing Variants
$vStmt = $p->prepare('SELECT * FROM product_variants WHERE product_id = ? ORDER BY id ASC');
$vStmt->execute([$id]);
$existingVariants = $vStmt->fetchAll();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name         = trim($_POST['name'] ?? '');
        $brand        = trim($_POST['brand'] ?? '');
        $model        = trim($_POST['model'] ?? '');
        $sku          = trim($_POST['sku'] ?? '');
        $hsnCode      = trim($_POST['hsn_code'] ?? '8517');
        $category     = trim($_POST['category'] ?? 'Mobile');
        $sellingPrice = floatval($_POST['selling_price'] ?? 0);
        $stock        = (int)($_POST['stock'] ?? 0);
        $status       = $_POST['status'] === 'inactive' ? 'inactive' : 'active';
        
        if (empty($name)) {
            throw new Exception("Product Name is required.");
        }

        // Process variants
        $variantNames  = $_POST['variant_name'] ?? [];
        $variantPrices = $_POST['variant_price'] ?? [];
        $variantStocks = $_POST['variant_stock'] ?? [];
        $variantSkus   = $_POST['variant_sku'] ?? [];

        $hasVariants = false;
        $validVariants = [];
        $totalStock = 0;
        $lowestPrice = $sellingPrice;

        for ($i = 0; $i < count($variantNames); $i++) {
            $vName = trim($variantNames[$i] ?? '');
            $vPrice = floatval($variantPrices[$i] ?? 0);
            $vStock = max(0, (int)($variantStocks[$i] ?? 0));
            $vSku   = trim($variantSkus[$i] ?? '') ?: ($sku . '-V' . ($i + 1));

            if (!empty($vName) && $vPrice > 0) {
                $hasVariants = true;
                $validVariants[] = [
                    'name'  => $vName,
                    'price' => $vPrice,
                    'stock' => $vStock,
                    'sku'   => $vSku
                ];
                $totalStock += $vStock;
                if ($lowestPrice == 0 || $vPrice < $lowestPrice) {
                    $lowestPrice = $vPrice;
                }
            }
        }

        if ($hasVariants) {
            $sellingPrice = $lowestPrice;
            $stock = $totalStock;
        }

        if ($sellingPrice <= 0) {
            throw new Exception("Please specify a valid Selling Price for product or variants.");
        }

        // Update Base Product
        $upStmt = $p->prepare("
            UPDATE products 
            SET name = ?, brand = ?, model = ?, sku = ?, hsn_code = ?, category = ?, 
                selling_price = ?, stock = ?, status = ?
            WHERE id = ?
        ");
        $upStmt->execute([
            $name, $brand, $model, $sku, $hsnCode, $category, 
            $sellingPrice, $stock, $status, $id
        ]);
        
        // Re-sync Variants (Delete existing & re-insert updated)
        $p->prepare("DELETE FROM product_variants WHERE product_id = ?")->execute([$id]);

        if ($hasVariants) {
            $insVStmt = $p->prepare("INSERT INTO product_variants (product_id, variant_name, sku, price, stock, status) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($validVariants as $v) {
                $insVStmt->execute([
                    $id,
                    $v['name'],
                    $v['sku'],
                    $v['price'],
                    $v['stock'],
                    'active'
                ]);
            }
        }
        
        header('Location: products.php?msg=updated');
        exit;
    } catch (Exception $ex) {
        $err = $ex->getMessage();
    }
}

start('Edit Product & Variants');
?>

<div class="card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <div>
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff;">✏️ Edit Product Details</h3>
        <p class="muted" style="margin-top: 4px;">Update product metadata and configure RAM/Storage variants with custom pricing</p>
    </div>
    <a class="btn" style="background: rgba(255,255,255,0.1);" href="products.php">← Back to Products</a>
</div>

<?php if ($err): ?>
    <div style="background: rgba(239,68,68,0.15); border: 1px solid var(--danger); color: var(--danger); padding: 12px 16px; border-radius: 10px; margin-bottom: 20px;">
        ❌ <?=e($err)?>
    </div>
<?php endif; ?>

<form class="form" method="post" id="productEditForm">
    <!-- BASE PRODUCT DETAILS -->
    <div class="card" style="margin-bottom: 20px;">
        <h4 style="font-size: 1rem; font-weight: 800; color: var(--primary); margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <span>📱 Base Product Details</span>
        </h4>
        <div class="form-grid">
            <div class="field">
                <label>Product Name *</label>
                <input name="name" value="<?=e($product['name'])?>" placeholder="e.g. iPhone 15 Pro" required>
            </div>
            <div class="field">
                <label>Brand</label>
                <input name="brand" value="<?=e($product['brand'])?>" placeholder="e.g. Apple / Samsung">
            </div>
            <div class="field">
                <label>Model</label>
                <input name="model" value="<?=e($product['model'])?>" placeholder="e.g. 15 Pro">
            </div>
            <div class="field">
                <label>Base SKU / Serial No</label>
                <input name="sku" value="<?=e($product['sku'])?>" placeholder="e.g. SKU9081">
            </div>
            <div class="field">
                <label>HSN Code</label>
                <input name="hsn_code" value="<?=e($product['hsn_code'] ?: '8517')?>" placeholder="e.g. 8517">
            </div>
            <div class="field">
                <label>Category</label>
                <select name="category">
                    <option value="Mobile" <?=$product['category']==='Mobile'?'selected':''?>>Mobile</option>
                    <option value="Laptop" <?=$product['category']==='Laptop'?'selected':''?>>Laptop</option>
                    <option value="Computer" <?=$product['category']==='Computer'?'selected':''?>>Computer</option>
                    <option value="Tablet" <?=$product['category']==='Tablet'?'selected':''?>>Tablet</option>
                    <option value="AC" <?=$product['category']==='AC'?'selected':''?>>Air Conditioner (AC)</option>
                    <option value="TV" <?=$product['category']==='TV'?'selected':''?>>Smart TV</option>
                    <option value="Accessory" <?=$product['category']==='Accessory'?'selected':''?>>Accessory</option>
                    <option value="Other" <?=$product['category']==='Other'?'selected':''?>>Other</option>
                </select>
            </div>
            <div class="field">
                <label>Base Selling Price (₹) *</label>
                <input name="selling_price" id="basePriceInput" type="number" step="0.01" value="<?=e($product['selling_price'])?>" required>
            </div>
            <div class="field">
                <label>Base Stock Quantity *</label>
                <input name="stock" id="baseStockInput" type="number" value="<?=e($product['stock'])?>" required>
            </div>
            <div class="field">
                <label>Status</label>
                <select name="status">
                    <option value="active" <?=$product['status']==='active'?'selected':''?>>Active</option>
                    <option value="inactive" <?=$product['status']==='inactive'?'selected':''?>>Inactive</option>
                </select>
            </div>
        </div>
    </div>

    <!-- FLIPKART STYLE VARIANTS SECTION -->
    <div class="card" style="margin-bottom: 24px; background: linear-gradient(145deg, rgba(30, 41, 59, 0.9), rgba(15, 23, 42, 0.95)); border: 1px solid var(--primary-glow);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
            <div>
                <h4 style="font-size: 1.05rem; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
                    <span>🏷️ Product Variants (RAM / Storage / Color Options)</span>
                    <span class="badge badge-info" style="font-size: 0.72rem;"><?=count($existingVariants)?> Configured</span>
                </h4>
                <p class="muted" style="font-size: 0.8rem; margin-top: 2px;">Manage variant options with custom prices (e.g. 4GB/128GB @ ₹14,999, 8GB/256GB @ ₹21,999)</p>
            </div>
            <button type="button" class="btn" style="background: var(--primary); font-size: 0.82rem; padding: 6px 14px;" onclick="addVariantRow()">
                <i data-lucide="plus"></i> + Add Variant Row
            </button>
        </div>

        <!-- QUICK PRESETS -->
        <div style="margin-bottom: 14px; padding: 10px 14px; background: rgba(0,0,0,0.2); border-radius: 10px; border: 1px dashed var(--border-color); display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
            <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700;">⚡ Quick Add Presets:</span>
            <button type="button" class="btn" style="font-size: 0.72rem; padding: 4px 10px; background: rgba(255,255,255,0.08);" onclick="addPresetVariant('4GB RAM + 128GB Storage')">+ 4GB / 128GB</button>
            <button type="button" class="btn" style="font-size: 0.72rem; padding: 4px 10px; background: rgba(255,255,255,0.08);" onclick="addPresetVariant('6GB RAM + 128GB Storage')">+ 6GB / 128GB</button>
            <button type="button" class="btn" style="font-size: 0.72rem; padding: 4px 10px; background: rgba(255,255,255,0.08);" onclick="addPresetVariant('8GB RAM + 128GB Storage')">+ 8GB / 128GB</button>
            <button type="button" class="btn" style="font-size: 0.72rem; padding: 4px 10px; background: rgba(255,255,255,0.08);" onclick="addPresetVariant('8GB RAM + 256GB Storage')">+ 8GB / 256GB</button>
            <button type="button" class="btn" style="font-size: 0.72rem; padding: 4px 10px; background: rgba(255,255,255,0.08);" onclick="addPresetVariant('12GB RAM + 256GB Storage')">+ 12GB / 256GB</button>
        </div>

        <div style="overflow-x: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="background: rgba(15,23,42,0.8); color: var(--text-muted);">
                        <th style="padding: 10px;">Variant Specification *</th>
                        <th style="padding: 10px; width: 140px;">Variant Price (₹) *</th>
                        <th style="padding: 10px; width: 110px;">Stock</th>
                        <th style="padding: 10px; width: 140px;">Variant SKU</th>
                        <th style="padding: 10px; width: 40px;"></th>
                    </tr>
                </thead>
                <tbody id="variantsTableBody">
                    <!-- Pre-filled existing variants -->
                </tbody>
            </table>
        </div>
        <div id="noVariantsMsg" style="text-align: center; padding: 16px; color: var(--text-muted); font-size: 0.82rem; display: <?=empty($existingVariants)?'block':'none'?>;">
            No variants added yet. Click <strong>"+ Add Variant Row"</strong> or quick presets above to create variants.
        </div>
    </div>

    <!-- ACTION BUTTONS -->
    <div style="display: flex; gap: 12px;">
        <button type="submit" class="btn" style="padding: 12px 24px; font-size: 0.95rem; font-weight: 800;"><i data-lucide="check"></i> Save Product Changes</button>
        <a class="btn" style="background: rgba(255,255,255,0.1); padding: 12px 24px;" href="products.php">Cancel</a>
    </div>
</form>

<script>
let variantCount = 0;

function addVariantRow(name = '', price = '', stock = 10, sku = '') {
    variantCount++;
    document.getElementById('noVariantsMsg').style.display = 'none';
    const tbody = document.getElementById('variantsTableBody');
    const tr = document.createElement('tr');
    tr.id = 'variantRow_' + variantCount;
    tr.style.borderBottom = '1px solid var(--border-color)';
    
    tr.innerHTML = `
        <td style="padding: 8px;">
            <input type="text" name="variant_name[]" value="${escapeHtml(name)}" placeholder="e.g. 8GB RAM + 128GB Storage" required style="height: 38px; font-size: 0.85rem; border-radius: 8px;">
        </td>
        <td style="padding: 8px;">
            <input type="number" step="any" name="variant_price[]" value="${price}" placeholder="Price (₹)" required style="height: 38px; font-size: 0.85rem; border-radius: 8px; color: #10b981; font-weight: 700;">
        </td>
        <td style="padding: 8px;">
            <input type="number" name="variant_stock[]" value="${stock}" placeholder="Stock" required style="height: 38px; font-size: 0.85rem; border-radius: 8px;">
        </td>
        <td style="padding: 8px;">
            <input type="text" name="variant_sku[]" value="${escapeHtml(sku)}" placeholder="Auto SKU" style="height: 38px; font-size: 0.82rem; border-radius: 8px;">
        </td>
        <td style="padding: 8px; text-align: center;">
            <button type="button" onclick="removeVariantRow(${variantCount})" style="background: none; border: none; color: var(--danger); font-size: 1.2rem; cursor: pointer; font-weight: 800;">×</button>
        </td>
    `;
    tbody.appendChild(tr);
}

function escapeHtml(text) {
    if (!text) return '';
    return text.toString().replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function removeVariantRow(id) {
    const row = document.getElementById('variantRow_' + id);
    if (row) {
        row.remove();
    }
    const tbody = document.getElementById('variantsTableBody');
    if (tbody.children.length === 0) {
        document.getElementById('noVariantsMsg').style.display = 'block';
    }
}

function addPresetVariant(presetName) {
    const basePrice = document.getElementById('basePriceInput').value;
    addVariantRow(presetName, basePrice || '');
}

// Pre-fill existing variants on page load
document.addEventListener('DOMContentLoaded', () => {
    const existing = <?=json_encode($existingVariants)?>;
    if (existing && existing.length > 0) {
        existing.forEach(v => {
            addVariantRow(v.variant_name, v.price, v.stock, v.sku || '');
        });
    }
});
</script>

<?php render_end(); ?>
