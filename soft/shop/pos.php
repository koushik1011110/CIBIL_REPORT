<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/onboarding_db_init.php';
role('shop_admin', 'superadmin', 'staff');

$p = db();
$u = u();
$shopId = (int)($u['shop_id'] ?? 0);

// If superadmin has no shop_id, fallback to first active shop or 1
if ($shopId === 0 && $u['role'] === 'superadmin') {
    $shopId = (int)($p->query("SELECT id FROM shops ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1);
}

// Fetch shop details for POS
$shopStmt = $p->prepare("SELECT * FROM shops WHERE id = ?");
$shopStmt->execute([$shopId]);
$shop = $shopStmt->fetch() ?: ['name' => 'Demo Store', 'gstin' => '', 'address' => ''];

$msg = '';
$err = '';

// Check POS Addon License Activation State
$isPosActivated = (get_setting('pos_addon_activated', '0') === '1');

// Handle POS API Key Verification (AJAX / Form)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_pos_api_key') {
    header('Content-Type: application/json');
    $apiKey = trim($_POST['pos_api_key'] ?? '');
    
    $validLicenseCode = 'KKWEBMART-PREMIUIM-ADDON-2022';
    
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid POS API Key / License Key.']);
        exit;
    }
    
    if (strtoupper($apiKey) !== strtoupper($validLicenseCode)) {
        echo json_encode(['success' => false, 'message' => '❌ Invalid POS API Key! Code does not match. Contact to developer for activate this feature.']);
        exit;
    }
    
    // Save setting permanently in database
    set_setting('pos_addon_activated', '1');
    set_setting('pos_addon_api_key', $validLicenseCode);
    
    echo json_encode([
        'success' => true, 
        'message' => '✓ POS Premium Addon Verified & Activated Successfully!'
    ]);
    exit;
}

