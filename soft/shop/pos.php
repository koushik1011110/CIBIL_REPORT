<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/onboarding_db_init.php';
role('shop_admin', 'superadmin', 'staff');

$p = db();
$u = u();
$shopId = (int)($u['shop_id'] ?? 0);

$isSuperAdmin = ($u['role'] === 'superadmin');

// If superadmin, allow switching shop via query param ?shop_id=...
if ($isSuperAdmin && isset($_GET['shop_id']) && (int)$_GET['shop_id'] > 0) {
    $shopId = (int)$_GET['shop_id'];
}

// If superadmin has no shop_id, fallback to first active shop or 1
if ($shopId === 0 && $isSuperAdmin) {
    $shopId = (int)($p->query("SELECT id FROM shops ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1);
}

// Fetch all shops for superadmin dropdown selector
$allShops = [];
if ($isSuperAdmin) {
    $allShops = $p->query("SELECT id, name FROM shops ORDER BY id ASC")->fetchAll();
}

// Fetch shop details for POS
$shopStmt = $p->prepare("SELECT * FROM shops WHERE id = ?");
$shopStmt->execute([$shopId]);
$shop = $shopStmt->fetch() ?: ['name' => 'Demo Store', 'gstin' => '', 'address' => ''];

$msg = '';
$err = '';

// Check POS Addon License Activation State:
// 1. SUPERADMIN IS ALWAYS FREE & UNLOCKED!
// 2. Shop accounts are locked unless shops.pos_active == 1
$isPosActivated = is_pos_unlocked($shopId, $u);
$posPrice = floatval(get_setting('pos_activation_price', '1999'));
if ($posPrice <= 0) $posPrice = 1999.00;

// Handle POS API Key Verification (AJAX / Form)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_pos_api_key') {
    header('Content-Type: application/json');
    $apiKey = trim($_POST['pos_api_key'] ?? '');
    
    $validLicenseCode = 'KKWEBMART-PREMIUIM-ADDON-2022';
    
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'message' => 'Please enter a valid POS API Key / License Key.']);
        exit;
    }
    
    if (strtoupper($apiKey) !== strtoupper($validLicenseCode)) {
        echo json_encode(['success' => false, 'message' => '❌ Invalid POS API Key! Code does not match. Please pay online via Cashfree to activate this feature.']);
        exit;
    }
    
    // Save setting permanently for this shop
    activate_shop_pos($shopId, 'LICENSE_CODE', $validLicenseCode);
    set_setting('pos_addon_activated', '1');
    set_setting('pos_addon_api_key', $validLicenseCode);
    
    echo json_encode([
        'success' => true, 
        'message' => '✓ POS Premium Addon Verified & Activated Successfully for this Store!'
    ]);
    exit;
}

// Process POS Sale Submission
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_pos_sale') {
    try {
        if (!$isPosActivated) {
            throw new Exception("POS Billing Terminal is locked for this shop. Please complete payment of ₹" . number_format($posPrice, 0) . " via Cashfree to unlock POS billing.");
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

start('POS Terminal');
?>

<style>
/* Remove page header on POS billing terminal */
.page-header {
    display: none !important;
}
.pos-container {
    display: grid;
    grid-template-columns: 1fr 460px;
    gap: 22px;
    align-items: start;
}
@media(max-width: 1100px) {
    .pos-container { grid-template-columns: 1fr; }
}

/* POS Header Search Card */
.pos-header-card {
    margin-bottom: 16px;
    padding: 16px;
    border-radius: 14px;
    background: linear-gradient(145deg, rgba(30, 41, 59, 0.8), rgba(15, 23, 42, 0.9));
    border: 1px solid rgba(255, 255, 255, 0.08);
}
body.light-theme .pos-header-card {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04) !important;
}

/* Search input with centered icon */
.pos-search-wrap {
    flex: 1;
    position: relative;
    min-width: 220px;
}
.pos-search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    width: 18px;
    height: 18px;
    color: var(--primary);
    pointer-events: none;
    z-index: 2;
}
#posSearch {
    width: 100%;
    padding-left: 44px !important;
    height: 44px;
    font-size: 0.9rem;
    border-radius: 10px;
    background: rgba(15,23,42,0.8);
    border: 1px solid var(--border-color);
    color: #fff;
    box-sizing: border-box;
}
body.light-theme #posSearch {
    background: #f8fafc !important;
    border: 1.5px solid #cbd5e1 !important;
    color: #0f172a !important;
    padding-left: 44px !important;
}
body.light-theme #posSearch:focus {
    background: #ffffff !important;
    border-color: #2563eb !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15) !important;
}

