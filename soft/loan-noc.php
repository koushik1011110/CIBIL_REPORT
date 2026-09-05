<?php
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/onboarding_db_init.php';
role('shop_admin', 'superadmin', 'staff', 'customer');

$p = db();
$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    die("Invalid Application ID");
}

// Fetch Application Details
$stmt = $p->prepare("
    SELECT f.*, c.name as customer_name, c.mobile as customer_mobile, c.email as customer_email,
           c.pan as customer_pan, c.dob as customer_dob, c.address as customer_address,
           ob.aadhaar_no, ob.aadhaar_verified,
           p.name as product_name, p.brand as product_brand, p.model as product_model, p.sku as product_sku,
           s.name as shop_name, s.phone as shop_phone, s.email as shop_email, s.address as shop_address, s.gstin as shop_gstin, s.logo as shop_logo
    FROM finance_applications f
    JOIN customers c ON c.id = f.customer_id
    LEFT JOIN finance_application_onboarding ob ON ob.finance_id = f.id
    LEFT JOIN products p ON p.id = f.product_id
    LEFT JOIN shops s ON s.id = f.shop_id
    WHERE f.id = ?
");
$stmt->execute([$id]);
$app = $stmt->fetch();

if (!$app) {
    die("Finance Application record not found.");
}

// Check total EMIs & unpaid EMIs count
$totalStmt = $p->prepare("SELECT COUNT(*) FROM emi_schedules WHERE finance_id = ?");
$totalStmt->execute([$id]);
$totalEmiCount = (int)$totalStmt->fetchColumn();

$unpaidStmt = $p->prepare("SELECT COUNT(*) FROM emi_schedules WHERE finance_id = ? AND status != 'paid'");
$unpaidStmt->execute([$id]);
$unpaidCount = (int)$unpaidStmt->fetchColumn();

// A loan NOC is valid ONLY if total EMIs exist (>0), all EMIs are paid (unpaidCount == 0), and status is approved/active/completed
$isLoanFullyPaid = ($totalEmiCount > 0 && $unpaidCount === 0 && in_array($app['status'], ['approved', 'active', 'completed']));

if ($isLoanFullyPaid && $app['status'] !== 'completed') {
    $p->prepare("UPDATE finance_applications SET status = 'completed' WHERE id = ?")->execute([$id]);
    $app['status'] = 'completed';
}

if (!$isLoanFullyPaid) {
    $reason = "This loan application is pending onboarding / approval";
    if ($totalEmiCount > 0 && $unpaidCount > 0) {
        $reason = "You have <strong>{$unpaidCount} unpaid EMI installment(s) remaining</strong>";
    }
    die("<div style='font-family:sans-serif; text-align:center; padding:60px 20px; background:#0f172a; color:#fff; min-height:100vh;'><div style='max-width:500px; margin:0 auto; background:rgba(30,41,59,0.9); padding:30px; border-radius:16px; border:1px solid #ef4444;'><h2 style='color:#ef4444; margin-top:0;'>🔒 NOC Certificate Restricted</h2><p style='color:#cbd5e1; font-size:0.95rem; line-height:1.6;'>No Objection Certificate (NOC) is not available.<br>{$reason}.<br>NOC is issued only after 100% EMI repayment on approved active loans.</p><p style='margin-top:20px;'><a href='javascript:history.back()' style='display:inline-block; padding:10px 20px; background:#2563eb; color:#fff; text-decoration:none; border-radius:8px; font-weight:700;'>← Go Back</a></p></div></div>");
}

// Total Paid
$paidStmt = $p->prepare("SELECT SUM(paid_amount) FROM emi_schedules WHERE finance_id = ?");
$paidStmt->execute([$id]);
$totalEmiPaid = floatval($paidStmt->fetchColumn() ?: 0);
$grandTotalPaid = $app['down_payment'] + $totalEmiPaid;

$nocNo = 'NOC-GO4FIN-' . date('Y') . '-' . str_pad($app['id'], 5, '0', STR_PAD_LEFT);

$shopLogoUrl = (!empty($app['shop_logo']) && file_exists(__DIR__ . '/uploads/logos/' . $app['shop_logo']))
    ? url('/uploads/logos/' . $app['shop_logo'])
    : url('/public/assets/images/logo.png');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>NOC & Loan Closure Certificate — <?=e($app['application_no'])?></title>
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background: #525659; margin: 0; padding: 25px; color: #1e293b; }
        .cert-container { background: #fff; max-width: 820px; margin: 0 auto; padding: 45px; border-radius: 10px; box-shadow: 0 12px 35px rgba(0,0,0,0.3); border: 8px double #1e3a8a; position: relative; }
        .header-table { width: 100%; border-collapse: collapse; border-bottom: 2px solid #1e3a8a; padding-bottom: 15px; margin-bottom: 25px; }
        .cert-title { text-align: center; margin: 20px 0; }
        .cert-title h1 { margin: 0; font-size: 22px; font-weight: 900; color: #1e3a8a; letter-spacing: 1.5px; text-transform: uppercase; }
        .cert-title p { margin: 4px 0 0 0; font-size: 13px; color: #059669; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; }
        .cert-body { font-size: 14px; line-height: 1.8; color: #334155; text-align: justify; margin-bottom: 25px; }
        .grid-box { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px; background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #cbd5e1; }
        .info-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .info-table td { padding: 4px 0; }
        .info-table td.lbl { color: #64748b; font-weight: 600; width: 45%; }
        .info-table td.val { font-weight: 800; color: #0f172a; }
        .summary-box { background: #ecfdf5; border: 1px solid #a7f3d0; padding: 14px; border-radius: 8px; text-align: center; margin-bottom: 30px; }
        .footer-grid { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 40px; }
        .seal-box { border: 2px solid #1e3a8a; color: #1e3a8a; padding: 10px 16px; border-radius: 50%; font-size: 10px; font-weight: 900; text-align: center; display: inline-block; transform: rotate(-10deg); }
        .sign-area { text-align: center; width: 220px; border-top: 1.5px dashed #64748b; padding-top: 8px; font-size: 12px; font-weight: 800; color: #0f172a; }
        .no-print { text-align: center; margin-bottom: 20px; }
        .btn-print { background: #059669; color: #fff; border: none; padding: 10px 24px; border-radius: 6px; font-weight: 800; cursor: pointer; font-size: 14px; }
        .btn-back { background: #475569; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: 700; font-size: 14px; margin-right: 12px; display: inline-block; }
        @media print {
            body { background: #fff; padding: 0; }
            .cert-container { box-shadow: none; max-width: 100%; border: 4px solid #1e3a8a; padding: 25px; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <a href="javascript:history.back()" class="btn-back">← Back to Applications</a>
    <button onclick="window.print()" class="btn-print">🖨️ Print NOC Certificate (PDF)</button>
</div>

<div class="cert-container">

    <!-- HEADER -->
    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                <div style="display: flex; align-items: center; gap: 14px;">
                    <img src="<?=$shopLogoUrl?>" alt="Logo" style="height: 54px; max-width: 130px; object-fit: contain;">
                    <div>
                        <h2 style="margin:0; font-size: 19px; font-weight: 900; color: #1e3a8a;">GO4 FINANCE PRIVATE LIMITED</h2>
                        <p style="margin: 2px 0 0 0; font-size: 11px; color: #64748b;">Certified Consumer Credit & Equipment Financing</p>
                        <p style="margin: 2px 0 0 0; font-size: 11px; color: #64748b;">Assam, India | CIN: U65929AS2022PTC023190</p>
                    </div>
                </div>
            </td>
            <td style="text-align: right; vertical-align: top;">
                <div style="font-size: 11px; color: #64748b; font-weight: 700;">NOC CERTIFICATE NO</div>
                <div style="font-size: 14px; font-weight: 900; color: #1e3a8a; font-family: monospace;"><?=e($nocNo)?></div>
                <div style="font-size: 11px; color: #64748b; margin-top: 4px;">Date of Issue: <strong><?=date('d M Y')?></strong></div>
            </td>
        </tr>
    </table>

    <!-- CERTIFICATE TITLE -->
    <div class="cert-title">
        <h1>NO OBJECTION CERTIFICATE</h1>
        <p>🎓 Official Loan Completion & Discharge Certificate</p>
    </div>

    <!-- CERTIFICATE DECLARATION TEXT -->
    <div class="cert-body">
        This is to certify that <strong>Mr. / Ms. <?=e($app['customer_name'])?></strong> 
        (PAN: <strong><?=e($app['customer_pan'] ?: 'Verified')?></strong>, Mobile: <strong><?=e($app['customer_mobile'])?></strong>) 
        has successfully repaid all financial dues and installments towards Finance Application No 
        <strong><?=e($app['application_no'])?></strong> for the purchase of 
        <strong><?=e($app['product_name'] ?: 'Store Product')?></strong> from retail partner 
        <strong><?=e($app['shop_name'] ?: 'Store Partner')?></strong>.
        <br><br>
        As of <strong><?=date('d F Y')?></strong>, there are <strong>ZERO OUTSTANDING DUES (₹0.00)</strong> remaining against this financing account. GO4 Finance Private Limited hereby releases and discharges all hypothecation, lien, and charges on the financed product.
    </div>

    <!-- FINANCING DETAILS GRID -->
    <div class="grid-box">
        <div>
            <table class="info-table">
                <tr><td class="lbl">Borrower Name:</td><td class="val"><?=e($app['customer_name'])?></td></tr>
                <tr><td class="lbl">Application No:</td><td class="val"><?=e($app['application_no'])?></td></tr>
                <tr><td class="lbl">Retail Partner:</td><td class="val"><?=e($app['shop_name'] ?: 'Store')?></td></tr>
                <tr><td class="lbl">Item Financed:</td><td class="val"><?=e($app['product_name'] ?: 'Electronic Product')?></td></tr>
            </table>
        </div>
        <div>
            <table class="info-table">
                <tr><td class="lbl">Financed Amount:</td><td class="val"><?=money($app['finance_amount'])?></td></tr>
                <tr><td class="lbl">Total Payable:</td><td class="val"><?=money($app['total_payable'])?></td></tr>
                <tr><td class="lbl">Tenure Cleared:</td><td class="val"><?=e($app['tenure'])?> Months (100% Paid)</td></tr>
                <tr><td class="lbl">Current Status:</td><td class="val" style="color: #059669;">CLOSED / FULLY PAID ✓</td></tr>
            </table>
        </div>
    </div>

    <!-- ZERO BALANCE SUMMARY -->
    <div class="summary-box">
        <div style="font-size: 12px; color: #047857; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;">Account Balance Summary</div>
        <div style="font-size: 20px; font-weight: 900; color: #065f46; margin: 4px 0;">Total Outstanding Balance: ₹0.00</div>
        <div style="font-size: 12px; color: #047857;">Total Amount Paid to Date: <strong><?=money($grandTotalPaid)?></strong> | All EMIs Cleared</div>
    </div>

    <!-- FOOTER SIGNATURES & SEAL -->
    <div class="footer-grid">
        <div style="text-align: center;">
            <div class="seal-box">
                GO4FINANCE<br>
                OFFICIAL SEAL<br>
                CERTIFIED NOC
            </div>
        </div>

        <div class="sign-area">
            <div style="height: 35px; display: flex; align-items: center; justify-content: center; font-style: italic; color: #1e3a8a; font-weight: 800;">
                Wazid Hoque
            </div>
            Authorized Signatory<br>
            <span style="font-size: 10px; color: #64748b; font-weight: normal;">GO4 Finance Private Limited</span>
        </div>
    </div>

    <!-- FOOTER DISCLOSURE -->
    <div style="margin-top: 35px; text-align: center; font-size: 10px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 10px;">
        This No Objection Certificate is digitally generated and legally binding | GO4 Finance Private Limited | Registered in Assam, India
    </div>

</div>

</body>
</html>