// Process POS Sale Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_pos_sale') {
    try {
        if (!$isPosActivated) {
            throw new Exception("POS Premium Addon is not activated. Please verify POS API Key to enable GST billing.");
        }

        $customerName   = trim($_POST['customer_name'] ?? 'Walk-in Customer');
        $customerMobile = trim($_POST['customer_mobile'] ?? '');
        $customerGstin  = trim($_POST['customer_gstin'] ?? '');
        $taxType        = $_POST['tax_type'] === 'inter_state' ? 'inter_state' : 'intra_state';
        $paymentMethod  = trim($_POST['payment_method'] ?? 'cash');
        $discount       = floatval($_POST['discount'] ?? 0);
        $notes          = trim($_POST['notes'] ?? '');
        
        $itemsJson      = $_POST['cart_items'] ?? '[]';
        $cartItems      = json_decode($itemsJson, true);
        
        if (empty($cartItems)) {
            throw new Exception("Cart is empty. Please add at least one product.");
        }
        
        // Find or create customer
        $customerId = null;
        if (!empty($customerMobile)) {
            $cStmt = $p->prepare("SELECT id FROM customers WHERE mobile = ? LIMIT 1");
            $cStmt->execute([$customerMobile]);
            $cRow = $cStmt->fetch();
            if ($cRow) {
                $customerId = (int)$cRow['id'];
                if (!empty($customerGstin)) {
                    $p->prepare("UPDATE customers SET gstin = ? WHERE id = ?")->execute([$customerGstin, $customerId]);
                }
            } else {
                $p->prepare("INSERT INTO customers (shop_id, name, mobile, gstin) VALUES (?, ?, ?, ?)")
                  ->execute([$shopId, $customerName, $customerMobile, $customerGstin]);
                $customerId = (int)$p->lastInsertId();
            }
        }
        
        // Generate Invoice Number
        $year = date('Y');
        $invCount = (int)$p->query("SELECT COUNT(*) FROM pos_sales WHERE shop_id = $shopId")->fetchColumn() + 1;
        $invoiceNo = 'INV-S' . $shopId . '-' . $year . '-' . str_pad($invCount, 4, '0', STR_PAD_LEFT);
        
        // Calculate Totals (Without GST)
        $subtotal = 0;
        $taxableTotal = 0;
        $totalGst = 0;
        $cgstTotal = 0;
        $sgstTotal = 0;
        $igstTotal = 0;
        
        $processedItems = [];
        
        foreach ($cartItems as $item) {
            $productId   = (int)($item['id'] ?? 0);
            $prodName    = trim($item['name'] ?? 'Product');
            $hsnCode     = trim($item['hsn'] ?? '8517');
            $qty         = max(1, (int)($item['qty'] ?? 1));
            $unitPrice   = floatval($item['price'] ?? 0);
            $gstRate     = 0.00;
            
            $itemSubtotal = $unitPrice * $qty;
            $itemTaxable  = $itemSubtotal;
            $itemGstAmt   = 0.00;
            $itemTotal    = $itemSubtotal;
            
            $subtotal     += $itemSubtotal;
            $taxableTotal += $itemTaxable;
            
            $processedItems[] = [
                'product_id'     => $productId,
                'product_name'   => $prodName,
                'hsn_code'       => $hsnCode,
                'quantity'       => $qty,
                'unit_price'     => $unitPrice,
                'gst_rate'       => 0.00,
                'taxable_amount' => $itemTaxable,
                'gst_amount'     => 0.00,
                'total_amount'   => $itemTotal
            ];
            
            // Deduct product stock if product_id exists
            if ($productId > 0) {
                $p->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ? AND shop_id = ?")
                  ->execute([$qty, $productId, $shopId]);
            }
        }
        
        $grandTotal = max(0, $subtotal - $discount);
        
        // Insert into pos_sales
        $pStmt = $p->prepare("
            INSERT INTO pos_sales (
                invoice_no, shop_id, customer_id, customer_name, customer_mobile, customer_gstin,
                tax_type, payment_method, subtotal, discount, taxable_amount,
                cgst_amount, sgst_amount, igst_amount, total_gst, grand_total, notes, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $pStmt->execute([
            $invoiceNo, $shopId, $customerId, $customerName, $customerMobile, $customerGstin,
            $taxType, $paymentMethod, $subtotal, $discount, $taxableTotal,
            $cgstTotal, $sgstTotal, $igstTotal, $totalGst, $grandTotal, $notes, $u['id']
        ]);
        
        $saleId = (int)$p->lastInsertId();
        
        // Insert Items
        $itemStmt = $p->prepare("
            INSERT INTO pos_sale_items (
                pos_sale_id, product_id, product_name, hsn_code, quantity, unit_price, gst_rate,
                taxable_amount, gst_amount, total_amount
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($processedItems as $pi) {
            $itemStmt->execute([
                $saleId, $pi['product_id'], $pi['product_name'], $pi['hsn_code'], $pi['quantity'],
                $pi['unit_price'], $pi['gst_rate'], $pi['taxable_amount'], $pi['gst_amount'], $pi['total_amount']
            ]);
        }

        log_audit(
            'POS Invoice Created',
            'POS Terminal',
            "Generated Invoice {$invoiceNo} of total ₹{$grandTotal} for {$customerName} (Payment Method: {$paymentMethod})",
            $u['id']
        );
        
        // Redirect to print Sale Invoice
        header("Location: pos-invoice.php?id=" . $saleId . "&auto_print=1");
        exit;
        
    } catch (Exception $ex) {
        $err = $ex->getMessage();
    }
}

// Fetch shop products for POS selector
$productsStmt = $p->prepare("SELECT * FROM products WHERE shop_id = ? AND status = 'active' ORDER BY name ASC");
$productsStmt->execute([$shopId]);
$products = $productsStmt->fetchAll();

// Fetch active product variants
$varsStmt = $p->prepare("SELECT * FROM product_variants WHERE status = 'active' ORDER BY price ASC");
$varsStmt->execute();
$allVariants = $varsStmt->fetchAll();

$variantsByProduct = [];
foreach ($allVariants as $v) {
    $variantsByProduct[$v['product_id']][] = $v;
}

// Fetch registered shop customers
$custStmt = $p->prepare("SELECT id, name, mobile, gstin FROM customers WHERE shop_id = ? ORDER BY id DESC LIMIT 100");
$custStmt->execute([$shopId]);
$recentCustomers = $custStmt->fetchAll();

start('POS Billing & Sales Terminal');
?>

<style>
.pos-container {
    display: grid;
    grid-template-columns: 1fr 460px;
    gap: 22px;
    align-items: start;
}
@media(max-width: 1100px) {
    .pos-container { grid-template-columns: 1fr; }
}

.cat-filter-btn {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 700;
    background: rgba(255,255,255,0.06);
    border: 1px solid var(--border-color);
    color: var(--text-muted);
    cursor: pointer;
    transition: all 0.2s ease;
}
.cat-filter-btn:hover, .cat-filter-btn.active {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
    box-shadow: 0 4px 12px var(--primary-glow);
}

.pos-prod-card {
    background: linear-gradient(145deg, rgba(30, 41, 59, 0.8), rgba(15, 23, 42, 0.9));
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 14px;
    padding: 16px;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    position: relative;
    overflow: hidden;
}
.pos-prod-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0; height: 3px;
    background: linear-gradient(90deg, #3b82f6, #10b981);
    opacity: 0;
    transition: opacity 0.25s ease;
}
.pos-prod-card:hover {
    border-color: rgba(59, 130, 246, 0.4);
    transform: translateY(-3px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3), 0 0 15px rgba(59, 130, 246, 0.15);
}
.pos-prod-card:hover::before {
    opacity: 1;
}

.cart-card-container {
    background: linear-gradient(145deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.98));
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 16px;
    padding: 20px;
    position: sticky;
    top: 80px;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
}

.cart-table th {
    background: rgba(15, 23, 42, 0.9);
    color: var(--text-muted);
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 10px 8px;
}
.cart-table td {
    padding: 10px 8px;
    font-size: 0.83rem;
    vertical-align: middle;
}

.qty-btn {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    border: 1px solid var(--border-color);
    background: rgba(255,255,255,0.08);
    color: #fff;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
}
.qty-btn:hover {
    background: var(--primary);
    border-color: var(--primary);
}

.tax-badge {
    background: rgba(16, 185, 129, 0.15);
    color: #10b981;
    border: 1px solid rgba(16, 185, 129, 0.3);
    padding: 2px 6px;
    border-radius: 6px;
    font-size: 0.72rem;
    font-weight: 800;
}

.pay-radio-box {
    background: rgba(255,255,255,0.04);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 8px;
    text-align: center;
    cursor: pointer;
    font-size: 0.78rem;
    font-weight: 700;
    transition: all 0.2s ease;
}
.pay-radio-box:hover, .pay-radio-box.active {
    background: rgba(59, 130, 246, 0.15);
    border-color: var(--primary);
    color: #fff;
}
</style>

<?php if ($err): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 12px 16px; border-radius: 10px; margin-bottom: 20px;">
        ❌ <?=e($err)?>
    </div>
<?php endif; ?>

<div class="pos-container">
    
    <!-- LEFT PANEL: SEARCH, CATEGORIES & PRODUCT GRID -->
    <div>
        <!-- SEARCH & CUSTOM PRODUCT HEADER -->
        <div class="card" style="margin-bottom: 16px; padding: 16px; background: linear-gradient(145deg, rgba(30, 41, 59, 0.8), rgba(15, 23, 42, 0.9));">
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
                <div style="flex: 1; position: relative; min-width: 220px;">
                    <i data-lucide="search" style="position: absolute; left: 14px; top: 12px; width: 18px; color: var(--primary);"></i>
                    <input type="text" id="posSearch" placeholder="Search by Product Name, SKU, or Brand..." onkeyup="filterProducts()" style="width: 100%; padding-left: 42px; height: 44px; font-size: 0.9rem; border-radius: 10px; background: rgba(15,23,42,0.8);">
                </div>
                <button type="button" class="btn" style="background: linear-gradient(135deg, var(--primary), #2563eb); color: #fff; height: 44px; padding: 0 16px; border-radius: 10px; font-weight: 700;" onclick="openCustomProductModal()">
                    + Add Custom Item
                </button>
            </div>

            <!-- CATEGORY FILTER BADGES -->
            <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                <span style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700; margin-right: 4px;">Category:</span>
                <button type="button" class="cat-filter-btn active" onclick="filterCategory('all', this)">All Products</button>
                <button type="button" class="cat-filter-btn" onclick="filterCategory('mobile', this)">📱 Mobiles</button>
                <button type="button" class="cat-filter-btn" onclick="filterCategory('laptop', this)">💻 Laptops</button>
                <button type="button" class="cat-filter-btn" onclick="filterCategory('ac', this)">❄️ AC</button>
                <button type="button" class="cat-filter-btn" onclick="filterCategory('tv', this)">📺 Smart TV</button>
                <button type="button" class="cat-filter-btn" onclick="filterCategory('accessory', this)">🎧 Accessories</button>
            </div>
        </div>

        <!-- PRODUCT GRID CATALOG -->
        <div id="productGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 16px;">
            <?php foreach ($products as $prod): 
                $pVariants = $variantsByProduct[$prod['id']] ?? [];
                $prodJson = [
                    'id' => $prod['id'],
                    'name' => $prod['name'],
                    'price' => floatval($prod['selling_price']),
                    'hsn' => $prod['hsn_code'] ?: '8517',
                    'gst_rate' => 0,
                    'stock' => intval($prod['stock'])
                ];
            ?>
                <div class="pos-prod-card" data-category="<?=e(strtolower($prod['category'] ?: 'mobile'))?>" data-name="<?=e(strtolower($prod['name'] . ' ' . $prod['brand'] . ' ' . $prod['sku']))?>" onclick="handlePosProductClick(<?=htmlspecialchars(json_encode($prodJson))?>, <?=htmlspecialchars(json_encode($pVariants))?>)">
                    <div>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 0.72rem; color: var(--primary); font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px;"><?=e($prod['brand'] ?: 'General')?></span>
                            <?php if (!empty($pVariants)): ?>
                                <span class="badge" style="font-size: 0.68rem; background: rgba(59, 130, 246, 0.2); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.4);">
                                    🏷️ <?=count($pVariants)?> Options
                                </span>
                            <?php endif; ?>
                        </div>
                        <h4 style="font-size: 0.92rem; font-weight: 800; color: #fff; margin: 0 0 6px 0; line-height: 1.3;"><?=e($prod['name'])?></h4>
                        <div style="font-size: 0.74rem; color: var(--text-muted);">HSN: <?=e($prod['hsn_code'] ?: '8517')?> | SKU: <?=e($prod['sku'] ?: '-')?></div>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 14px; padding-top: 10px; border-top: 1px dashed rgba(255,255,255,0.1);">
                        <div>
                            <div style="font-size: 0.7rem; color: var(--text-muted);"><?=!empty($pVariants)?'Starts from':'Price'?></div>
                            <strong style="color: #10b981; font-size: 1.1rem; font-weight: 800;"><?=money($prod['selling_price'])?></strong>
                        </div>
                        <div style="text-align: right;">
                            <span style="font-size: 0.72rem; display: block; font-weight: 700; color: <?=$prod['stock']>0?'#10b981':'#ef4444'?>;">
                                <?=$prod['stock']>0 ? 'Stock: ' . intval($prod['stock']) : 'Out of Stock'?>
                            </span>
                            <button type="button" style="margin-top: 4px; padding: 4px 10px; font-size: 0.75rem; background: rgba(59,130,246,0.15); color: var(--primary); border: 1px solid rgba(59,130,246,0.3); border-radius: 6px; font-weight: 800;">+ Add</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </div>

    <!-- RIGHT PANEL: BILLING CART & SUMMARY -->
    <div class="cart-card-container">
        <form method="POST" id="posForm">
            <input type="hidden" name="action" value="create_pos_sale">
            <input type="hidden" name="cart_items" id="cartItemsInput">

            <!-- CUSTOMER DETAILS -->
            <div style="margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--border-color);">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <label style="font-weight: 800; font-size: 0.88rem; color: var(--primary); display: flex; align-items: center; gap: 6px;">
                        <span>👤 Customer Information</span>
                    </label>
                    <select id="custQuickSelect" onchange="selectCustomer(this)" style="font-size: 0.75rem; padding: 4px 8px; width: 140px; border-radius: 6px;">
                        <option value="">-- Quick Select --</option>
                        <?php foreach ($recentCustomers as $rc): ?>
                            <option value="<?=e($rc['name'])?>" data-mobile="<?=e($rc['mobile'])?>" data-gstin="<?=e($rc['gstin'] ?? '')?>"><?=e($rc['name'])?> (<?=e($rc['mobile'])?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                    <input type="text" name="customer_name" id="custName" placeholder="Customer Name *" required value="Walk-in Customer" style="font-size: 0.85rem; height: 38px; border-radius: 8px;">
                    <input type="text" name="customer_mobile" id="custMobile" placeholder="Mobile Number" style="font-size: 0.85rem; height: 38px; border-radius: 8px;">
                </div>
            </div>

            <!-- CART ITEMS TABLE -->
            <div style="margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <label style="font-weight: 800; font-size: 0.88rem; display: flex; align-items: center; gap: 6px;">
                        <span>🛒 Billing Cart Items</span>
                        <span id="cartCountBadge" style="background: var(--primary); color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 0.72rem;">0 Items</span>
                    </label>
                    <button type="button" onclick="clearCart()" style="background: none; border: none; color: var(--danger); font-size: 0.78rem; cursor: pointer; font-weight: 800;">Clear All</button>
                </div>

                <div style="max-height: 220px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: 10px; background: rgba(15,23,42,0.8);">
                    <table class="table cart-table" style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th style="width: 70px; text-align: center;">Qty</th>
                                <th style="width: 70px;">Price</th>
                                <th style="width: 80px; text-align: right;">Total</th>
                                <th style="width: 24px;"></th>
                            </tr>
                        </thead>
                        <tbody id="cartTableBody">
                            <tr><td colspan="5" style="text-align: center; padding: 20px;" class="muted">Cart is empty. Click products to add.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- BILLING SUMMARY -->
            <div style="background: linear-gradient(145deg, rgba(15, 23, 42, 0.9), rgba(30, 41, 59, 0.95)); padding: 14px; border-radius: 12px; border: 1px solid var(--border-color); margin-bottom: 16px; font-size: 0.85rem;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span class="muted">Subtotal:</span>
                    <strong id="lblSubtotal">₹0.00</strong>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin: 8px 0; padding: 8px 0; border-top: 1px dashed var(--border-color);">
                    <span class="muted">Special Discount (₹):</span>
                    <input type="number" name="discount" id="discountInput" value="0" min="0" step="any" oninput="renderCart()" style="width: 95px; text-align: right; height: 32px; font-size: 0.88rem; border-radius: 6px;">
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 10px; border-top: 1px solid var(--border-color); font-size: 1.1rem; color: #fff;">
                    <strong>Grand Total Payable:</strong>
                    <strong style="color: #10b981; font-size: 1.3rem; font-weight: 800;" id="lblGrandTotal">₹0.00</strong>
                </div>
            </div>

            <!-- PAYMENT METHOD SELECTION -->
            <div style="margin-bottom: 16px;">
                <label style="font-weight: 800; font-size: 0.82rem; display: block; margin-bottom: 8px; color: var(--text-muted);">Payment Method *</label>
                <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px;">
                    <label class="pay-radio-box active" onclick="selectPayRadio(this)">
                        <input type="radio" name="payment_method" value="cash" checked style="display:none;">💵 Cash
                    </label>
                    <label class="pay-radio-box" onclick="selectPayRadio(this)">
                        <input type="radio" name="payment_method" value="upi" style="display:none;">📲 UPI / QR
                    </label>
                    <label class="pay-radio-box" onclick="selectPayRadio(this)">
                        <input type="radio" name="payment_method" value="card" style="display:none;">💳 Card
                    </label>
                    <label class="pay-radio-box" onclick="selectPayRadio(this)">
                        <input type="radio" name="payment_method" value="netbanking" style="display:none;">🏦 NetBank
                    </label>
                </div>
            </div>

            <?php if (!$isPosActivated): ?>
                <div style="background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.3); color: #fbbf24; padding: 10px 12px; border-radius: 8px; font-size: 0.8rem; font-weight: 700; margin-bottom: 12px; text-align: center;">
                    🔒 POS Addon Pending Activation — Contact to developer for activate this feature.
                </div>
            <?php endif; ?>

            <button type="submit" class="btn" id="btnSubmitPosSale" style="width: 100%; padding: 14px; font-size: 1.05rem; font-weight: 800; background: <?=$isPosActivated?'linear-gradient(135deg, #059669, #10b981)':'linear-gradient(135deg, #64748b, #475569)'?>; border-radius: 10px; box-shadow: 0 6px 20px rgba(16,185,129,0.3);">
                🧾 Complete Sale & Print Invoice →
            </button>
        </form>
    </div>
</div>

<!-- MODAL FOR CUSTOM ITEM -->
<div id="customItemModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(4px); align-items: center; justify-content: center; z-index: 9999;">
    <div class="card" style="width: 380px; max-width: 90%; padding: 22px; border-radius: 14px;">
        <h4 style="margin-bottom: 16px; font-weight: 800; font-size: 1.05rem; color: var(--primary);">+ Add Non-Inventory / Custom Item</h4>
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <div>
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">Item Description *</label>
                <input type="text" id="custItemName" placeholder="e.g. Tempered Glass / Back Cover">
            </div>
            <div>
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">HSN Code</label>
                <input type="text" id="custItemHsn" placeholder="e.g. 8517" value="8517">
            </div>
            <div>
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">Selling Price (₹) *</label>
                <input type="number" id="custItemPrice" placeholder="e.g. 299" step="any">
            </div>
        </div>
        </div>
        <div style="display: flex; gap: 10px; margin-top: 20px; justify-content: flex-end;">
            <button type="button" class="btn" style="background: rgba(255,255,255,0.1);" onclick="closeCustomProductModal()">Cancel</button>
            <button type="button" class="btn" style="background: var(--primary);" onclick="addCustomItemToCart()">Add to Cart</button>
        </div>
    </div>
</div>

<!-- MODAL FOR POS API KEY LICENSE ACTIVATION -->
<div id="posLicenseModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(6px); align-items: center; justify-content: center; z-index: 10000;">
    <div class="card" style="width: 440px; max-width: 92%; padding: 24px; border-radius: 16px; border: 1px solid rgba(245,158,11,0.4); background: linear-gradient(145deg, #0f172a, #1e293b); box-shadow: 0 20px 50px rgba(0,0,0,0.5);">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
            <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(245,158,11,0.2); border: 1px solid rgba(245,158,11,0.4); display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
                ⭐
            </div>
            <div>
                <h4 style="font-size: 1.05rem; font-weight: 800; color: #fff; margin: 0;">Premium POS Addon Feature</h4>
                <span style="font-size: 0.75rem; color: #f59e0b; font-weight: 700;">API Key License Verification Required</span>
            </div>
        </div>

        <div style="background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.3); color: #fbbf24; padding: 12px 14px; border-radius: 10px; font-size: 0.84rem; line-height: 1.5; margin-bottom: 18px;">
            <strong>⚠️ Contact to developer for activate this feature.</strong><br>
            POS Billing & Instant GST Tax Invoicing is a premium addon module. Please enter your API Key to verify and activate.
        </div>

        <div style="margin-bottom: 16px;">
            <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 6px;">Enter POS API / License Key *</label>
            <input type="text" id="posApiKeyInput" placeholder="e.g. POS-KEY-2026-X890" style="width: 100%; height: 44px; font-weight: 700; letter-spacing: 1px; font-size: 0.9rem; border-radius: 8px; text-transform: uppercase;">
        </div>

        <div id="posApiVerifyMsg" style="display: none; margin-bottom: 14px; padding: 10px; border-radius: 8px; font-size: 0.84rem; font-weight: 700;"></div>

        <div style="display: flex; gap: 10px; justify-content: flex-end;">
            <button type="button" class="btn" style="background: rgba(255,255,255,0.1);" onclick="closePosLicenseModal()">Close</button>
            <button type="button" class="btn" id="btnVerifyPosKey" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; font-weight: 800;" onclick="submitPosApiKey()">
                🔑 Verify & Activate API Key
            </button>
        </div>
    </div>
</div>

<!-- MODAL FOR POS PRODUCT VARIANT SELECTION -->
<div id="posVariantModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(5px); align-items: center; justify-content: center; z-index: 10000;">
    <div class="card" style="width: 440px; max-width: 92%; padding: 22px; border-radius: 16px; border: 1px solid var(--primary);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
            <div>
                <h4 style="font-size: 1.05rem; font-weight: 800; color: #fff; margin: 0;" id="posVariantModalTitle">Select Variant</h4>
                <p class="muted" style="font-size: 0.78rem; margin-top: 2px;">Choose specification option to add to cart</p>
            </div>
            <button type="button" onclick="closePosVariantModal()" style="background: none; border: none; color: var(--text-muted); font-size: 1.4rem; cursor: pointer;">×</button>
        </div>
        <div id="posVariantList" style="display: flex; flex-direction: column; gap: 10px; max-height: 300px; overflow-y: auto;">
            <!-- Variants dynamically rendered -->
        </div>
    </div>
</div>

<script>
let cart = [];
const isPosActivated = <?= $isPosActivated ? 'true' : 'false' ?>;

function handlePosProductClick(prod, variants) {
    if (variants && variants.length > 0) {
        openPosVariantModal(prod, variants);
    } else {
        addToCart(prod);
    }
}

function openPosVariantModal(prod, variants) {
    document.getElementById('posVariantModalTitle').innerText = 'Select Option for ' + prod.name;
    const list = document.getElementById('posVariantList');
    list.innerHTML = '';
    
    variants.forEach(v => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn';
        btn.style.cssText = 'width: 100%; display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-color); border-radius: 10px; text-align: left; transition: all 0.2s ease;';
        btn.onmouseover = function() { this.style.borderColor = 'var(--primary)'; this.style.background = 'rgba(59,130,246,0.15)'; };
        btn.onmouseout = function() { this.style.borderColor = 'var(--border-color)'; this.style.background = 'rgba(255,255,255,0.05)'; };
        
        const vPriceFormatted = parseFloat(v.price).toLocaleString('en-IN', {minimumFractionDigits:2});
        
        btn.innerHTML = `
            <div>
                <strong style="color: #fff; font-size: 0.9rem; display: block;">${v.variant_name}</strong>
                <span class="muted" style="font-size: 0.74rem;">SKU: ${v.sku || prod.hsn} | Stock: ${v.stock}</span>
            </div>
            <strong style="color: #10b981; font-size: 1rem;">₹${vPriceFormatted}</strong>
        `;
        
        btn.onclick = function() {
            addToCart({
                id: prod.id,
                name: prod.name + ' (' + v.variant_name + ')',
                price: parseFloat(v.price),
                hsn: v.sku || prod.hsn,
                gst_rate: 0,
                stock: v.stock
            });
            closePosVariantModal();
        };
        list.appendChild(btn);
    });
    
    document.getElementById('posVariantModal').style.display = 'flex';
}

function closePosVariantModal() {
    document.getElementById('posVariantModal').style.display = 'none';
}

function filterProducts() {
    const q = document.getElementById('posSearch').value.toLowerCase().trim();
    document.querySelectorAll('.pos-prod-card').forEach(card => {
        const name = card.getAttribute('data-name') || '';
        card.style.display = name.includes(q) ? 'flex' : 'none';
    });
}

function filterCategory(cat, btn) {
    document.querySelectorAll('.cat-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    
    document.querySelectorAll('.pos-prod-card').forEach(card => {
        const itemCat = card.getAttribute('data-category') || '';
        if (cat === 'all' || itemCat.includes(cat)) {
            card.style.display = 'flex';
        } else {
            card.style.display = 'none';
        }
    });
}

function selectCustomer(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (opt && opt.value) {
        document.getElementById('custName').value = opt.value;
        document.getElementById('custMobile').value = opt.getAttribute('data-mobile') || '';
        document.getElementById('custGstin').value = opt.getAttribute('data-gstin') || '';
    }
}

function selectPayRadio(lbl) {
    document.querySelectorAll('.pay-radio-box').forEach(b => b.classList.remove('active'));
    lbl.classList.add('active');
    const radio = lbl.querySelector('input[type="radio"]');
    if (radio) radio.checked = true;
}

function addToCart(prod) {
    const existing = cart.find(i => i.id > 0 && i.id === prod.id);
    if (existing) {
        existing.qty++;
    } else {
        cart.push({
            id: prod.id,
            name: prod.name,
            price: parseFloat(prod.price),
            hsn: prod.hsn || '8517',
            gst_rate: parseFloat(prod.gst_rate || 18),
            qty: 1
        });
    }
    renderCart();
}

function updateCartQty(index, delta) {
    if (cart[index]) {
        cart[index].qty = Math.max(1, cart[index].qty + delta);
        renderCart();
    }
}

function setCartQtyInput(index, val) {
    const qty = parseInt(val) || 1;
    if (qty <= 0) {
        removeFromCart(index);
        return;
    }
    cart[index].qty = qty;
    renderCart();
}

function removeFromCart(index) {
    cart.splice(index, 1);
    renderCart();
}

function clearCart() {
    cart = [];
    renderCart();
}

function renderCart() {
    const tbody = document.getElementById('cartTableBody');
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;

    if (cart.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align: center; padding: 20px;" class="muted">Cart is empty. Click products to add.</td></tr>';
        document.getElementById('lblSubtotal').innerText = '₹0.00';
        document.getElementById('lblGrandTotal').innerText = '₹0.00';
        document.getElementById('cartItemsInput').value = '[]';
        document.getElementById('cartCountBadge').innerText = '0 Items';
        return;
    }

    let html = '';
    let subtotal = 0;
    let totalItems = 0;

    cart.forEach((item, index) => {
        const itemTotal = item.price * item.qty;
        subtotal += itemTotal;
        totalItems += item.qty;

        html += '<tr style="border-bottom: 1px solid var(--border-color);">' +
            '<td>' +
                '<strong style="color:#fff;">' + (item.name || '') + '</strong><br>' +
                '<span class="muted" style="font-size:0.7rem;">HSN: ' + (item.hsn || '8517') + '</span>' +
            '</td>' +
            '<td style="text-align: center;">' +
                '<div style="display: inline-flex; align-items: center; gap: 4px;">' +
                    '<button type="button" class="qty-btn" onclick="updateCartQty(' + index + ', -1)">-</button>' +
                    '<input type="number" value="' + item.qty + '" min="1" onchange="setCartQtyInput(' + index + ', this.value)" style="width: 32px; padding: 2px; text-align: center; height: 24px; font-size: 0.8rem; border-radius: 4px; border: 1px solid var(--border-color); background: rgba(0,0,0,0.3); color: #fff;">' +
                    '<button type="button" class="qty-btn" onclick="updateCartQty(' + index + ', 1)">+</button>' +
                '</div>' +
            '</td>' +
            '<td>₹' + item.price.toFixed(2) + '</td>' +
            '<td style="text-align: right; font-weight: 800; color: #10b981;">₹' + itemTotal.toFixed(2) + '</td>' +
            '<td>' +
                '<button type="button" onclick="removeFromCart(' + index + ')" style="background:none; border:none; color: var(--danger); cursor:pointer; font-weight:800; font-size: 1.1rem;">×</button>' +
            '</td>' +
        '</tr>';
    });

    tbody.innerHTML = html;

    const grandTotal = Math.max(0, subtotal - discount);

    document.getElementById('lblSubtotal').innerText = '₹' + subtotal.toFixed(2);
    document.getElementById('lblGrandTotal').innerText = '₹' + grandTotal.toFixed(2);
    document.getElementById('cartItemsInput').value = JSON.stringify(cart);
    document.getElementById('cartCountBadge').innerText = totalItems + ' Items';
}

function openCustomProductModal() {
    document.getElementById('customItemModal').style.display = 'flex';
}
function closeCustomProductModal() {
    document.getElementById('customItemModal').style.display = 'none';
}
function addCustomItemToCart() {
    const name = document.getElementById('custItemName').value.trim();
    const hsn = document.getElementById('custItemHsn').value.trim() || '8517';
    const price = parseFloat(document.getElementById('custItemPrice').value) || 0;

    if (!name || price <= 0) {
        alert('Please enter product name and a valid selling price.');
        return;
    }

    addToCart({
        id: 0,
        name: name,
        price: price,
        hsn: hsn,
        gst_rate: 0,
        stock: 99
    });

    document.getElementById('custItemName').value = '';
    document.getElementById('custItemPrice').value = '';
    closeCustomProductModal();
}

function openPosLicenseModal() {
    document.getElementById('posLicenseModal').style.display = 'flex';
}
function closePosLicenseModal() {
    document.getElementById('posLicenseModal').style.display = 'none';
}

function submitPosApiKey() {
    const input = document.getElementById('posApiKeyInput');
    const key = input.value.trim();
    const btn = document.getElementById('btnVerifyPosKey');
    const msg = document.getElementById('posApiVerifyMsg');

    if (!key || key.length < 4) {
        msg.style.display = 'block';
        msg.style.background = 'rgba(239, 68, 68, 0.15)';
        msg.style.color = '#ef4444';
        msg.style.border = '1px solid #ef4444';
        msg.innerText = 'Please enter a valid POS API Key / License Key.';
        return;
    }

    btn.disabled = true;
    btn.innerText = '⏳ Verifying API Key...';

    const formData = new FormData();
    formData.append('action', 'verify_pos_api_key');
    formData.append('pos_api_key', key);

    fetch('pos.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerText = '🔑 Verify & Activate API Key';
        msg.style.display = 'block';
        
        if (data.success) {
            msg.style.background = 'rgba(16, 185, 129, 0.15)';
            msg.style.color = '#10b981';
            msg.style.border = '1px solid #10b981';
            msg.innerText = data.message || '✓ POS API Key Verified & Activated!';
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            msg.style.background = 'rgba(239, 68, 68, 0.15)';
            msg.style.color = '#ef4444';
            msg.style.border = '1px solid #ef4444';
            msg.innerText = data.message || 'API Key verification failed.';
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerText = '🔑 Verify & Activate API Key';
        msg.style.display = 'block';
        msg.style.background = 'rgba(239, 68, 68, 0.15)';
        msg.style.color = '#ef4444';
        msg.style.border = '1px solid #ef4444';
        msg.innerText = 'Error verifying API key.';
    });
}

document.getElementById('posForm').addEventListener('submit', function(e) {
    if (!isPosActivated) {
        e.preventDefault();
        openPosLicenseModal();
        return false;
    }
    if (cart.length === 0) {
        e.preventDefault();
        alert('Please add at least one item to the cart before completing the sale.');
    }
});
</script>

<?php render_end(); ?>