/* Category Filter Badges */
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
.cat-filter-btn:hover {
    background: rgba(255,255,255,0.12);
    color: #fff;
}
.cat-filter-btn.active {
    background: var(--primary) !important;
    color: #fff !important;
    border-color: var(--primary) !important;
    box-shadow: 0 4px 12px var(--primary-glow);
}
body.light-theme .cat-filter-btn {
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    color: #475569;
}
body.light-theme .cat-filter-btn:hover {
    background: #e2e8f0;
    color: #0f172a;
    border-color: #cbd5e1;
}
body.light-theme .cat-filter-btn.active {
    background: #2563eb !important;
    border-color: #2563eb !important;
    color: #ffffff !important;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
}

/* Product Cards */
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
body.light-theme .pos-prod-card {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
}
body.light-theme .pos-prod-card:hover {
    border-color: #3b82f6 !important;
    box-shadow: 0 10px 24px rgba(37, 99, 235, 0.12), 0 0 0 1px rgba(59, 130, 246, 0.2) !important;
    transform: translateY(-3px);
}
.pos-prod-title {
    font-size: 0.92rem;
    font-weight: 800;
    color: #fff;
    margin: 0 0 6px 0;
    line-height: 1.35;
}
body.light-theme .pos-prod-title {
    color: #0f172a !important;
}
.pos-brand-tag {
    font-size: 0.72rem;
    color: var(--primary);
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
body.light-theme .pos-brand-tag {
    color: #2563eb !important;
}
.pos-badge-options {
    font-size: 0.68rem;
    background: rgba(59, 130, 246, 0.2);
    color: #60a5fa;
    border: 1px solid rgba(59, 130, 246, 0.4);
    border-radius: 6px;
    padding: 2px 7px;
    font-weight: 700;
}
body.light-theme .pos-badge-options {
    background: #eff6ff !important;
    color: #2563eb !important;
    border: 1px solid #bfdbfe !important;
}
.pos-prod-footer {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    margin-top: 14px;
    padding-top: 10px;
    border-top: 1px dashed rgba(255,255,255,0.1);
}
body.light-theme .pos-prod-footer {
    border-top: 1px dashed #e2e8f0;
}
.pos-add-btn {
    margin-top: 4px;
    padding: 4px 12px;
    font-size: 0.75rem;
    background: rgba(59,130,246,0.15);
    color: var(--primary);
    border: 1px solid rgba(59,130,246,0.3);
    border-radius: 6px;
    font-weight: 800;
    cursor: pointer;
    transition: all 0.2s ease;
}
.pos-add-btn:hover {
    background: var(--primary);
    color: #fff;
}
body.light-theme .pos-add-btn {
    background: #eff6ff;
    color: #2563eb;
    border: 1px solid #bfdbfe;
}
body.light-theme .pos-add-btn:hover {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
}

/* Cart Container */
.cart-card-container {
    background: linear-gradient(145deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.98));
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 16px;
    padding: 20px;
    position: sticky;
    top: 80px;
    box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35);
}
body.light-theme .cart-card-container {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.06) !important;
}

.cart-section-title {
    font-weight: 800;
    font-size: 0.88rem;
    color: var(--primary);
    display: flex;
    align-items: center;
    gap: 6px;
}
body.light-theme .cart-section-title {
    color: #1e40af !important;
}

/* Cart Table & Rows */
.pos-table-scroll {
    max-height: 220px;
    overflow-y: auto;
    border: 1px solid var(--border-color);
    border-radius: 10px;
    background: rgba(15,23,42,0.8);
}
body.light-theme .pos-table-scroll {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
}

.cart-table th {
    background: rgba(15, 23, 42, 0.9);
    color: var(--text-muted);
    font-size: 0.72rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 10px 8px;
}
body.light-theme .cart-table th {
    background: #f1f5f9 !important;
    color: #475569 !important;
    border-bottom: 1px solid #e2e8f0 !important;
}
.cart-table td {
    padding: 10px 8px;
    font-size: 0.83rem;
    vertical-align: middle;
}
body.light-theme .cart-table td {
    color: #1e293b !important;
    border-bottom: 1px solid #e2e8f0 !important;
}

