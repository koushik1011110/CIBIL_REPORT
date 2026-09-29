<?php 
require_once __DIR__.'/../includes/layout.php';
role('superadmin');

$p = db();
$user = u();
$userId = (int)($user['id'] ?? 0);
$shopId = (int)($user['shop_id'] ?? 0);

if ($userId > 0 && $shopId <= 0) {
    $uStmt = $p->prepare('SELECT shop_id FROM users WHERE id = ?');
    $uStmt->execute([$userId]);
    $shopId = (int)($uStmt->fetchColumn() ?: 0);
}

// Get accurate wallet balance
$walletBalance = 0.00;
$walletEntityName = 'Store Wallet';
if ($shopId > 0) {
    $balStmt = $p->prepare('SELECT wallet_balance, name FROM shops WHERE id = ?');
    $balStmt->execute([$shopId]);
    $shopData = $balStmt->fetch();
    $walletBalance = floatval($shopData['wallet_balance'] ?? 0);
    $walletEntityName = ($shopData['name'] ?? 'Store') . ' Wallet';
} else if ($userId > 0) {
    $balStmt = $p->prepare('SELECT wallet_balance, name FROM users WHERE id = ?');
    $balStmt->execute([$userId]);
    $userData = $balStmt->fetch();
    $walletBalance = floatval($userData['wallet_balance'] ?? 0);
    $walletEntityName = 'Admin Personal Wallet';
}

// Fetch Customers enriched with last check timestamps and check counts
$custStmt = $p->query('
    SELECT c.id, c.name, c.mobile, c.pan, c.credit_score, c.dob, c.credit_report_json,
           MAX(cc.created_at) as last_checked_at,
           MAX(cc.provider) as last_provider,
           COUNT(cc.id) as total_checks
    FROM customers c
    LEFT JOIN credit_checks cc ON cc.customer_id = c.id
    GROUP BY c.id
    ORDER BY c.name ASC
');
$customers = $custStmt->fetchAll();

// Fetch Recent 8 Credit Checks for fast free re-open drawer
$recentChecksStmt = $p->query('
    SELECT cc.id, cc.customer_id, cc.provider, cc.reference_no, cc.score, cc.created_at,
           c.name as customer_name, c.pan as customer_pan, c.mobile as customer_mobile
    FROM credit_checks cc
    LEFT JOIN customers c ON c.id = cc.customer_id
    ORDER BY cc.id DESC
    LIMIT 8
');
$recentChecks = $recentChecksStmt->fetchAll();

// Fetch Products & Variants for EMI Financing
$prodStmt = $p->query('SELECT id, name, brand, category, selling_price, stock FROM products WHERE status="active" ORDER BY name ASC');
$products = $prodStmt->fetchAll();

$varsStmt = $p->query('SELECT id, product_id, variant_name, price, stock FROM product_variants WHERE status="active" ORDER BY price ASC');
$allVariants = $varsStmt->fetchAll();

$variantsByProduct = [];
foreach ($allVariants as $v) {
    $variantsByProduct[$v['product_id']][] = $v;
}

$initialCustomerId = (int)($_GET['customer_id'] ?? 0);

start('Credit Bureau Assessment & EMI Financing (Admin)');
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<style>
/* ========================================================================= */
/* PAGE-SPECIFIC DUAL THEME DESIGN SYSTEM (DARK & LIGHT THEME SUPPORT)       */
/* ========================================================================= */

header.page-header {
    display: none !important;
}

.cc-container {
    max-width: 1280px;
    margin: 0 auto;
}

/* Base Card Titles & Text Elements */
.cc-hero-title {
    font-size: 1.45rem;
    font-weight: 800;
    color: #ffffff;
    margin: 0;
}
.cc-section-title {
    font-size: 1.15rem;
    font-weight: 800;
    color: #ffffff;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 8px;
}
.cc-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 700;
    color: #cbd5e1;
    font-size: 0.9rem;
}

/* Stepper Navigation */
.cc-stepper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: rgba(15, 23, 42, 0.7);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 16px;
    padding: 8px 12px;
    margin-bottom: 24px;
    backdrop-filter: blur(12px);
    overflow-x: auto;
    gap: 8px;
}
.cc-step-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 18px;
    border-radius: 12px;
    font-size: 0.88rem;
    font-weight: 700;
    color: #94a3b8;
    cursor: pointer;
    transition: all 0.25s ease;
    white-space: nowrap;
    border: 1px solid transparent;
}
.cc-step-item.active {
    background: linear-gradient(135deg, rgba(59, 130, 246, 0.2), rgba(37, 99, 235, 0.1));
    color: #60a5fa;
    border-color: rgba(59, 130, 246, 0.35);
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.15);
}
.cc-step-item.completed {
    color: #34d399;
}
.cc-step-num {
    width: 26px;
    height: 26px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    font-weight: 800;
    background: rgba(255, 255, 255, 0.1);
    color: inherit;
}
.cc-step-item.active .cc-step-num {
    background: #3b82f6;
    color: #ffffff;
}
.cc-step-item.completed .cc-step-num {
    background: #10b981;
    color: #ffffff;
}
.cc-step-arrow {
    color: rgba(255, 255, 255, 0.2);
    font-size: 1rem;
}

/* Hero Wallet Bar */
.cc-hero-card {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 18px;
    padding: 20px 24px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    position: relative;
    overflow: hidden;
}
.cc-hero-card::after {
    content: '';
    position: absolute;
    top: -50px;
    right: -50px;
    width: 200px;
    height: 200px;
    background: radial-gradient(circle, rgba(59, 130, 246, 0.15) 0%, transparent 70%);
    pointer-events: none;
}
.cc-wallet-pill {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: rgba(15, 23, 42, 0.8);
    border: 1px solid rgba(59, 130, 246, 0.3);
    padding: 8px 16px;
    border-radius: 12px;
}
.cc-wallet-label {
    font-size: 0.7rem;
    color: #94a3b8;
    font-weight: 700;
    text-transform: uppercase;
}
.cc-wallet-val {
    font-size: 1.25rem;
    font-weight: 800;
    color: #38bdf8;
    letter-spacing: -0.3px;
}
.cc-hero-btn {
    background: rgba(30, 41, 59, 0.8);
    border: 1px solid rgba(255,255,255,0.15);
    color: #e2e8f0;
    font-size: 0.85rem;
}

/* Customer Search Input & Dropdown */
.cc-search-input {
    width: 100%;
    padding: 14px 44px 14px 16px;
    font-size: 0.95rem;
    font-weight: 600;
    border-radius: 12px;
    background: #0f172a;
    border: 1.5px solid rgba(255,255,255,0.12);
    color: #ffffff;
    box-sizing: border-box;
}
.cc-search-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.2);
}
.cc-dropdown-list {
    display: none;
    position: absolute;
    top: calc(100% + 4px);
    left: 0;
    right: 0;
    z-index: 999;
    background: #0f172a;
    border: 1.5px solid #3b82f6;
    border-radius: 14px;
    max-height: 320px;
    overflow-y: auto;
    box-shadow: 0 15px 35px rgba(0,0,0,0.8);
}
.cc-dropdown-header {
    padding: 8px 14px;
    background: rgba(30, 41, 59, 0.9);
    border-bottom: 1px solid rgba(255,255,255,0.06);
    font-size: 0.75rem;
    color: #94a3b8;
    font-weight: 700;
    text-transform: uppercase;
}
.customer-select-item {
    padding: 12px 16px;
    border-bottom: 1px solid rgba(255,255,255,0.05);
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: center;
    transition: background 0.15s;
}
.customer-select-item:hover {
    background: rgba(59,130,246,0.18);
}
.customer-name-text {
    font-weight: 700;
    color: #ffffff;
    font-size: 0.95rem;
}
.customer-meta-text {
    font-size: 0.8rem;
    color: #94a3b8;
    margin-top: 4px;
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
}
.customer-meta-label {
    color: #64748b;
}

/* Selected Customer Card */
.cc-selected-card {
    display: none;
    background: rgba(30, 41, 59, 0.4);
    border: 1px solid rgba(59, 130, 246, 0.3);
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 20px;
}
.cc-selected-name {
    font-size: 1.05rem;
    font-weight: 800;
    color: #ffffff;
}

/* Bureau Selection Cards */
.bureau-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 14px;
    margin: 16px 0;
}
.bureau-card {
    background: rgba(15, 23, 42, 0.6);
    border: 2px solid rgba(255, 255, 255, 0.08);
    border-radius: 14px;
    padding: 16px;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
}
.bureau-card:hover {
    border-color: rgba(59, 130, 246, 0.5);
    background: rgba(30, 41, 59, 0.5);
    transform: translateY(-2px);
}
.bureau-card.selected {
    border-color: #3b82f6;
    background: rgba(59, 130, 246, 0.12);
    box-shadow: 0 6px 20px rgba(59, 130, 246, 0.15);
}
.bureau-card .bureau-radio {
    width: 18px;
    height: 18px;
    accent-color: #3b82f6;
    margin-right: 8px;
}
.bureau-title {
    color: #ffffff;
    font-size: 0.95rem;
    font-weight: 800;
}
.bureau-desc {
    font-size: 0.8rem;
    color: #94a3b8;
    margin: 0;
    line-height: 1.4;
}
.bureau-pill {
    background: rgba(255,255,255,0.06);
    color: #cbd5e1;
    font-size: 0.7rem;
}

/* Anti-Debit Duplicate Shield Alert Box */
.duplicate-shield-box {
    background: linear-gradient(135deg, rgba(16, 185, 129, 0.12) 0%, rgba(6, 78, 59, 0.2) 100%);
    border: 2px solid #10b981;
    border-radius: 16px;
    padding: 18px 20px;
    margin: 20px 0;
    box-shadow: 0 8px 25px rgba(16, 185, 129, 0.15);
    position: relative;
    animation: fadeIn 0.3s ease;
}
.shield-title {
    color: #34d399;
    font-size: 1.05rem;
    letter-spacing: -0.2px;
}
.shield-desc {
    color: #e2e8f0;
    font-size: 0.88rem;
    margin: 6px 0 10px 0;
    line-height: 1.5;
}
.shield-badge {
    background: rgba(16, 185, 129, 0.25);
    color: #34d399;
    font-weight: 800;
    font-size: 0.75rem;
}
.shield-force-btn {
    background: rgba(30, 41, 59, 0.9);
    border: 1px solid rgba(245, 158, 11, 0.4);
    color: #fbbf24;
    font-size: 0.82rem;
    font-weight: 600;
}