.cart-item-name {
    color: #fff;
    font-weight: 700;
}
body.light-theme .cart-item-name {
    color: #0f172a !important;
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
    transition: all 0.15s ease;
}
.qty-btn:hover {
    background: var(--primary);
    border-color: var(--primary);
}
body.light-theme .qty-btn {
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    color: #0f172a !important;
}
body.light-theme .qty-btn:hover {
    background: #2563eb !important;
    border-color: #2563eb !important;
    color: #ffffff !important;
}

.cart-qty-input {
    width: 34px;
    padding: 2px;
    text-align: center;
    height: 24px;
    font-size: 0.8rem;
    border-radius: 4px;
    border: 1px solid var(--border-color);
    background: rgba(0,0,0,0.3);
    color: #fff;
}
body.light-theme .cart-qty-input {
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    color: #0f172a !important;
    padding: 2px !important;
}

/* POS Summary Box */
.pos-summary-box {
    background: linear-gradient(145deg, rgba(15, 23, 42, 0.9), rgba(30, 41, 59, 0.95));
    padding: 14px;
    border-radius: 12px;
    border: 1px solid var(--border-color);
    margin-bottom: 16px;
    font-size: 0.85rem;
}
body.light-theme .pos-summary-box {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
}
.pos-total-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 10px;
    border-top: 1px solid var(--border-color);
    font-size: 1.05rem;
    color: #fff;
}
body.light-theme .pos-total-row {
    color: #0f172a !important;
    border-top: 1px solid #e2e8f0 !important;
}
body.light-theme #lblSubtotal {
    color: #0f172a !important;
}
body.light-theme #lblGrandTotal {
    color: #059669 !important;
}
body.light-theme #discountInput {
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    color: #0f172a !important;
}
body.light-theme #custQuickSelect {
    background: #f8fafc !important;
    border: 1px solid #cbd5e1 !important;
    color: #0f172a !important;
}

/* Payment Method Radios */
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
    user-select: none;
}
.pay-radio-box:hover, .pay-radio-box.active {
    background: rgba(59, 130, 246, 0.15);
    border-color: var(--primary);
    color: #fff;
}
body.light-theme .pay-radio-box {
    background: #f1f5f9;
    border: 1.5px solid #e2e8f0;
    color: #475569;
}
body.light-theme .pay-radio-box:hover {
    background: #e2e8f0;
    color: #0f172a;
}
body.light-theme .pay-radio-box.active {
    background: #eff6ff !important;
    border-color: #2563eb !important;
    color: #1d4ed8 !important;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.15);
}

/* Modals Light Theme */
body.light-theme .pos-modal-card {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 20px 45px rgba(0, 0, 0, 0.15) !important;
    color: #0f172a !important;
}
body.light-theme .pos-modal-title {
    color: #0f172a !important;
}
.pos-variant-btn {
    width: 100%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    text-align: left;
    transition: all 0.2s ease;
    cursor: pointer;
}
.pos-variant-btn:hover {
    border-color: var(--primary);
    background: rgba(59,130,246,0.15);
}
.pos-variant-name {
    color: #fff;
}
body.light-theme .pos-variant-btn {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
    color: #0f172a !important;
}
body.light-theme .pos-variant-btn:hover {
    background: #eff6ff !important;
    border-color: #2563eb !important;
}
body.light-theme .pos-variant-name {
    color: #0f172a !important;
}
.pos-modal-btn-cancel {
    background: rgba(255,255,255,0.1);
    color: var(--text-muted);
}
body.light-theme .pos-modal-btn-cancel {
    background: #f1f5f9 !important;
    border: 1px solid #cbd5e1 !important;
    color: #475569 !important;
}
body.light-theme .pos-modal-btn-cancel:hover {
    background: #e2e8f0 !important;
    color: #0f172a !important;
}

/* POS Locked State Styles */
.pos-locked-card {
    background: linear-gradient(145deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.98));
    border: 1px solid rgba(245, 158, 11, 0.35);
    border-radius: 20px;
    padding: 38px 30px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5), 0 0 35px rgba(245, 158, 11, 0.1);
    max-width: 900px;
    margin: 0 auto 30px auto;
    text-align: center;
    position: relative;
    overflow: hidden;
}
body.light-theme .pos-locked-card {
    background: #ffffff !important;
    border: 1px solid #fed7aa !important;
    box-shadow: 0 15px 40px rgba(245, 158, 11, 0.08), 0 4px 12px rgba(0,0,0,0.04) !important;
}
.pos-locked-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
    background: linear-gradient(90deg, #f59e0b, #ef4444, #10b981);
}
.pos-badge-locked {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: rgba(245, 158, 11, 0.15);
    border: 1px solid rgba(245, 158, 11, 0.4);
    color: #f59e0b;
    font-size: 0.8rem;
    font-weight: 800;
    padding: 6px 14px;
    border-radius: 9999px;
    margin-bottom: 16px;
    letter-spacing: 0.5px;
}
body.light-theme .pos-badge-locked {
    background: #fffbeb !important;
    color: #d97706 !important;
    border-color: #fde68a !important;
}
.pos-price-pill {
    background: rgba(15, 23, 42, 0.7);
    border: 1px solid rgba(255, 255, 255, 0.08);
    display: inline-block;
    padding: 16px 32px;
    border-radius: 16px;
    margin: 18px 0;
}
body.light-theme .pos-price-pill {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
}
.pos-features-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 16px;
    margin: 26px 0;
    text-align: left;
}
.pos-feature-item {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 12px;
    padding: 14px 16px;
    display: flex;
    gap: 12px;
    align-items: flex-start;
}
body.light-theme .pos-feature-item {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
}
.pos-feature-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    flex-shrink: 0;
}
.btn-unlock-pos {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 16px 36px;
    background: linear-gradient(135deg, #10b981, #059669);
    color: #ffffff !important;
    text-decoration: none;
    border-radius: 14px;
    font-size: 1.1rem;
    font-weight: 800;
    box-shadow: 0 12px 25px -4px rgba(16, 185, 129, 0.4), 0 0 20px rgba(16, 185, 129, 0.2);
    transition: all 0.25s ease;
    border: none;
    cursor: pointer;
}
.btn-unlock-pos:hover {
    transform: translateY(-2px);
    box-shadow: 0 16px 32px -4px rgba(16, 185, 129, 0.5), 0 0 30px rgba(16, 185, 129, 0.3);
    background: linear-gradient(135deg, #059669, #047857);
}
</style>

<?php if ($err): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 12px 16px; border-radius: 10px; margin-bottom: 20px;">
        ❌ <?=e($err)?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['activated']) && $_GET['activated'] == 1): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 20px; border-radius: 12px; margin-bottom: 22px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">
        <div style="display: flex; align-items: center; gap: 10px;">
            <i data-lucide="check-circle" style="width: 22px; height: 22px; color: #10b981;"></i>
            <div>
                <strong style="font-size: 1rem;">🎉 POS Terminal Unlocked & Activated Successfully!</strong>
                <div style="font-size: 0.82rem; color: #94a3b8; margin-top: 2px;">Your payment of ₹1,999 has been verified via Cashfree. You now have lifetime access to POS GST Billing.</div>
            </div>
        </div>
        <span class="badge" style="background: #10b981; color: #fff; font-weight: 800; padding: 4px 10px; border-radius: 6px;">LIFETIME ACTIVE</span>
    </div>
<?php endif; ?>