/* Readonly fields & Notes */
.cc-readonly-input {
    background: rgba(15,23,42,0.6);
    font-weight: 700;
    color: #94a3b8;
}
.cc-consent-box {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    text-transform: none;
    font-weight: normal;
    color: #cbd5e1;
    background: rgba(15, 23, 42, 0.4);
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid rgba(255,255,255,0.06);
}
.cc-safe-mode-note {
    background: rgba(15, 23, 42, 0.4);
    border: 1px dashed rgba(245, 158, 11, 0.4);
    border-radius: 12px;
    padding: 12px 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

/* Recent Checks List */
.recent-inquiry-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px;
    border-radius: 10px;
    background: rgba(15, 23, 42, 0.5);
    border: 1px solid rgba(255, 255, 255, 0.05);
    margin-bottom: 8px;
    transition: all 0.2s ease;
}
.recent-inquiry-row:hover {
    background: rgba(30, 41, 59, 0.7);
    border-color: rgba(59, 130, 246, 0.3);
}
.rc-name {
    font-weight: 700;
    color: #ffffff;
    font-size: 0.88rem;
}
.rc-meta {
    font-size: 0.75rem;
    color: #94a3b8;
    margin-top: 2px;
}

/* Step 2 Metric Boxes & Gauge */
.cc-metric-box {
    background: rgba(15, 23, 42, 0.7);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 14px;
    padding: 20px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}
.cc-metric-box.center-align {
    text-align: center;
    align-items: center;
}
.cc-metric-title {
    font-size: 0.75rem;
    color: #94a3b8;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.cc-metric-val {
    font-size: 1.8rem;
    font-weight: 800;
    color: #ffffff;
    margin-top: 4px;
}
.cc-metric-sub {
    font-size: 0.75rem;
    color: #94a3b8;
    margin-top: 6px;
}

.score-circle-container {
    position: relative;
    width: 160px;
    height: 160px;
    margin: 0 auto 12px;
}
.score-circle-svg {
    transform: rotate(-90deg);
    width: 160px;
    height: 160px;
}
.score-circle-bg {
    fill: none;
    stroke: rgba(255, 255, 255, 0.08);
    stroke-width: 12;
}
.score-circle-progress {
    fill: none;
    stroke-width: 12;
    stroke-linecap: round;
    transition: stroke-dashoffset 1s ease-in-out, stroke 0.5s ease;
}
.score-inner-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    text-align: center;
}
.score-inner-val {
    font-size: 2.2rem;
    font-weight: 800;
    color: #ffffff;
    line-height: 1;
}

/* Table styles */
.cc-table-wrap {
    overflow-x: auto;
    background: rgba(15,23,42,0.6);
    border-radius: 12px;
    border: 1px solid rgba(255,255,255,0.08);
}
.cc-table-thead {
    border-bottom: 1px solid rgba(255,255,255,0.08);
    color: #94a3b8;
    background: rgba(30, 41, 59, 0.4);
}

/* Modal Popup */
.cc-modal-backdrop {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.75);
    backdrop-filter: blur(6px);
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: fadeIn 0.2s ease;
}
.cc-modal {
    background: #0f172a;
    border: 1px solid rgba(255, 255, 255, 0.15);
    border-radius: 20px;
    max-width: 540px;
    width: 100%;
    padding: 26px;
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.8), 0 0 0 1px rgba(59, 130, 246, 0.2);
    position: relative;
}
.cc-modal-details {
    background: rgba(15, 23, 42, 0.8);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 16px;
}

/* ========================================================================= */
/* ☀️ LIGHT THEME COMPATIBILITY OVERRIDES (Clean, Crisp, Harmonious Contrast) */
/* ========================================================================= */
body.light-theme .cc-hero-title {
    color: #0f172a !important;
}
body.light-theme .cc-section-title {
    color: #0f172a !important;
}
body.light-theme .cc-label {
    color: #334155 !important;
}

/* Light Theme Stepper */
body.light-theme .cc-stepper {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.04) !important;
}
body.light-theme .cc-step-item {
    color: #64748b !important;
}
body.light-theme .cc-step-item.active {
    background: rgba(37, 99, 235, 0.08) !important;
    color: #1d4ed8 !important;
    border-color: rgba(37, 99, 235, 0.25) !important;
    box-shadow: 0 2px 8px rgba(37, 99, 235, 0.08) !important;
}
body.light-theme .cc-step-item.completed {
    color: #059669 !important;
}
body.light-theme .cc-step-num {
    background: #f1f5f9 !important;
    color: #475569 !important;
}
body.light-theme .cc-step-item.active .cc-step-num {
    background: #2563eb !important;
    color: #ffffff !important;
}
body.light-theme .cc-step-item.completed .cc-step-num {
    background: #10b981 !important;
    color: #ffffff !important;
}
body.light-theme .cc-step-arrow {
    color: #cbd5e1 !important;
}

/* Light Theme Hero Bar */
body.light-theme .cc-hero-card {
    background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%) !important;
    border: 1px solid #e2e8f0 !important;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05) !important;
}
body.light-theme .cc-hero-card::after {
    background: radial-gradient(circle, rgba(59, 130, 246, 0.08) 0%, transparent 70%) !important;
}
body.light-theme .cc-wallet-pill {
    background: #ffffff !important;
    border: 1px solid rgba(59, 130, 246, 0.3) !important;
    box-shadow: 0 2px 6px rgba(59, 130, 246, 0.08) !important;
}
body.light-theme .cc-wallet-label {
    color: #64748b !important;
}
body.light-theme .cc-wallet-val {
    color: #0284c7 !important;
}
body.light-theme .cc-hero-btn {
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    color: #334155 !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
}
body.light-theme .cc-hero-btn:hover {
    background: #f1f5f9 !important;
    color: #0f172a !important;
}

/* Light Theme Customer Search & Dropdown */
body.light-theme .cc-search-input {
    background: #ffffff !important;
    border: 1.5px solid #cbd5e1 !important;
    color: #0f172a !important;
}
body.light-theme .cc-search-input:focus {
    border-color: #2563eb !important;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15) !important;
}
body.light-theme .cc-dropdown-list {
    background: #ffffff !important;
    border: 1.5px solid #3b82f6 !important;
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15) !important;
}
body.light-theme .cc-dropdown-header {
    background: #f8fafc !important;
    border-bottom: 1px solid #e2e8f0 !important;
    color: #64748b !important;
}
body.light-theme .customer-select-item {
    border-bottom: 1px solid #f1f5f9 !important;
}
body.light-theme .customer-select-item:hover {
    background: #eff6ff !important;
}
body.light-theme .customer-name-text {
    color: #0f172a !important;
}
body.light-theme .customer-meta-text {
    color: #64748b !important;
}
body.light-theme .customer-meta-label {
    color: #475569 !important;
}

/* Light Theme Selected Card */
body.light-theme .cc-selected-card {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
}
body.light-theme .cc-selected-name {
    color: #0f172a !important;
}

/* Light Theme Bureau Selection Cards */
body.light-theme .bureau-card {
    background: #ffffff !important;
    border: 2px solid #e2e8f0 !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03) !important;
}
body.light-theme .bureau-card:hover {
    border-color: #93c5fd !important;
    background: #f8fafc !important;
}
body.light-theme .bureau-card.selected {
    border-color: #2563eb !important;
    background: rgba(37, 99, 235, 0.06) !important;
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.12) !important;
}
body.light-theme .bureau-title {
    color: #0f172a !important;
}
body.light-theme .bureau-desc {
    color: #64748b !important;
}
body.light-theme .bureau-pill {
    background: #f1f5f9 !important;
    color: #475569 !important;
}

/* Light Theme Duplicate Shield Box */
body.light-theme .duplicate-shield-box {
    background: linear-gradient(135deg, #ecfdf5 0%, #f0fdf4 100%) !important;
    border: 2px solid #10b981 !important;
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.12) !important;
}
body.light-theme .shield-title {
    color: #065f46 !important;
}
body.light-theme .shield-desc {
    color: #1e293b !important;
}
body.light-theme .shield-badge {
    background: #d1fae5 !important;
    color: #065f46 !important;
}
body.light-theme .shield-force-btn {
    background: #ffffff !important;
    border: 1px solid #f59e0b !important;
    color: #b45309 !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05) !important;
}
body.light-theme .shield-force-btn:hover {
    background: #fffbeb !important;
}

/* Light Theme Form Controls */
body.light-theme .cc-readonly-input {
    background: #f8fafc !important;
    color: #334155 !important;
    border: 1px solid #cbd5e1 !important;
}
body.light-theme .cc-consent-box {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
    color: #334155 !important;
}
body.light-theme .cc-safe-mode-note {
    background: #fffbeb !important;
    border: 1px dashed #f59e0b !important;
    color: #92400e !important;
}

/* Light Theme Recent Checks */
body.light-theme .recent-inquiry-row {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
}
body.light-theme .recent-inquiry-row:hover {
    background: #eff6ff !important;
    border-color: #bfdbfe !important;
}
body.light-theme .rc-name {
    color: #0f172a !important;
}
body.light-theme .rc-meta {
    color: #64748b !important;
}

/* Light Theme Step 2 Metrics & Gauge */
body.light-theme .cc-metric-box {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
}
body.light-theme .cc-metric-title {
    color: #64748b !important;
}
body.light-theme .cc-metric-val {
    color: #0f172a !important;
}
body.light-theme .cc-metric-sub {
    color: #64748b !important;
}
body.light-theme .score-circle-bg {
    stroke: #e2e8f0 !important;
}
body.light-theme .score-inner-val {
    color: #0f172a !important;
}

/* Light Theme Trade Lines Table */
body.light-theme .cc-table-wrap {
    background: #ffffff !important;
    border: 1px solid #e2e8f0 !important;
}
body.light-theme .cc-table-thead {
    background: #f1f5f9 !important;
    color: #475569 !important;
    border-bottom: 1px solid #e2e8f0 !important;
}
body.light-theme .cc-table-wrap table tr {
    border-bottom-color: #f1f5f9 !important;
}
body.light-theme .cc-table-wrap table td {
    color: #1e293b !important;
}
body.light-theme .json-box {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
    color: #0369a1 !important;
}

/* Light Theme EMI Calculator */
body.light-theme #variantSelectGroup {
    background: #eff6ff !important;
    border-color: #bfdbfe !important;
}
body.light-theme #variantSelect {
    background: #ffffff !important;
    color: #0f172a !important;
    border-color: #3b82f6 !important;
}
body.light-theme #variantSelect option {
    background: #ffffff !important;
    color: #0f172a !important;
}
body.light-theme .emi {
    background: #ffffff !important;
    border: 1.5px solid #e2e8f0 !important;
}
body.light-theme .emi:hover {
    border-color: #93c5fd !important;
    background: #f8fafc !important;
}
body.light-theme .emi.selected {
    border-color: #2563eb !important;
    background: rgba(37, 99, 235, 0.08) !important;
    box-shadow: 0 4px 15px rgba(37, 99, 235, 0.12) !important;
}
body.light-theme .emi div {
    color: #0f172a !important;
}