<?php if (!$isPosActivated): ?>
    <!-- LOCKED SCREEN FOR SHOP ACCOUNTS -->
    <div class="pos-locked-card">
        <div class="pos-badge-locked">
            🔒 STORE TERMINAL LOCKED • PREMIUM ADDON
        </div>
        
        <h2 style="font-size: 1.75rem; font-weight: 800; color: #fff; margin-bottom: 8px;">
            Unlock POS Billing & GST Invoicing Terminal
        </h2>
        <p style="color: #94a3b8; font-size: 0.95rem; max-width: 680px; margin: 0 auto; line-height: 1.6;">
            POS Terminal is locked for <strong><?=e($shop['name'])?></strong>. Activate instant retail billing, GST invoicing, barcode scanning, and thermal printing by completing the one-time activation.
        </p>

        <div class="pos-price-pill">
            <div style="font-size: 0.78rem; font-weight: 800; color: #10b981; text-transform: uppercase; letter-spacing: 1px;">One-Time Store License</div>
            <div style="font-size: 2.8rem; font-weight: 800; color: #fff; margin: 4px 0; letter-spacing: -1px;">
                <span style="color: #10b981;">₹</span><?=number_format($posPrice, 0)?>
            </div>
            <div style="font-size: 0.78rem; color: #94a3b8;">Lifetime Unlimited Access • Instant Cashfree Activation • No Monthly Renewal</div>
        </div>

        <!-- FEATURES GRID -->
        <div class="pos-features-grid">
            <div class="pos-feature-item">
                <div class="pos-feature-icon" style="background: rgba(59,130,246,0.15); color: #3b82f6;">🧾</div>
                <div>
                    <strong style="color: #fff; font-size: 0.92rem; display: block;">GST & Retail Tax Invoices</strong>
                    <span class="muted" style="font-size: 0.78rem; line-height: 1.4; display: block; margin-top: 2px;">Generate legal invoices with intra-state (CGST+SGST) and inter-state (IGST) tax calculation.</span>
                </div>
            </div>
            <div class="pos-feature-item">
                <div class="pos-feature-icon" style="background: rgba(16,185,129,0.15); color: #10b981;">🖨️</div>
                <div>
                    <strong style="color: #fff; font-size: 0.92rem; display: block;">Thermal Receipt Printing</strong>
                    <span class="muted" style="font-size: 0.78rem; line-height: 1.4; display: block; margin-top: 2px;">1-Click auto-print formatted for standard 80mm and 58mm thermal POS roll printers.</span>
                </div>
            </div>
            <div class="pos-feature-item">
                <div class="pos-feature-icon" style="background: rgba(245,158,11,0.15); color: #f59e0b;">🔍</div>
                <div>
                    <strong style="color: #fff; font-size: 0.92rem; display: block;">Barcode & SKU Fast Search</strong>
                    <span class="muted" style="font-size: 0.78rem; line-height: 1.4; display: block; margin-top: 2px;">Blazing fast live search by barcode scanner, SKU, model or product brand.</span>
                </div>
            </div>
            <div class="pos-feature-item">
                <div class="pos-feature-icon" style="background: rgba(168,85,247,0.15); color: #a855f7;">📦</div>
                <div>
                    <strong style="color: #fff; font-size: 0.92rem; display: block;">Live Stock Inventory Sync</strong>
                    <span class="muted" style="font-size: 0.78rem; line-height: 1.4; display: block; margin-top: 2px;">Automatically decrements store stock on every invoice generated to prevent overselling.</span>
                </div>
            </div>
            <div class="pos-feature-item">
                <div class="pos-feature-icon" style="background: rgba(236,72,153,0.15); color: #ec4899;">👥</div>
                <div>
                    <strong style="color: #fff; font-size: 0.92rem; display: block;">Customer Purchase Records</strong>
                    <span class="muted" style="font-size: 0.78rem; line-height: 1.4; display: block; margin-top: 2px;">Stores walk-in customers or links with registered profiles for easy repeat billing.</span>
                </div>
            </div>
            <div class="pos-feature-item">
                <div class="pos-feature-icon" style="background: rgba(6,182,212,0.15); color: #06b6d4;">⚡</div>
                <div>
                    <strong style="color: #fff; font-size: 0.92rem; display: block;">Instant Gateway Clearance</strong>
                    <span class="muted" style="font-size: 0.78rem; line-height: 1.4; display: block; margin-top: 2px;">Clear payment via Cashfree and POS terminal activates automatically in real-time.</span>
                </div>
            </div>
        </div>

        <!-- STORE DETAILS CONFIRMATION -->
        <div style="background: rgba(15,23,42,0.5); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; padding: 12px 18px; margin: 18px auto; max-width: 500px; display: flex; justify-content: space-around; font-size: 0.8rem;">
            <div><span class="muted">Store:</span> <strong><?=e($shop['name'])?></strong></div>
            <div><span class="muted">Phone:</span> <strong><?=e($shop['phone'] ?: 'N/A')?></strong></div>
            <div><span class="muted">Price:</span> <strong style="color: #10b981;">₹<?=number_format($posPrice, 0)?></strong></div>
        </div>

        <!-- PRIMARY CASHFREE ACTION CTA -->
        <div style="margin-top: 24px;">
            <a href="<?=url('/api/pay-pos-activation.php')?>" class="btn-unlock-pos">
                <i data-lucide="zap" style="width: 22px; height: 22px;"></i>
                Pay ₹<?=number_format($posPrice, 0)?> via Cashfree & Unlock POS Instantly →
            </a>
        </div>

        <!-- SECURITY TRUST BADGES -->
        <div style="margin-top: 22px; display: flex; align-items: center; justify-content: center; gap: 14px; flex-wrap: wrap; font-size: 0.78rem; color: #94a3b8;">
            <span style="display: flex; align-items: center; gap: 5px;">
                <i data-lucide="shield-check" style="width: 15px; height: 15px; color: #10b981;"></i> 100% Secure via Cashfree Payments
            </span>
            <span>•</span>
            <span>UPI (PhonePe, Google Pay, Paytm)</span>
            <span>•</span>
            <span>Debit / Credit Cards & NetBanking</span>
        </div>

        <div style="margin-top: 24px; padding-top: 18px; border-top: 1px dashed rgba(255,255,255,0.1); font-size: 0.8rem; color: #64748b;">
            Have an offline developer license code? 
            <a href="javascript:void(0)" onclick="openPosLicenseModal()" style="color: #f59e0b; font-weight: 700; text-decoration: underline;">Click here to enter API Key</a>
        </div>
    </div>