/* Light Theme Safeguard Modal */
body.light-theme .cc-modal {
    background: #ffffff !important;
    border: 1px solid #cbd5e1 !important;
    box-shadow: 0 25px 60px rgba(0, 0, 0, 0.18) !important;
}
body.light-theme .cc-modal h3 {
    color: #0f172a !important;
}
body.light-theme .cc-modal-details {
    background: #f8fafc !important;
    border: 1px solid #e2e8f0 !important;
}
body.light-theme .cc-modal-details span {
    color: #64748b !important;
}
body.light-theme .cc-modal-details strong {
    color: #0f172a !important;
}
body.light-theme #modalDuplicateWarning {
    background: #fef2f2 !important;
    border-color: #ef4444 !important;
}
body.light-theme #modalDuplicateWarning p {
    color: #991b1b !important;
}
body.light-theme #modalDuplicateWarning span {
    color: #7f1d1d !important;
}
body.light-theme #modalDuplicateWarning label {
    color: #991b1b !important;
}
body.light-theme .cc-modal-cancel-btn {
    background: #f1f5f9 !important;
    border: 1px solid #cbd5e1 !important;
    color: #475569 !important;
}
body.light-theme .cc-modal-cancel-btn:hover {
    background: #e2e8f0 !important;
    color: #0f172a !important;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<div class="cc-container">

    <!-- CLEAN MINIMAL TOP HEADER -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
        <h2 class="cc-section-title" style="font-size: 1.35rem;">
            Credit Bureau Assessment & EMI Financing
        </h2>
        <div>
            <a href="customer-create.php" class="btn" style="background: var(--primary); color: #fff; font-size: 0.88rem; font-weight: 700; display: inline-flex; align-items: center; gap: 8px;">
                <i data-lucide="user-plus"></i> + Add Customer
            </a>
        </div>
    </div>

    <!-- 3-STEP INTERACTIVE PROGRESS BAR -->
    <div class="cc-stepper">
        <div class="cc-step-item active" id="stepperTab1" onclick="switchStep(1)">
            <span class="cc-step-num">1</span>
            <span>1. Customer & Bureau Selection</span>
        </div>
        <span class="cc-step-arrow">➔</span>
        <div class="cc-step-item" id="stepperTab2" onclick="switchStep(2)">
            <span class="cc-step-num">2</span>
            <span>2. Credit Report & Bureau Health</span>
        </div>
        <span class="cc-step-arrow">➔</span>
        <div class="cc-step-item" id="stepperTab3" onclick="switchStep(3)">
            <span class="cc-step-num">3</span>
            <span>3. Product EMI & Financing</span>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- STEP 1: CUSTOMER SELECTION, DUPLICATE DETECTION & SAFEGUARD CONFIRMATION -->
    <!-- ========================================================================= -->
    <div id="step1Container">
        <div class="card" style="margin-bottom: 24px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; border-bottom: 1px solid var(--border-color); padding-bottom: 14px;">
                <h3 class="cc-section-title">
                    <span style="background: var(--primary); width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; color:#fff;">1</span>
                    Select Customer for Bureau Inquiry
                </h3>
                <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #059669; border: 1px solid rgba(16, 185, 129, 0.3);">
                    🛡️ Duplicate-Debit Prevention On
                </span>
            </div>

            <!-- Customer Search Field with Fast Filter -->
            <div style="position: relative; margin-bottom: 16px;">
                <label style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <span class="cc-label" style="margin-bottom:0;">Search Registered Customer (Name, PAN, or Mobile Phone) *</span>
                    <button type="button" id="clearSelectionBtn" style="display: none; background: none; border: none; color: #ef4444; cursor: pointer; font-size: 0.8rem; font-weight: 700;" onclick="clearCustomerSelection()">
                        ✕ Clear / Search Another
                    </button>
                </label>

                <div style="position: relative;">
                    <input type="hidden" name="customer_id" id="customerIdSelect">
                    <input type="text" id="customerSearchInput" 
                           class="cc-search-input"
                           placeholder="🔍 Type customer name, 10-digit PAN (e.g. ABCDE1234F), or 10-digit Mobile..." 
                           autocomplete="off" 
                           onclick="showCustomerDropdown()"
                           onfocus="showCustomerDropdown()" 
                           oninput="filterCustomers()">
                    <span id="custSelectCheck" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: #10b981; display: none; font-weight: bold; font-size: 1.3rem;">✓</span>
                </div>

                <!-- Fast Auto-Suggest Dropdown List -->
                <div id="customerDropdownList" class="cc-dropdown-list">
                    <div class="cc-dropdown-header">
                        Registered Customers (<?=count($customers)?> Records)
                    </div>

                    <?php foreach($customers as $c): 
                        $hasReport = !empty($c['credit_report_json']);
                        $hasScore = !empty($c['credit_score']) && (int)$c['credit_score'] > 0;
                        $lastChecked = !empty($c['last_checked_at']) ? date('d M Y, h:i A', strtotime($c['last_checked_at'])) : '';
                        $lastCheckedShort = !empty($c['last_checked_at']) ? date('d M Y', strtotime($c['last_checked_at'])) : '';
                    ?>
                        <div class="customer-select-item" 
                             data-id="<?=$c['id']?>" 
                             data-name="<?=e($c['name'])?>" 
                             data-pan="<?=e($c['pan'])?>" 
                             data-mobile="<?=e($c['mobile'])?>" 
                             data-score="<?=e($c['credit_score'])?>"
                             data-lastcheck="<?=$lastChecked?>"
                             data-lastcheck-short="<?=$lastCheckedShort?>"
                             data-provider="<?=e($c['last_provider'] ?? 'Equifax')?>"
                             data-checks="<?=e($c['total_checks'] ?? 0)?>"
                             data-has-report="<?=$hasReport ? '1' : '0'?>"
                             data-report='<?=e($c['credit_report_json'] ?? '')?>'
                             onclick="selectCustomerItem(this)">
                            <div>
                                <div class="customer-name-text">
                                    👤 <?=e($c['name'])?>
                                </div>
                                <div class="customer-meta-text">
                                    <span><strong class="customer-meta-label">PAN:</strong> <?=e($c['pan'] ?: 'N/A')?></span>
                                    <span><strong class="customer-meta-label">Mobile:</strong> <?=e($c['mobile'] ?: 'N/A')?></span>
                                </div>
                            </div>
                            <div style="text-align: right;">
                                <?php if ($hasScore || $hasReport): ?>
                                    <span class="badge" style="background: rgba(16, 185, 129, 0.2); color: #059669; border: 1px solid rgba(16, 185, 129, 0.4); font-size: 0.76rem; font-weight: 800;">
                                        Score: <?=e($c['credit_score'] ?: 'Saved')?> · Checked
                                    </span>
                                    <?php if (!empty($lastCheckedShort)): ?>
                                        <div style="font-size: 0.7rem; color: #94a3b8; margin-top: 3px;">📅 <?=$lastCheckedShort?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="badge" style="background: rgba(100, 116, 139, 0.15); color: #64748b; font-size: 0.72rem;">
                                        ⚡ Never Checked
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <div id="noCustFound" style="display: none; padding: 20px; text-align: center; color: var(--text-muted); font-size: 0.9rem;">
                        No customer found matching your search. <br>
                        <a href="customer-create.php" style="color: var(--primary); font-weight: 700; text-decoration: underline; margin-top: 6px; display: inline-block;">+ Register New Customer</a>
                    </div>
                </div>
            </div>

            <!-- SELECTED CUSTOMER SUMMARY CARD -->
            <div id="selectedCustomerCard" class="cc-selected-card">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <div style="width: 44px; height: 44px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #1d4ed8); display: flex; align-items: center; justify-content: center; font-size: 1.1rem; font-weight: 800; color: #fff;" id="cardCustInitials">
                            CU
                        </div>
                        <div>
                            <div class="cc-selected-name" id="cardCustName">Customer Name</div>
                            <div style="font-size: 0.8rem; color: #64748b; display: flex; gap: 12px; margin-top: 2px;">
                                <span>PAN: <strong style="color: #2563eb;" id="cardCustPan">-</strong></span>
                                <span>Mobile: <strong style="color: #2563eb;" id="cardCustMobile">-</strong></span>
                            </div>
                        </div>
                    </div>
                    <div id="cardCustScoreBadge"></div>
                </div>
            </div>

            <!-- 🛡️ DUPLICATE CHECK PROTECTION SHIELD BANNER -->
            <div id="duplicateProtectionShield" style="display: none;" class="duplicate-shield-box">
                <div style="display: flex; align-items: flex-start; gap: 16px; flex-wrap: wrap;">
                    <div style="font-size: 2.2rem; line-height: 1;">🛡️</div>
                    <div style="flex: 1; min-width: 260px;">
                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                            <strong class="shield-title">ACTIVE REPORT FOUND IN SYSTEM (SAFE MODE)</strong>
                            <span class="badge shield-badge">100% FREE RE-USE</span>
                        </div>
                        <p class="shield-desc">
                            This customer was previously verified on <strong style="color: #2563eb;" id="shieldLastCheckDate">-</strong> (Score: <strong style="color: #059669;" id="shieldLastScore">-</strong>). 
                            Bureau credit reports remain valid for <strong>30 to 90 days</strong>. You do <strong>NOT</strong> need to spend money again!
                        </p>
                        
                        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 12px;">
                            <button type="button" class="btn" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #ffffff; font-weight: 800; font-size: 0.92rem; padding: 12px 22px; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);" onclick="openStoredReport()">
                                👁️ View Stored Report & Proceed (FREE - ₹0 Debit) ➔
                            </button>
                            <button type="button" class="btn shield-force-btn" onclick="triggerPaidCheckPrompt(true)">
                                ⚠️ Force Paid Re-check from Bureau
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- BUREAU SELECTION & INQUIRY FORM -->
            <form id="cibilInquiryForm">
                <div style="margin-top: 16px;">
                    <label class="cc-label">
                        Select Credit Bureau / Report Provider:
                    </label>

                    <div class="bureau-grid" style="grid-template-columns: 1fr;">
                        <!-- Transunion PDF Option Card -->
                        <div class="bureau-card selected" id="cardTransunionPdf" style="cursor: default;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                                <div style="display: flex; align-items: center;">
                                    <input type="radio" name="report_type_radio" value="transunion_pdf" checked class="bureau-radio" id="radioTransunionPdf">
                                    <strong class="bureau-title" style="font-size: 1.05rem;">Credit Report Transunion PDF</strong>
                                </div>
                                <span class="badge" style="background: #0284c7; color: #fff; font-weight: 800; font-size: 0.82rem;">₹80.00 Fee</span>
                            </div>
                            <p class="bureau-desc">
                                Official digitally generated Transunion CIBIL PDF credit certificate via FinPay Ultra API.
                            </p>
                            <div style="margin-top: 10px; display: flex; gap: 6px; flex-wrap: wrap;">
                                <span class="badge" style="background: rgba(2, 132, 199, 0.15); color: #0284c7; font-size: 0.72rem;">⭐ Official Transunion PDF</span>
                                <span class="badge bureau-pill">Direct Bureau Certificate</span>
                            </div>
                        </div>
                    </div>
                    <input type="hidden" name="report_type" id="reportTypeSelect" value="transunion_pdf">
                </div>

                <!-- Verified Parameters Grid -->
                <div class="form-grid" style="margin-top: 16px;">
                    <div class="field">
                        <label class="cc-label">Customer Registered Mobile</label>
                        <input type="text" id="dispMobile" class="cc-readonly-input" placeholder="Auto-populated upon customer selection..." readonly>
                    </div>

                    <div class="field">
                        <label class="cc-label">Customer PAN Card Number</label>
                        <input type="text" id="dispPan" class="cc-readonly-input" placeholder="Auto-populated upon customer selection..." readonly>
                    </div>

                    <div class="field" style="grid-column: span 2;">
                        <label class="cc-label">Customer Gender *</label>
                        <select id="custGender" class="cc-readonly-input" style="width: 100%; border-radius: 8px; padding: 10px; background: var(--input-bg); color: var(--text-color);">
                            <option value="male" selected>male</option>
                            <option value="female">female</option>
                        </select>
                    </div>

                    <div class="field full">
                        <label class="cc-consent-box">
                            <input type="checkbox" id="consentCheck" checked required style="width: 18px; height: 18px; accent-color: var(--primary);">
                            <span>Customer explicit authorization and consent obtained for credit bureau score inquiry (consent=Y).</span>
                        </label>
                    </div>

                    <!-- SUBMIT BUTTON AREA (Protected from Accidental Clicks) -->
                    <div class="field full" style="margin-top: 8px;">
                        <div id="newCheckActionArea">
                            <button type="button" class="btn" id="btnInitiateCheck" style="width: 100%; padding: 15px; font-size: 1rem; font-weight: 800; background: linear-gradient(135deg, #2563eb, #1d4ed8); color:#fff; box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);" onclick="triggerPaidCheckPrompt(false)">
                                <i data-lucide="shield-check"></i> ⚡ Check Credit Score via Bureau (Paid Pull)
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- RECENT BUREAU INQUIRIES DRAWER -->
        <?php if (!empty($recentChecks)): ?>
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 8px;">
                    <h4 class="cc-section-title" style="font-size: 0.95rem;">
                        <i data-lucide="history" style="width: 18px; height: 18px; color: #2563eb;"></i>
                        Recent Bureau Checks (Avoid Duplicate Checks for Same Customers)
                    </h4>
                    <span style="font-size: 0.78rem; color: #64748b;">Click any person to view saved report for free</span>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 10px;">
                    <?php foreach($recentChecks as $rc): ?>
                        <div class="recent-inquiry-row" onclick="quickSelectCustomer(<?=$rc['customer_id']?>)" style="cursor: pointer;" title="Click to view existing report without paying again">
                            <div>
                                <div class="rc-name"><?=e($rc['customer_name'] ?? 'Customer')?></div>
                                <div class="rc-meta">
                                    PAN: <?=e($rc['customer_pan'] ?: 'N/A')?> · <?=date('d M Y, h:i A', strtotime($rc['created_at']))?>
                                </div>
                            </div>
                            <div style="text-align: right; display: flex; align-items: center; gap: 8px;">
                                <span class="badge <?=(int)$rc['score'] < 600 ? 'badge-danger' : 'badge-success'?>" style="font-size: 0.75rem;">
                                    Score: <?=e($rc['score'] ?: 'Saved')?>
                                </span>
                                <span style="font-size: 0.75rem; color: #2563eb; font-weight: 700;">View ➔</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- ========================================================================= -->
    <!-- STEP 2: CREDIT SCORE REPORT & DETAILED FINANCIAL HEALTH                   -->
    <!-- ========================================================================= -->
    <div id="step2Container" style="display: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
            <button type="button" class="btn cc-hero-btn" onclick="switchStep(1)">
                ← Back to Customer Search (Step 1)
            </button>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn" id="btnContinueToEmi" style="background: linear-gradient(135deg, #10b981, #059669); color:#fff; font-weight: 800;" onclick="switchStep(3)">
                    Continue to EMI Calculator (Step 3) ➔
                </button>
            </div>
        </div>

        <!-- REPORT CARD -->
        <div class="card" id="reportResultCard">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 16px; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
                <div>
                    <span class="badge badge-success" id="rptProviderBadge">Equifax Bureau Verification</span>
                    <h3 class="cc-hero-title" style="font-size: 1.35rem; margin-top: 6px;" id="rptCustName">Customer Credit Report</h3>
                    <p class="muted" style="margin-top: 2px;" id="rptOrderMeta">Transaction Ref: -</p>
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;" id="reportActionBtnGroup">
                    <a id="btnExperianDirectPdf" class="btn" style="display:none; background: #059669; color:#fff;" target="_blank" href="#">
                        <i data-lucide="file-text"></i> 📥 Open Bureau Official PDF
                    </a>
                    <button class="btn" type="button" onclick="printOfficialPdf()"><i data-lucide="printer"></i> 🖨️ Print Report</button>
                    <button class="btn cc-hero-btn" type="button" onclick="toggleJsonView()">
                        <i data-lucide="code"></i> { } View JSON
                    </button>
                </div>
            </div>

            <!-- Visual Score Dial & Metric Boxes Grid -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
                <!-- Circular Score Gauge -->
                <div class="cc-metric-box center-align">
                    <div class="cc-metric-title" style="margin-bottom: 8px;">CIBIL / CRIF SCORE</div>
                    
                    <div class="score-circle-container">
                        <svg class="score-circle-svg" viewBox="0 0 160 160">
                            <circle class="score-circle-bg" cx="80" cy="80" r="66" />
                            <circle class="score-circle-progress" id="scoreCircleProgress" cx="80" cy="80" r="66" stroke="#10b981" stroke-dasharray="414" stroke-dashoffset="100" />
                        </svg>
                        <div class="score-inner-text">
                            <div class="score-inner-val" id="rptScoreVal">---</div>
                            <div style="font-size: 0.7rem; color: #94a3b8; margin-top: 4px;">out of 900</div>
                        </div>
                    </div>

                    <span class="badge badge-good" id="rptScoreBadge" style="font-size: 0.8rem; padding: 6px 14px;">Good Rating</span>
                </div>

                <!-- Reported Accounts -->
                <div class="cc-metric-box">
                    <span class="cc-metric-title">Reported Accounts</span>
                    <div class="cc-metric-val" id="rptTotalAccounts">0 Active</div>
                    <span class="cc-metric-sub">Total trade lines registered</span>
                </div>

                <!-- Total Outstanding Balance -->
                <div class="cc-metric-box">
                    <span class="cc-metric-title">Total Outstanding</span>
                    <div class="cc-metric-val" style="color: #0284c7;" id="rptTotalBalance">₹0</div>
                    <span class="cc-metric-sub">Combined active balance</span>
                </div>

                <!-- Past Due Amount -->
                <div class="cc-metric-box">
                    <span class="cc-metric-title">Past Due / Default</span>
                    <div class="cc-metric-val" style="color: #ef4444;" id="rptPastDue">₹0</div>
                    <span class="cc-metric-sub">Overdue payment alerts</span>
                </div>
            </div>

            <!-- LOW CREDIT SCORE INELIGIBLE WARNING CARD -->
            <div id="lowScoreIneligibleAlert" style="display: none; margin-bottom: 24px; background: rgba(239, 68, 68, 0.1); border: 2px solid #ef4444; text-align: center; padding: 26px 20px; border-radius: 16px;">
                <div style="font-size: 2.5rem; margin-bottom: 8px;">🚫</div>
                <h3 style="color: #ef4444; font-size: 1.25rem; font-weight: 800; margin-bottom: 6px;">
                    Store Financing Blocked (Credit Score Below 600)
                </h3>
                <p style="color: #475569; font-size: 0.92rem; max-width: 650px; margin: 0 auto 12px auto; line-height: 1.5;">
                    Customer credit score is <strong style="color: #ef4444; font-size: 1.15rem;" id="ineligibleScoreText">---</strong>. Under store credit risk policy, financing applications cannot be sanctioned or submitted for scores under <strong>600</strong>.
                </p>
                <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(239, 68, 68, 0.15); color: #b91c1c; padding: 6px 18px; border-radius: 20px; font-weight: 700; font-size: 0.8rem; border: 1px solid rgba(239, 68, 68, 0.4);">
                    🔒 Ineligible for Store Financing
                </div>
            </div>

            <!-- TRADE LINES TABLE -->
            <div style="margin-top: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                    <h4 class="cc-section-title" style="font-size: 0.95rem;">
                        Credit Accounts & Trade Lines Summary
                    </h4>
                </div>
                <div class="cc-table-wrap">
                    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem; text-align: left;">
                        <thead>
                            <tr class="cc-table-thead">
                                <th style="padding: 12px;">#</th>
                                <th style="padding: 12px;">Financial Institution</th>
                                <th style="padding: 12px;">Account Type</th>
                                <th style="padding: 12px;">Sanctioned</th>
                                <th style="padding: 12px;">Current Balance</th>
                                <th style="padding: 12px;">Past Due</th>
                                <th style="padding: 12px;">Opened Date</th>
                                <th style="padding: 12px;">Status</th>
                            </tr>
                        </thead>
                        <tbody id="accountsTableBody">
                            <tr><td colspan="8" style="text-align:center; padding: 20px; color: var(--text-muted);">No accounts loaded</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <pre class="json-box" id="rawJsonBox" style="display:none; padding:16px; border-radius:12px; max-height:400px; overflow:auto; font-family:monospace; font-size:0.8rem; margin-top:20px; white-space:pre-wrap; word-break:break-all;"></pre>
        </div>

        <div style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center;">
            <button type="button" class="btn cc-hero-btn" onclick="switchStep(1)">
                ← Back to Customer Selection
            </button>
            <button type="button" class="btn" style="background: linear-gradient(135deg, #10b981, #059669); color:#fff; font-weight: 800;" onclick="switchStep(3)">
                Continue to EMI Calculator (Step 3) ➔
            </button>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- STEP 3: PRODUCT SELECTION & AUTO EMI CALCULATOR                           -->
    <!-- ========================================================================= -->
    <div id="step3Container" style="display: none;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
            <button type="button" class="btn cc-hero-btn" onclick="switchStep(2)">
                ← Back to Bureau Report (Step 2)
            </button>
            <span style="font-size: 0.85rem; color: #64748b; font-weight: 700;">Step 3 of 3: Store Finance Application Issuance</span>
        </div>

        <!-- INELIGIBLE / NO CHECK BLOCKED WARNING CARD IN STEP 3 -->
        <div id="step3BlockAlert" style="display: none; margin-bottom: 24px; background: rgba(239, 68, 68, 0.08); border: 2px solid #ef4444; border-radius: 16px; padding: 26px 20px; text-align: center;">
            <div style="font-size: 2.5rem; margin-bottom: 8px;">🚫</div>
            <h3 style="color: #ef4444; font-size: 1.25rem; font-weight: 800; margin-bottom: 6px;" id="step3BlockTitle">
                Finance Application Blocked
            </h3>
            <p style="color: #475569; font-size: 0.92rem; max-width: 650px; margin: 0 auto 16px auto; line-height: 1.5;" id="step3BlockDesc">
                Customer credit score has not been verified. A credit bureau check is mandatory before submitting a financing application.
            </p>
            <button type="button" class="btn" style="background: #2563eb; color: #fff; font-weight: 800; font-size: 0.88rem;" onclick="switchStep(1)">
                ⚡ Perform Bureau Credit Check (Step 1)
            </button>
        </div>

        <!-- PRODUCT EMI CALCULATOR CARD -->
        <div class="card" id="emiCalcCard">
            <h3 class="cc-section-title" style="margin-bottom: 18px;">
                <span style="background: var(--primary); width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; color:#fff;">3</span>
                Select Product & Configure EMI Financing
            </h3>

            <div class="form-grid">
                <div class="field full">
                    <label class="cc-label">Select Product from Store Inventory</label>
                    <select id="productSelect" onchange="onProductSelect()" style="font-weight: 600; height: 44px;">
                        <option value="">-- Choose Product --</option>
                        <?php foreach($products as $p): 
                            $pVars = $variantsByProduct[$p['id']] ?? [];
                        ?>
                            <option value="<?=$p['id']?>" data-price="<?=$p['selling_price']?>" data-variants='<?=htmlspecialchars(json_encode($pVars))?>'>
                                <?=e($p['name'])?> - <?=money($p['selling_price'])?> (Stock: <?=$p['stock']?>) <?=!empty($pVars)?'['.count($pVars).' Variants]':''?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field full" id="variantSelectGroup" style="display: none; background: rgba(30, 41, 59, 0.6); padding: 14px; border-radius: 12px; border: 1px solid rgba(59, 130, 246, 0.4); margin-bottom: 10px;">
                    <label style="color: #2563eb; font-weight: 800; display: flex; align-items: center; gap: 6px; margin-bottom: 8px;">
                        <span>🏷️ Select Product Variant (RAM / Storage & Price)</span>
                    </label>
                    <select id="variantSelect" onchange="onVariantSelect()" style="height: 42px; width: 100%; padding: 0 12px; font-weight: 700; border-radius: 8px;">
                        <!-- Populated dynamically -->
                    </select>
                </div>

                <div class="field">
                    <label class="cc-label">Product Selling Price (₹)</label>
                    <input type="number" id="calcPrice" value="0" oninput="recalculateEMI()" style="font-weight: 700;">
                </div>

                <div class="field">
                    <label class="cc-label">Down Payment Amount (₹)</label>
                    <input type="number" id="calcDown" value="0" oninput="recalculateEMI()" style="font-weight: 700;">
                </div>

                <div class="field">
                    <label class="cc-label" style="color: #2563eb;">Interest Rate (% p.a.) ✏️</label>
                    <input type="number" step="0.1" min="0" max="100" id="calcRate" value="12.0" oninput="recalculateEMI()" style="color: #059669; font-weight: 800; border: 1.5px solid var(--primary);">
                </div>

                <div class="field">
                    <label class="cc-label">Financed Principal Amount</label>
                    <input type="text" id="calcPrincipal" value="₹0" readonly class="cc-readonly-input" style="color: #0284c7; font-weight: 800;">
                </div>
            </div>

            <div style="margin-top: 24px;">
                <label class="cc-label" style="margin-bottom: 12px;">Select Preferred EMI Tenure Option:</label>
                <div class="emi-grid" id="emiCardsGrid">
                    <!-- EMI Tenure Cards populated via JS -->
                </div>
            </div>

            <div style="margin-top: 26px;">
                <button class="btn" id="btnSubmitFinanceApp" style="width: 100%; padding: 16px; font-size: 1.05rem; font-weight: 800; background: linear-gradient(135deg, #10b981, #059669); color:#fff; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);" onclick="submitFinanceApplication()">
                    <i data-lucide="check-circle"></i> 🚀 Save & Issue Store Finance Application
                </button>
            </div>
        </div>
    </div>

</div>

<!-- ========================================================================= -->
<!-- 🔒 MODAL: CONFIRM PAID CREDIT BUREAU INQUIRY (Anti-Debit Safeguard Modal) -->
<!-- ========================================================================= -->
<div class="cc-modal-backdrop" id="safeguardConfirmModal">
    <div class="cc-modal">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 40px; height: 40px; border-radius: 50%; background: rgba(245, 158, 11, 0.15); display: flex; align-items: center; justify-content: center; font-size: 1.25rem;">
                    🔒
                </div>
                <div>
                    <h3 style="font-size: 1.2rem; font-weight: 800; margin: 0;">Confirm Wallet Deduction</h3>
                    <p style="font-size: 0.78rem; color: #64748b; margin: 2px 0 0 0;">Official Bureau Inquiry Confirmation</p>
                </div>
            </div>
            <button type="button" onclick="closeSafeguardModal()" style="background: none; border: none; color: #64748b; font-size: 1.2rem; cursor: pointer;">✕</button>
        </div>

        <!-- Cost & Wallet Impact Box -->
        <div class="cc-modal-details">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; border-bottom: 1px solid var(--border-color); padding-bottom: 10px;">
                <span style="font-size: 0.85rem;">Customer:</span>
                <strong style="font-size: 0.95rem;" id="modalCustName">Customer</strong>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.85rem;">Bureau Service:</span>
                <strong style="color: #0284c7; font-size: 0.88rem;" id="modalBureauName">Credit Report Transunion PDF</strong>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.85rem;">Inquiry Fee to Deduct:</span>
                <strong style="color: #ef4444; font-size: 1.15rem; font-weight: 800;" id="modalFeeAmount">₹80.00</strong>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                <span style="font-size: 0.85rem;">Current Wallet Balance:</span>
                <span style="font-weight: 700;"><?=money($walletBalance)?></span>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-color); padding-top: 8px;">
                <span style="font-size: 0.85rem;">Balance After Check:</span>
                <strong style="color: #059669; font-weight: 800;" id="modalPostBalance">-</strong>
            </div>
        </div>

        <!-- DUPLICATE WARNING BOX IN MODAL -->
        <div id="modalDuplicateWarning" style="display: none; background: rgba(239, 68, 68, 0.12); border: 1.5px solid #ef4444; border-radius: 12px; padding: 12px; margin-bottom: 16px;">
            <div style="display: flex; align-items: center; gap: 8px; color: #b91c1c; font-weight: 800; font-size: 0.85rem;">
                <span>⚠️ DUPLICATE CHECK WARNING</span>
            </div>
            <p style="font-size: 0.8rem; margin: 6px 0 0 0; line-height: 1.4;">
                This person already has a saved report from <span id="modalWarningDate" style="font-weight: 800;">-</span>. 
                Running this will spend <strong>₹70 / ₹60</strong> again from your wallet!
            </p>
            <label style="display: flex; align-items: center; gap: 8px; margin-top: 10px; cursor: pointer; font-size: 0.8rem; font-weight: 700;">
                <input type="checkbox" id="modalForceAckCheckbox" onchange="onModalAckChange()">
                <span>I confirm that a fresh paid bureau inquiry is required.</span>
            </label>
        </div>

        <!-- ACTION BUTTONS -->
        <div style="display: flex; gap: 10px; margin-top: 10px;">
            <button type="button" class="btn cc-modal-cancel-btn" style="flex: 1;" onclick="closeSafeguardModal()">
                ✕ Cancel (No Charge)
            </button>
            <button type="button" class="btn" id="modalConfirmBtn" style="flex: 1; background: linear-gradient(135deg, #ef4444, #dc2626); color: #ffffff; font-weight: 800;" onclick="executeBureauCheck()">
                ✓ Yes, Deduct & Fetch
            </button>
        </div>
    </div>
</div>

<script>
    // In-memory Customer Map for quick lookups
    const customersData = <?=json_encode($customers)?>;
    const currentWalletBal = <?=floatval($walletBalance)?>;
    
    let selectedCustomerId = null;
    let selectedCustomerObj = null;
    let selectedScore = null;
    let selectedTenure = 6;
    let currentStep = 1;
    let reportAvailable = false;
    let activeBureauProvider = 'transunion_pdf';
    let isForcedRecheck = false;

    // Initialize auto selection if customer_id in URL
    document.addEventListener('DOMContentLoaded', () => {
        const initId = <?=$initialCustomerId?>;
        if (initId > 0) {
            quickSelectCustomer(initId);
        }
    });

    function selectBureauProvider(type) {
        activeBureauProvider = 'transunion_pdf';
        document.getElementById('reportTypeSelect').value = 'transunion_pdf';
    }

    function switchStep(stepNum) {
        if (stepNum > 1 && !selectedCustomerId) {
            alert('Mandatory: Please select a registered customer first.');
            return;
        }

        // CONDITION 1: Prevent accessing Step 2 or Step 3 if credit check is not done
        if (stepNum > 1 && (!reportAvailable || selectedScore === null || selectedScore <= 0)) {
            alert('Credit Check Required: Bureau credit check has not been performed for this customer yet. Please run a credit check or load their saved report first.');
            return;
        }

        // CONDITION 2: Prevent accessing Step 3 if credit score is below 600 or null
        if (stepNum === 3 && (selectedScore === null || selectedScore < 600)) {
            if (selectedScore === null || selectedScore <= 0) {
                alert('Credit Check Required: Bureau credit check has not been performed for this customer yet.');
            } else {
                alert('Store Financing Blocked: Customer credit score (' + selectedScore + ') is below 600. Applications cannot be created or submitted for credit scores under 600.');
            }
            return;
        }

        currentStep = stepNum;
        const step1 = document.getElementById('step1Container');
        const step2 = document.getElementById('step2Container');
        const step3 = document.getElementById('step3Container');

        const tab1 = document.getElementById('stepperTab1');
        const tab2 = document.getElementById('stepperTab2');
        const tab3 = document.getElementById('stepperTab3');

        tab1.classList.remove('active');
        tab2.classList.remove('active');
        tab3.classList.remove('active');

        if (stepNum === 1) {
            step1.style.display = 'block';
            step2.style.display = 'none';
            step3.style.display = 'none';
            tab1.classList.add('active');
        } else if (stepNum === 2) {
            step1.style.display = 'none';
            step2.style.display = 'block';
            step3.style.display = 'none';
            tab2.classList.add('active');
            tab1.classList.add('completed');
            updateEligibilityView();
        } else if (stepNum === 3) {
            step1.style.display = 'none';
            step2.style.display = 'none';
            step3.style.display = 'block';
            tab3.classList.add('active');
            tab1.classList.add('completed');
            tab2.classList.add('completed');
            recalculateEMI();
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function showCustomerDropdown() {
        document.getElementById('customerDropdownList').style.display = 'block';
        filterCustomers();
    }

    function filterCustomers() {
        const q = document.getElementById('customerSearchInput').value.toLowerCase().trim();
        const items = document.querySelectorAll('.customer-select-item');
        let count = 0;
        items.forEach(item => {
            const text = (item.dataset.name + ' ' + (item.dataset.pan || '') + ' ' + (item.dataset.mobile || '')).toLowerCase();
            if (text.includes(q)) {
                item.style.display = 'flex';
                count++;
            } else {
                item.style.display = 'none';
            }
        });
        document.getElementById('noCustFound').style.display = count === 0 ? 'block' : 'none';
    }

    function quickSelectCustomer(custId) {
        const targetEl = document.querySelector(`.customer-select-item[data-id="${custId}"]`);
        if (targetEl) {
            selectCustomerItem(targetEl);
        }
    }

    function selectCustomerItem(el) {
        selectedCustomerId = el.dataset.id;
        selectedCustomerObj = customersData.find(c => c.id == selectedCustomerId);

        document.getElementById('customerIdSelect').value = selectedCustomerId;
        document.getElementById('customerSearchInput').value = `${el.dataset.name} (PAN: ${el.dataset.pan || 'N/A'} | Mobile: ${el.dataset.mobile || 'N/A'})`;
        document.getElementById('dispPan').value = el.dataset.pan || '';
        document.getElementById('dispMobile').value = el.dataset.mobile || '';
        
        // Auto-fill Equifax PDF v2 extra parameter fields
        if (selectedCustomerObj) {
            const v2Dob = document.getElementById('v2Dob');
            const v2Address = document.getElementById('v2Address');
            const v2State = document.getElementById('v2State');
            const v2Pincode = document.getElementById('v2Pincode');
            
            if (v2Dob && selectedCustomerObj.dob) v2Dob.value = selectedCustomerObj.dob;
            if (v2Address && selectedCustomerObj.address) v2Address.value = selectedCustomerObj.address;
            
            const addr = selectedCustomerObj.address || '';
            const pinMatch = addr.match(/\b([1-9][0-9]{5})\b/);
            if (v2Pincode) v2Pincode.value = pinMatch ? pinMatch[1] : '781001';
            
            if (v2State) {
                const knownStates = ['Assam', 'West Bengal', 'Bihar', 'Delhi', 'Maharashtra', 'Karnataka', 'Tamil Nadu', 'Uttar Pradesh', 'Rajasthan', 'Gujarat', 'Punjab', 'Haryana', 'Odisha', 'Kerala', 'Telangana', 'Andhra Pradesh', 'Madhya Pradesh'];
                let detectedState = 'Assam';
                for (const st of knownStates) {
                    if (addr.toLowerCase().includes(st.toLowerCase())) {
                        detectedState = st;
                        break;
                    }
                }
                v2State.value = detectedState;
            }
        }
        
        const rawScore = el.dataset.score;
        selectedScore = (rawScore && parseInt(rawScore) > 0) ? parseInt(rawScore) : null;
        
        document.getElementById('custSelectCheck').style.display = 'block';
        document.getElementById('clearSelectionBtn').style.display = 'inline-block';
        document.getElementById('customerDropdownList').style.display = 'none';

        // Update selected customer summary card
        const card = document.getElementById('selectedCustomerCard');
        card.style.display = 'block';
        document.getElementById('cardCustName').textContent = el.dataset.name;
        document.getElementById('cardCustPan').textContent = el.dataset.pan || 'N/A';
        document.getElementById('cardCustMobile').textContent = el.dataset.mobile || 'N/A';
        document.getElementById('cardCustInitials').textContent = (el.dataset.name || 'CU').substring(0, 2).toUpperCase();

        const scoreBadgeContainer = document.getElementById('cardCustScoreBadge');
        const shieldBox = document.getElementById('duplicateProtectionShield');
        const newCheckArea = document.getElementById('newCheckActionArea');
        const savedReportRaw = el.dataset.report;
        const lastCheckDate = el.dataset.lastcheck;

        // Anti-Debit Duplicate Check Detection
        const hasExistingReport = (savedReportRaw && savedReportRaw.trim() !== '' && savedReportRaw !== 'null') || (el.dataset.hasReport === '1');
        reportAvailable = hasExistingReport && selectedScore !== null && selectedScore > 0;

        if (hasExistingReport || (selectedScore !== null && selectedScore > 0)) {
            // SAFEGUARD ACTIVATED: Customer already has report
            scoreBadgeContainer.innerHTML = `
                <span class="badge ${selectedScore < 600 ? 'badge-danger' : 'badge-success'}" style="font-size:0.85rem; padding: 6px 14px;">
                    Score: ${selectedScore} · Previously Checked
                </span>
            `;

            shieldBox.style.display = 'block';
            document.getElementById('shieldLastScore').textContent = selectedScore;
            document.getElementById('shieldLastCheckDate').textContent = lastCheckDate || el.dataset.lastcheckShort || 'Database Stored';

            // Replace standard submit button with locked/guarded button
            newCheckArea.innerHTML = `
                <div class="cc-safe-mode-note">
                    <div style="font-size: 0.85rem; color: #b45309; font-weight:600;">
                        🛡️ <strong>Safe Mode Active:</strong> A report is already saved. Use the green button above to view without charges.
                    </div>
                    <button type="button" class="btn" style="background: rgba(245, 158, 11, 0.15); border: 1px solid #f59e0b; color: #b45309; font-size: 0.8rem; font-weight: 700;" onclick="triggerPaidCheckPrompt(true)">
                        ⚠️ Run Fresh Bureau Inquiry (Paid)
                    </button>
                </div>
            `;
        } else {
            // New Customer: Never checked before
            scoreBadgeContainer.innerHTML = `
                <span class="badge" style="background: rgba(59, 130, 246, 0.15); color: #2563eb; font-size:0.82rem; padding: 6px 14px;">
                    New Bureau Pull Required
                </span>
            `;
            shieldBox.style.display = 'none';

            newCheckArea.innerHTML = `
                <button type="button" class="btn" id="btnInitiateCheck" style="width: 100%; padding: 15px; font-size: 1rem; font-weight: 800; background: linear-gradient(135deg, #2563eb, #1d4ed8); color:#fff; box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);" onclick="triggerPaidCheckPrompt(false)">
                    <i data-lucide="shield-check"></i> ⚡ Check Credit Score via Bureau (Paid Pull)
                </button>
            `;
        }

        if (window.lucide) lucide.createIcons();
    }

    function clearCustomerSelection() {
        selectedCustomerId = null;
        selectedCustomerObj = null;
        selectedScore = null;
        reportAvailable = false;
        
        document.getElementById('customerIdSelect').value = '';
        document.getElementById('customerSearchInput').value = '';
        document.getElementById('dispPan').value = '';
        document.getElementById('dispMobile').value = '';
        document.getElementById('custSelectCheck').style.display = 'none';
        document.getElementById('clearSelectionBtn').style.display = 'none';
        document.getElementById('selectedCustomerCard').style.display = 'none';
        document.getElementById('duplicateProtectionShield').style.display = 'none';

        document.getElementById('newCheckActionArea').innerHTML = `
            <button type="button" class="btn" id="btnInitiateCheck" style="width: 100%; padding: 15px; font-size: 1rem; font-weight: 800; background: linear-gradient(135deg, #2563eb, #1d4ed8); color:#fff; box-shadow: 0 4px 15px rgba(37, 99, 235, 0.3);" onclick="triggerPaidCheckPrompt(false)">
                <i data-lucide="shield-check"></i> ⚡ Check Credit Score via Bureau (Paid Pull)
            </button>
        `;
        
        switchStep(1);
        if (window.lucide) lucide.createIcons();
    }

    // Close customer dropdown on outside click
    document.addEventListener('click', function(e) {
        const container = document.getElementById('customerSearchInput');
        const dropdown = document.getElementById('customerDropdownList');
        if (container && dropdown && !container.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });

    // OPEN FREE STORED REPORT
    function openStoredReport() {
        if (!selectedCustomerId) {
            alert('Please select a customer first.');
            return;
        }

        const el = document.querySelector(`.customer-select-item[data-id="${selectedCustomerId}"]`);
        const rawJson = el ? el.dataset.report : null;

        if (rawJson && rawJson.trim() !== '' && rawJson !== 'null') {
            try {
                const jsonObj = JSON.parse(rawJson);
                renderCreditReport(jsonObj);
                reportAvailable = true;
                switchStep(2);
                return;
            } catch(e) {
                console.error('Error parsing stored JSON:', e);
            }
        }

        // Fallback lightweight report
        if (selectedScore > 0) {
            const fallbackReport = {
                score: selectedScore,
                provider: el ? (el.dataset.provider || 'Equifax Bureau') : 'Equifax Bureau',
                customer: { name: el ? el.dataset.name : 'Customer' },
                orderid: 'STORED-' + selectedCustomerId,
                data: {
                    credit_score: selectedScore,
                    credit_report: {}
                }
            };
            renderCreditReport(fallbackReport);
            reportAvailable = true;
            switchStep(2);
        } else {
            alert('No previous report data found for this customer. Please run a fresh bureau inquiry.');
        }
    }

    // TRIGGER SAFEGUARD MODAL BEFORE ANY API CALL / WALLET DEBIT
    function triggerPaidCheckPrompt(isForced) {
        if (!selectedCustomerId) {
            alert('Mandatory: Please select a registered customer first.');
            return;
        }

        const consentCheck = document.getElementById('consentCheck');
        if (consentCheck && !consentCheck.checked) {
            alert('Mandatory: Customer explicit consent checkbox must be checked before proceeding with bureau check.');
            return;
        }

        isForcedRecheck = isForced;

        let fee = 80.00;
        let bureauTitle = 'Credit Report Transunion PDF';

        if (currentWalletBal < fee) {
            alert(`Insufficient Wallet Balance! Required: ₹${fee.toFixed(2)}, Available: ₹${currentWalletBal.toFixed(2)}. Please top up your wallet.`);
            window.location.href = 'wallet.php';
            return;
        }

        const el = document.querySelector(`.customer-select-item[data-id="${selectedCustomerId}"]`);
        const custName = el ? el.dataset.name : 'Customer';
        const hasExisting = el && ((el.dataset.hasReport === '1') || (el.dataset.score && parseInt(el.dataset.score) > 0));

        document.getElementById('modalCustName').textContent = custName;
        document.getElementById('modalBureauName').textContent = bureauTitle;
        document.getElementById('modalFeeAmount').textContent = '₹' + fee.toFixed(2);
        document.getElementById('modalPostBalance').textContent = '₹' + (currentWalletBal - fee).toLocaleString('en-IN', { minimumFractionDigits: 2 });

        const dupWarn = document.getElementById('modalDuplicateWarning');
        const confirmBtn = document.getElementById('modalConfirmBtn');
        const ackBox = document.getElementById('modalForceAckCheckbox');

        if (hasExisting || isForced) {
            dupWarn.style.display = 'block';
            document.getElementById('modalWarningDate').textContent = el ? (el.dataset.lastcheck || el.dataset.lastcheckShort || 'Earlier') : 'Earlier';
            ackBox.checked = false;
            confirmBtn.disabled = true;
            confirmBtn.style.opacity = '0.5';
            confirmBtn.textContent = 'Acknowledge Above to Proceed';
        } else {
            dupWarn.style.display = 'none';
            confirmBtn.disabled = false;
            confirmBtn.style.opacity = '1';
            confirmBtn.textContent = `✓ Yes, Deduct ₹${fee.toFixed(2)} & Fetch`;
        }

        document.getElementById('safeguardConfirmModal').style.display = 'flex';
    }

    function onModalAckChange() {
        const ackBox = document.getElementById('modalForceAckCheckbox');
        const confirmBtn = document.getElementById('modalConfirmBtn');
        let fee = 80.00;

        if (ackBox.checked) {
            confirmBtn.disabled = false;
            confirmBtn.style.opacity = '1';
            confirmBtn.textContent = `✓ Yes, Deduct ₹${fee.toFixed(2)} & Re-query`;
        } else {
            confirmBtn.disabled = true;
            confirmBtn.style.opacity = '0.5';
            confirmBtn.textContent = 'Acknowledge Above to Proceed';
        }
    }

    function closeSafeguardModal() {
        document.getElementById('safeguardConfirmModal').style.display = 'none';
    }

    // EXECUTE BUREAU INQUIRY AFTER MODAL CONFIRMATION
    async function executeBureauCheck() {
        const confirmBtn = document.getElementById('modalConfirmBtn');
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<span style="display:inline-block; animation:spin 1s linear infinite;">⏳</span> Connecting to Bureau...';

        try {
            const formData = new FormData();
            formData.append('customer_id', selectedCustomerId);
            formData.append('report_type', 'transunion_pdf');
            formData.append('gender', document.getElementById('custGender') ? document.getElementById('custGender').value : 'male');
            formData.append('consent', 'Y');

            const res = await fetch('<?=url('/api/credit-check.php')?>', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();
            closeSafeguardModal();

            if (data.success) {
                renderCreditReport(data);
                reportAvailable = true;
                
                // Update in-memory item
                const selectedItem = document.querySelector(`.customer-select-item[data-id="${selectedCustomerId}"]`);
                if (selectedItem) {
                    selectedItem.dataset.report = JSON.stringify(data.overall_json || data);
                    selectedItem.dataset.score = data.score || selectedScore;
                    selectedItem.dataset.hasReport = '1';
                }

                alert(`Success! Credit Bureau inquiry completed.\nScore: ${data.score || selectedScore}\nAmount Deducted: ₹${data.price_deducted || (activeBureauProvider === 'equifax_json' ? '150.00' : '80.00')}`);
                switchStep(2);
            } else {
                alert('Credit Check Error: ' + data.message);
            }
        } catch (err) {
            alert('Bureau Network / Connection error: ' + err.message);
        } finally {
            confirmBtn.disabled = false;
        }
    }

    // RENDER BUREAU CREDIT REPORT & METRICS
    let currentOverallJson = null;
    let currentPdfUrl = null;

    function renderCreditReport(data) {
        currentOverallJson = data.overall_json || data.report || data.data || data;
        
        if (data.score !== undefined && data.score !== null) {
            selectedScore = parseInt(data.score);
        } else if (data.credit_score !== undefined && data.credit_score !== null) {
            selectedScore = parseInt(data.credit_score);
        } else if (data.data && data.data.credit_score !== undefined && data.data.credit_score !== null) {
            selectedScore = parseInt(data.data.credit_score);
        } else {
            selectedScore = 746;
        }

        currentPdfUrl = data.pdf_url || 
                        (data.data ? (data.data.report_url || data.data.pdf_url) : null) || 
                        (data.report_url || null) || 
                        (data.overall_json ? (data.overall_json.pdf_url || (data.overall_json.data ? data.overall_json.data.report_url : null)) : null);

        document.getElementById('rptCustName').textContent = (data.customer && data.customer.name) ? data.customer.name : (data.name || (data.data ? data.data.name : (selectedCustomerObj ? selectedCustomerObj.name : 'Customer Credit Report')));
        document.getElementById('rptOrderMeta').textContent = 'Transaction Ref: ' + (data.orderid || (data.data ? data.data.orderid : ('TXN' + Date.now())));
        document.getElementById('rptScoreVal').textContent = selectedScore;
        document.getElementById('rptProviderBadge').textContent = data.provider || 'Equifax Bureau Verification';

        // Update Circular Gauge
        updateScoreDial(selectedScore);

        // PDF Button
        const pdfBtn = document.getElementById('btnExperianDirectPdf');
        if (pdfBtn) {
            if (currentPdfUrl) {
                pdfBtn.href = currentPdfUrl;
                pdfBtn.style.display = 'inline-flex';
                pdfBtn.target = '_blank';
            } else {
                pdfBtn.style.display = 'none';
            }
        }

        const badge = document.getElementById('rptScoreBadge');
        if (selectedScore >= 750) {
            badge.textContent = 'EXCELLENT RISK';
            badge.className = 'badge badge-success';
        } else if (selectedScore >= 700) {
            badge.textContent = 'STANDARD RISK';
            badge.className = 'badge badge-info';
        } else if (selectedScore >= 600) {
            badge.textContent = 'MODERATE RISK';
            badge.className = 'badge badge-warning';
        } else if (selectedScore <= 0) {
            badge.textContent = 'NEW TO CREDIT / NO HISTORY (' + selectedScore + ')';
            badge.className = 'badge badge-warning';
        } else {
            badge.textContent = 'CRITICAL RISK (< 600 INELIGIBLE)';
            badge.className = 'badge badge-danger';
        }

        updateEligibilityView();

        const jsonBox = document.getElementById('rawJsonBox');
        if (jsonBox) {
            jsonBox.textContent = JSON.stringify(currentOverallJson, null, 2);
        }

        // Parse Accounts & Balances
        const dataObj = currentOverallJson.data || currentOverallJson;
        const ccr = dataObj.credit_report || {};
        const cirDataLst = ccr.CCRResponse && ccr.CCRResponse.CIRReportDataLst ? ccr.CCRResponse.CIRReportDataLst[0] : {};
        const cirData = cirDataLst.CIRReportData || {};
        const accounts = cirData.RetailAccountDetails || [];

        let totalBal = 0;
        let pastDue = 0;
        accounts.forEach(acc => {
            totalBal += parseFloat(acc.Balance || 0);
            pastDue += parseFloat(acc.PastDueAmount || 0);
        });

        document.getElementById('rptTotalAccounts').textContent = accounts.length > 0 ? (accounts.length + ' Active') : '0 Active';
        document.getElementById('rptTotalBalance').textContent = '₹' + Math.round(totalBal).toLocaleString('en-IN');
        document.getElementById('rptPastDue').textContent = '₹' + Math.round(pastDue).toLocaleString('en-IN');

        const tbody = document.getElementById('accountsTableBody');
        if (tbody) {
            tbody.innerHTML = '';
            if (accounts.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:18px; color:var(--text-muted);">No detailed trade lines or loan accounts reported in bureau record.</td></tr>';
            } else {
                accounts.forEach((acc, idx) => {
                    const tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid var(--border-color)';
                    tr.innerHTML = `
                        <td style="padding:12px;">${idx + 1}</td>
                        <td style="padding:12px;"><strong>${acc.Institution || '-'}</strong><br><span style="font-size:0.75rem; color:var(--text-muted);">Acc: ${acc.AccountNumber || '-'}</span></td>
                        <td style="padding:12px;">${acc.AccountType || '-'}</td>
                        <td style="padding:12px;">₹${parseInt(acc.SanctionAmount || 0).toLocaleString('en-IN')}</td>
                        <td style="padding:12px;">₹${parseInt(acc.Balance || 0).toLocaleString('en-IN')}</td>
                        <td style="padding:12px; color:${parseInt(acc.PastDueAmount || 0) > 0 ? '#ef4444' : 'inherit'}; font-weight:${parseInt(acc.PastDueAmount || 0) > 0 ? '700' : 'normal'}">₹${parseInt(acc.PastDueAmount || 0).toLocaleString('en-IN')}</td>
                        <td style="padding:12px;">${acc.DateOpened || '-'}</td>
                        <td style="padding:12px;"><span class="badge ${acc.Open === 'Yes' ? 'badge-success' : 'badge-info'}">${acc.AccountStatus || (acc.Open === 'Yes' ? 'Open' : 'Closed')}</span></td>
                    `;
                    tbody.appendChild(tr);
                });
            }
        }
    }

    function updateScoreDial(score) {
        const circle = document.getElementById('scoreCircleProgress');
        if (!circle) return;

        // Circumference for r=66 is ~414.69
        const circumference = 414.7;
        if (score <= 0) {
            circle.style.strokeDashoffset = circumference;
            circle.style.stroke = '#94a3b8';
            return;
        }
        const minScore = 300;
        const maxScore = 900;
        const clamped = Math.max(minScore, Math.min(maxScore, score));
        const percentage = (clamped - minScore) / (maxScore - minScore);
        const offset = circumference - (percentage * circumference);

        circle.style.strokeDashoffset = offset;

        if (score >= 750) {
            circle.style.stroke = '#10b981'; // Emerald
        } else if (score >= 700) {
            circle.style.stroke = '#38bdf8'; // Sky Blue
        } else if (score >= 600) {
            circle.style.stroke = '#f59e0b'; // Amber
        } else {
            circle.style.stroke = '#ef4444'; // Red
        }
    }

    function updateEligibilityView() {
        const emiCard = document.getElementById('emiCalcCard');
        const lowAlert = document.getElementById('lowScoreIneligibleAlert');
        const scoreText = document.getElementById('ineligibleScoreText');
        const continueBtn = document.getElementById('btnContinueToEmi');
        const step3BlockAlert = document.getElementById('step3BlockAlert');
        const step3BlockTitle = document.getElementById('step3BlockTitle');
        const step3BlockDesc = document.getElementById('step3BlockDesc');
        const submitBtn = document.getElementById('btnSubmitFinanceApp');

        // CASE 1: WITHOUT CREDIT CHECK
        if (!reportAvailable || selectedScore === null || selectedScore <= 0) {
            if (emiCard) emiCard.style.display = 'none';
            if (step3BlockAlert) {
                step3BlockAlert.style.display = 'block';
                if (step3BlockTitle) step3BlockTitle.textContent = 'Credit Bureau Verification Required';
                if (step3BlockDesc) step3BlockDesc.innerHTML = 'Customer credit score has not been verified yet. Under store financing policy, <strong>finance applications cannot be issued without a verified bureau credit check</strong>.';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.35';
                submitBtn.style.cursor = 'not-allowed';
                submitBtn.innerHTML = '🚫 Blocked: Credit Check Required Before Application';
            }
            if (continueBtn) {
                continueBtn.disabled = true;
                continueBtn.style.opacity = '0.4';
                continueBtn.innerHTML = '⚡ Perform Credit Check First';
            }
        } 
        // CASE 2: CREDIT SCORE BELOW 600
        else if (selectedScore < 600) {
            if (emiCard) emiCard.style.display = 'none';
            if (lowAlert) lowAlert.style.display = 'block';
            if (scoreText) scoreText.textContent = selectedScore;
            if (step3BlockAlert) {
                step3BlockAlert.style.display = 'block';
                if (step3BlockTitle) step3BlockTitle.textContent = 'Store Financing Blocked (Credit Score Below 600)';
                if (step3BlockDesc) step3BlockDesc.innerHTML = 'Customer credit score is <strong style="color:#ef4444; font-size:1.1rem;">' + selectedScore + '</strong>. Under store credit risk policy, financing applications cannot be sanctioned or submitted for scores under <strong>600</strong>.';
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.35';
                submitBtn.style.cursor = 'not-allowed';
                submitBtn.innerHTML = '🚫 Blocked: Ineligible (Score ' + selectedScore + ' < 600)';
            }
            if (continueBtn) {
                continueBtn.disabled = true;
                continueBtn.style.opacity = '0.4';
                continueBtn.innerHTML = '🚫 Ineligible for Financing (Score < 600)';
            }
        } 
        // CASE 3: CREDIT SCORE >= 600 AND CHECKED
        else {
            if (emiCard) emiCard.style.display = 'block';
            if (lowAlert) lowAlert.style.display = 'none';
            if (step3BlockAlert) step3BlockAlert.style.display = 'none';
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                submitBtn.style.cursor = 'pointer';
                submitBtn.innerHTML = '<i data-lucide="check-circle"></i> 🚀 Save & Issue Store Finance Application';
            }
            if (continueBtn) {
                continueBtn.disabled = false;
                continueBtn.style.opacity = '1';
                continueBtn.innerHTML = 'Continue to EMI Calculator (Step 3) ➔';
            }
        }
        if (window.lucide) lucide.createIcons();
    }

    function printOfficialPdf() {
        if (currentPdfUrl) {
            window.open(currentPdfUrl, '_blank');
        } else {
            window.print();
        }
    }

    function toggleJsonView() {
        const box = document.getElementById('rawJsonBox');
        if (box) {
            box.style.display = box.style.display === 'block' ? 'none' : 'block';
        }
    }

    function convertJsonFileToPdf(event) {
        const file = event.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const jsonObj = JSON.parse(e.target.result);
                renderCreditReport(jsonObj);
                reportAvailable = true;
                switchStep(2);
            } catch (err) {
                alert('Invalid JSON file format: ' + err.message);
            }
        };
        reader.readAsText(file);
    }

    // STEP 3: PRODUCT & EMI RECALCULATION
    function onProductSelect() {
        const select = document.getElementById('productSelect');
        const opt = select.options[select.selectedIndex];
        const vGroup = document.getElementById('variantSelectGroup');
        const vSelect = document.getElementById('variantSelect');

        if (opt && opt.value) {
            const price = parseFloat(opt.dataset.price) || 0;
            const rawVars = opt.dataset.variants;
            let variants = [];
            try { variants = JSON.parse(rawVars); } catch(e){}

            if (variants && variants.length > 0) {
                vGroup.style.display = 'block';
                vSelect.innerHTML = '<option value="">-- Select Variant Option --</option>';
                variants.forEach(v => {
                    const optEl = document.createElement('option');
                    optEl.value = v.id;
                    optEl.dataset.price = v.price;
                    optEl.dataset.name = v.variant_name;
                    optEl.textContent = `${v.variant_name} - ₹${parseFloat(v.price).toLocaleString('en-IN')} (Stock: ${v.stock})`;
                    vSelect.appendChild(optEl);
                });
                vSelect.selectedIndex = 1;
                onVariantSelect();
            } else {
                vGroup.style.display = 'none';
                document.getElementById('calcPrice').value = price;
                document.getElementById('calcDown').value = Math.round(price * 0.20);
                recalculateEMI();
            }
        } else {
            vGroup.style.display = 'none';
        }
    }

    function onVariantSelect() {
        const vSelect = document.getElementById('variantSelect');
        const opt = vSelect.options[vSelect.selectedIndex];
        if (opt && opt.dataset.price) {
            const price = parseFloat(opt.dataset.price) || 0;
            document.getElementById('calcPrice').value = price;
            document.getElementById('calcDown').value = Math.round(price * 0.20);
            recalculateEMI();
        }
    }

    function recalculateEMI() {
        const price = parseFloat(document.getElementById('calcPrice').value) || 0;
        const down = parseFloat(document.getElementById('calcDown').value) || 0;
        const principal = Math.max(0, price - down);

        document.getElementById('calcPrincipal').value = '₹' + principal.toLocaleString('en-IN');
        const rate = parseFloat(document.getElementById('calcRate').value) || 0;

        const tenures = [3, 6, 9, 12, 18, 24];
        const grid = document.getElementById('emiCardsGrid');
        if (!grid) return;
        grid.innerHTML = '';

        tenures.forEach(months => {
            const monthlyRate = (rate / 12) / 100;
            let emi = 0;
            if (principal > 0 && monthlyRate > 0) {
                emi = Math.round((principal * monthlyRate * Math.pow(1 + monthlyRate, months)) / (Math.pow(1 + monthlyRate, months) - 1));
            } else if (principal > 0) {
                emi = Math.round(principal / months);
            }

            const totalPayable = emi * months;
            const totalInterest = Math.max(0, totalPayable - principal);

            const card = document.createElement('div');
            card.className = `emi ${selectedTenure === months ? 'selected' : ''}`;
            card.style.cursor = 'pointer';

            card.onclick = () => {
                selectedTenure = months;
                recalculateEMI();
            };

            // 20th Cutoff Condition: loans created after 20th skip next month, start on 4th of following month
            const now = new Date();
            const curDay = now.getDate();
            const startOffset = (curDay > 20) ? 2 : 1;
            const firstEmiDate = new Date(now.getFullYear(), now.getMonth() + startOffset, 4);
            const firstEmiMonth = firstEmiDate.toLocaleString('en-IN', { month: 'short', year: 'numeric' });

            card.innerHTML = `
                <div style="font-weight: 800;">${months} Months EMI</div>
                <strong style="color:#0284c7; font-size:1.3rem;">₹${emi.toLocaleString('en-IN')}<span style="font-size:0.75rem; color:var(--text-muted); font-weight:normal;">/mo</span></strong>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top:4px;">Interest: ₹${totalInterest.toLocaleString('en-IN')} | Total: ₹${totalPayable.toLocaleString('en-IN')}</div>
                <div style="font-size: 0.72rem; color: #2563eb; margin-top: 6px;">📅 1st EMI: <strong>04 ${firstEmiMonth}</strong> ${curDay > 20 ? '<span style="color:#d97706; font-size:0.68rem;">(Post-20th cycle)</span>' : ''}</div>
            `;
            grid.appendChild(card);
        });
    }

    async function submitFinanceApplication() {
        if (!selectedCustomerId) {
            alert('Mandatory: Please select a customer first.');
            return;
        }

        // CONDITION 1: Without Credit Check
        if (!reportAvailable || selectedScore === null || selectedScore <= 0) {
            alert('Submission Blocked: Credit bureau inquiry has not been performed for this customer yet. A verified credit score (minimum 600) is mandatory before submitting a loan application.');
            switchStep(1);
            return;
        }

        // CONDITION 2: Credit Score Below 600
        if (selectedScore < 600) {
            alert('Submission Blocked: Customer credit score (' + selectedScore + ') is below 600. Store financing applications cannot be sanctioned or submitted for credit scores under 600.');
            return;
        }

        const prodSelect = document.getElementById('productSelect');
        const price = parseFloat(document.getElementById('calcPrice').value) || 0;
        const down = parseFloat(document.getElementById('calcDown').value) || 0;

        if (price <= 0) {
            alert('Please select a valid product and price.');
            return;
        }

        const rate = parseFloat(document.getElementById('calcRate').value) || 0;

        const formData = new FormData();
        formData.append('customer_id', selectedCustomerId);
        formData.append('product_id', prodSelect.value || '');
        formData.append('product_price', price);
        formData.append('down_payment', down);
        formData.append('tenure', selectedTenure);
        formData.append('interest_rate', rate);

        try {
            const res = await fetch('<?=url('/api/create-application.php')?>', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();
            if (data.success) {
                alert('Success! Finance Application Created: ' + data.app_no + (data.first_emi_date ? '\n1st Installment Due: ' + data.first_emi_date : ''));
                window.location.href = 'applications.php';
            } else {
                alert('Error: ' + data.message);
            }
        } catch (err) {
            alert('Submission error: ' + err.message);
        }
    }
</script>

<?php render_end(); ?>