<?php else: ?>

<div class="pos-container">
    
    <!-- LEFT PANEL: SEARCH, CATEGORIES & PRODUCT GRID -->
    <div>
        <!-- SEARCH & CUSTOM PRODUCT HEADER -->
        <div class="card pos-header-card">
            <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 12px;">
                <div class="pos-search-wrap">
                    <i data-lucide="search" class="pos-search-icon"></i>
                    <input type="text" id="posSearch" placeholder="Search by Product Name, SKU, or Brand..." onkeyup="filterProducts()">
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
                            <span class="pos-brand-tag"><?=e($prod['brand'] ?: 'General')?></span>
                            <?php if (!empty($pVariants)): ?>
                                <span class="badge pos-badge-options">
                                    🏷️ <?=count($pVariants)?> Options
                                </span>
                            <?php endif; ?>
                        </div>
                        <h4 class="pos-prod-title"><?=e($prod['name'])?></h4>
                        <div class="muted" style="font-size: 0.74rem;">HSN: <?=e($prod['hsn_code'] ?: '8517')?> | SKU: <?=e($prod['sku'] ?: '-')?></div>
                    </div>
                    <div class="pos-prod-footer">
                        <div>
                            <div class="muted" style="font-size: 0.7rem;"><?=!empty($pVariants)?'Starts from':'Price'?></div>
                            <strong style="color: #10b981; font-size: 1.1rem; font-weight: 800;"><?=money($prod['selling_price'])?></strong>
                        </div>
                        <div style="text-align: right;">
                            <span style="font-size: 0.72rem; display: block; font-weight: 700; color: <?=$prod['stock']>0?'#10b981':'#ef4444'?>;">
                                <?=$prod['stock']>0 ? 'Stock: ' . intval($prod['stock']) : 'Out of Stock'?>
                            </span>
                            <button type="button" class="pos-add-btn">+ Add</button>
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
                    <label class="cart-section-title">
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
                    <label class="cart-section-title" style="color: inherit;">
                        <span>🛒 Billing Cart Items</span>
                        <span id="cartCountBadge" style="background: var(--primary); color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 0.72rem; font-weight: 700;">0 Items</span>
                    </label>
                    <button type="button" onclick="clearCart()" style="background: none; border: none; color: var(--danger); font-size: 0.78rem; cursor: pointer; font-weight: 800;">Clear All</button>
                </div>

                <div class="pos-table-scroll">
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
            <div class="pos-summary-box">
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span class="muted">Subtotal:</span>
                    <strong id="lblSubtotal">₹0.00</strong>
                </div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin: 8px 0; padding: 8px 0; border-top: 1px dashed var(--border-color);">
                    <span class="muted">Special Discount (₹):</span>
                    <input type="number" name="discount" id="discountInput" value="0" min="0" step="any" oninput="renderCart()" style="width: 95px; text-align: right; height: 32px; font-size: 0.88rem; border-radius: 6px;">
                </div>

                <div class="pos-total-row">
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

            <button type="submit" class="btn" id="btnSubmitPosSale" style="width: 100%; padding: 14px; font-size: 1.05rem; font-weight: 800; background: <?=$isPosActivated?'linear-gradient(135deg, #059669, #10b981)':'linear-gradient(135deg, #64748b, #475569)'?>; border-radius: 10px; box-shadow: 0 6px 20px rgba(16,185,129,0.3); color: #fff;">
                🧾 Complete Sale & Print Invoice →
            </button>
        </form>
    </div>
</div>
<?php endif; /* End isPosActivated conditional check */ ?>

<!-- MODAL FOR CUSTOM ITEM -->
<div id="customItemModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(4px); align-items: center; justify-content: center; z-index: 9999;">
    <div class="card pos-modal-card" style="width: 380px; max-width: 90%; padding: 22px; border-radius: 14px;">
        <h4 class="pos-modal-title" style="margin-bottom: 16px; font-weight: 800; font-size: 1.05rem; color: var(--primary);">+ Add Non-Inventory / Custom Item</h4>
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
        <div style="display: flex; gap: 10px; margin-top: 20px; justify-content: flex-end;">
            <button type="button" class="btn pos-modal-btn-cancel" onclick="closeCustomProductModal()">Cancel</button>
            <button type="button" class="btn" style="background: var(--primary); color: #fff;" onclick="addCustomItemToCart()">Add to Cart</button>
        </div>
    </div>
</div>

<!-- MODAL FOR POS API KEY LICENSE ACTIVATION -->
<div id="posLicenseModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(6px); align-items: center; justify-content: center; z-index: 10000;">
    <div class="card pos-modal-card" style="width: 440px; max-width: 92%; padding: 24px; border-radius: 16px; border: 1px solid rgba(245,158,11,0.4); box-shadow: 0 20px 50px rgba(0,0,0,0.5);">
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 14px;">
            <div style="width: 44px; height: 44px; border-radius: 12px; background: rgba(245,158,11,0.2); border: 1px solid rgba(245,158,11,0.4); display: flex; align-items: center; justify-content: center; font-size: 1.4rem;">
                ⭐
            </div>
            <div>
                <h4 class="pos-modal-title" style="font-size: 1.05rem; font-weight: 800; margin: 0;">Premium POS Addon Feature</h4>
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
            <button type="button" class="btn pos-modal-btn-cancel" onclick="closePosLicenseModal()">Close</button>
            <button type="button" class="btn" id="btnVerifyPosKey" style="background: linear-gradient(135deg, #f59e0b, #d97706); color: #fff; font-weight: 800;" onclick="submitPosApiKey()">
                🔑 Verify & Activate API Key
            </button>
        </div>
    </div>
</div>

<!-- MODAL FOR POS PRODUCT VARIANT SELECTION -->
<div id="posVariantModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(5px); align-items: center; justify-content: center; z-index: 10000;">
    <div class="card pos-modal-card" style="width: 440px; max-width: 92%; padding: 22px; border-radius: 16px; border: 1px solid var(--primary);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
            <div>
                <h4 class="pos-modal-title" style="font-size: 1.05rem; font-weight: 800; margin: 0;" id="posVariantModalTitle">Select Variant</h4>
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
        btn.className = 'btn pos-variant-btn';
        
        const vPriceFormatted = parseFloat(v.price).toLocaleString('en-IN', {minimumFractionDigits:2});
        
        btn.innerHTML = `
            <div>
                <strong class="pos-variant-name" style="font-size: 0.9rem; display: block;">${v.variant_name}</strong>
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
                '<strong class="cart-item-name">' + (item.name || '') + '</strong><br>' +
                '<span class="muted" style="font-size:0.7rem;">HSN: ' + (item.hsn || '8517') + '</span>' +
            '</td>' +
            '<td style="text-align: center;">' +
                '<div style="display: inline-flex; align-items: center; gap: 4px;">' +
                    '<button type="button" class="qty-btn" onclick="updateCartQty(' + index + ', -1)">-</button>' +
                    '<input type="number" class="cart-qty-input" value="' + item.qty + '" min="1" onchange="setCartQtyInput(' + index + ', this.value)">' +
                    '<button type="button" class="qty-btn" onclick="updateCartQty(' + index + ', 1)">+</button>' +
                </div>' +
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

const posFormEl = document.getElementById('posForm');
if (posFormEl) {
    posFormEl.addEventListener('submit', function(e) {
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
}
</script>

<?php render_end(); ?>
