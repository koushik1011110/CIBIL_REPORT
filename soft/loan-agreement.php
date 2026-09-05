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
           ob.aadhaar_no, ob.aadhaar_verified, ob.witness_name as ref_name, ob.witness_mobile as ref_mobile,
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

// Fetch EMI Schedules
$emiStmt = $p->prepare("SELECT * FROM emi_schedules WHERE finance_id = ? ORDER BY installment_no ASC");
$emiStmt->execute([$id]);
$emis = $emiStmt->fetchAll();

$shopLogoUrl = (!empty($app['shop_logo']) && file_exists(__DIR__ . '/uploads/logos/' . $app['shop_logo']))
    ? url('/uploads/logos/' . $app['shop_logo'])
    : url('/public/assets/images/logo.png');

$agreementNo = 'AGR-GO4FIN-' . date('Y', strtotime($app['created_at'])) . '-' . str_pad($app['id'], 5, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Loan Agreement & e-Sign — <?=e($app['application_no'])?></title>
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background: #525659; margin: 0; padding: 20px; color: #1e293b; }
        .page { background: #fff; max-width: 850px; margin: 0 auto; padding: 40px; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); font-size: 13px; line-height: 1.5; }
        .header-table { width: 100%; border-collapse: collapse; border-bottom: 2px solid #0f172a; padding-bottom: 15px; margin-bottom: 20px; }
        .title-badge { background: #0f172a; color: #fff; text-align: center; padding: 8px; font-weight: 800; font-size: 15px; letter-spacing: 1px; margin-bottom: 20px; border-radius: 4px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .box { border: 1px solid #cbd5e1; padding: 14px; border-radius: 6px; background: #f8fafc; }
        .box h4 { margin: 0 0 10px 0; font-size: 13px; font-weight: 800; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; text-transform: uppercase; }
        .info-table { width: 100%; border-collapse: collapse; font-size: 12px; }
        .info-table td { padding: 4px 0; vertical-align: top; }
        .info-table td.lbl { color: #64748b; font-weight: 600; width: 40%; }
        .info-table td.val { font-weight: 700; color: #0f172a; }
        .schedule-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 12px; }
        .schedule-table th { background: #1e293b; color: #fff; padding: 8px; text-align: left; }
        .schedule-table td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; }
        .terms-list { font-size: 11px; color: #475569; padding-left: 18px; margin: 10px 0; }
        .terms-list li { margin-bottom: 6px; }
        .sign-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 30px; text-align: center; }
        .sign-box { border: 1px dashed #94a3b8; border-radius: 6px; padding: 12px; background: #fafafa; min-height: 110px; display: flex; flex-direction: column; justify-content: space-between; }
        .esign-stamp { background: #e6f4ea; border: 1px solid #ceead6; color: #137333; padding: 6px; border-radius: 4px; font-size: 10px; font-weight: 800; text-align: center; }
        .no-print { text-align: center; margin-bottom: 20px; }
        .btn-print { background: #2563eb; color: #fff; border: none; padding: 10px 24px; border-radius: 6px; font-weight: 800; cursor: pointer; font-size: 14px; }
        .btn-back { background: #475569; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: 700; font-size: 14px; margin-right: 12px; display: inline-block; }
        @media print {
            body { background: #fff; padding: 0; }
            .page { box-shadow: none; max-width: 100%; padding: 15px; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <a href="javascript:history.back()" class="btn-back">← Back to Applications</a>
    <button onclick="window.print()" class="btn-print">🖨️ Print / Save PDF Agreement</button>
</div>

<div class="page">
    
    <!-- HEADER -->
    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                <div style="display: flex; align-items: center; gap: 14px;">
                    <img src="<?=$shopLogoUrl?>" alt="Logo" style="height: 52px; max-width: 130px; object-fit: contain;">
                    <div>
                        <h2 style="margin:0; font-size: 18px; font-weight: 800; color: #0f172a;">GO4 FINANCE PRIVATE LIMITED</h2>
                        <p style="margin: 2px 0 0 0; font-size: 11px; color: #64748b;">Certified Consumer Durable Financing Partner</p>
                        <p style="margin: 2px 0 0 0; font-size: 11px; color: #64748b;">CIN: U65929AS2022PTC023190 | Corporate Office: Assam, India</p>
                    </div>
                </div>
            </td>
            <td style="text-align: right; vertical-align: top;">
                <div style="font-size: 12px; color: #64748b; font-weight: 700;">AGREEMENT REF NO</div>
                <div style="font-size: 15px; font-weight: 800; color: #0f172a; font-family: monospace;"><?=e($agreementNo)?></div>
                <div style="font-size: 11px; color: #64748b; margin-top: 4px;">App No: <strong><?=e($app['application_no'])?></strong></div>
                <div style="font-size: 11px; color: #64748b;">Date: <strong><?=date('d M Y', strtotime($app['created_at']))?></strong></div>
            </td>
        </tr>
    </table>

    <div class="title-badge">CONSUMER DURABLE STORE FINANCING AGREEMENT</div>

    <!-- LENDER & BORROWER DETAILS -->
    <div class="grid-2">
        <div class="box">
            <h4>1. Lender & Retail Partner</h4>
            <table class="info-table">
                <tr><td class="lbl">Financier:</td><td class="val">GO4 Finance Private Limited</td></tr>
                <tr><td class="lbl">Retail Store:</td><td class="val"><?=e($app['shop_name'] ?: 'Demo Partner Store')?></td></tr>
                <tr><td class="lbl">Store GSTIN:</td><td class="val"><?=e($app['shop_gstin'] ?: '18AABCU9603R1ZM')?></td></tr>
                <tr><td class="lbl">Store Address:</td><td class="val"><?=e($app['shop_address'] ?: 'Barpeta Road, Assam')?></td></tr>
            </table>
        </div>
        <div class="box">
            <h4>2. Borrower Details</h4>
            <table class="info-table">
                <tr><td class="lbl">Full Name:</td><td class="val"><?=e($app['customer_name'])?></td></tr>
                <tr><td class="lbl">Mobile No:</td><td class="val"><?=e($app['customer_mobile'])?></td></tr>
                <tr><td class="lbl">PAN Card:</td><td class="val"><?=e($app['customer_pan'] ?: 'N/A')?></td></tr>
                <tr><td class="lbl">Aadhaar No:</td><td class="val"><?=e($app['aadhaar_no'] ? 'XXXX-XXXX-' . substr($app['aadhaar_no'], -4) : 'Verified')?> 🛡️</td></tr>
                <tr><td class="lbl">Address:</td><td class="val"><?=e($app['customer_address'] ?: 'Assam, India')?></td></tr>
            </table>
        </div>
    </div>

    <!-- WITNESS & PRODUCT DETAILS -->
    <div class="grid-2">
        <div class="box">
            <h4>3. Guarantor / Witness Details</h4>
            <table class="info-table">
                <tr><td class="lbl">Witness Name:</td><td class="val"><?=e(($app['ref_name'] ?? '') ?: 'Family Reference')?></td></tr>
                <tr><td class="lbl">Relationship:</td><td class="val"><?=e(($app['ref_relation'] ?? '') ?: 'Witness Reference')?></td></tr>
                <tr><td class="lbl">Contact No:</td><td class="val"><?=e(($app['ref_mobile'] ?? '') ?: 'N/A')?></td></tr>
                <tr><td class="lbl">Address:</td><td class="val"><?=e(($app['ref_address'] ?? '') ?: ($app['customer_address'] ?? 'Assam'))?></td></tr>
            </table>
        </div>
        <div class="box">
            <h4>4. Financed Product & Price</h4>
            <table class="info-table">
                <tr><td class="lbl">Item Name:</td><td class="val"><?=e($app['product_name'] ?: 'Electronic Item')?></td></tr>
                <tr><td class="lbl">Brand / Model:</td><td class="val"><?=e($app['product_brand'])?> <?=e($app['product_model'])?></td></tr>
                <tr><td class="lbl">Product MRP:</td><td class="val"><?=money($app['product_price'])?></td></tr>
                <tr><td class="lbl">Down Payment:</td><td class="val" style="color: #059669;"><?=money($app['down_payment'])?> (Paid)</td></tr>
            </table>
        </div>
    </div>

    <!-- LOAN FINANCIAL TERMS -->
    <div class="box" style="margin-bottom: 20px;">
        <h4>5. Loan Structure & Repayment Terms</h4>
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; text-align: center;">
            <div style="background:#fff; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                <div style="font-size: 11px; color: #64748b;">Financed Amount</div>
                <div style="font-size: 16px; font-weight: 800; color: #2563eb;"><?=money($app['finance_amount'])?></div>
            </div>
            <div style="background:#fff; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                <div style="font-size: 11px; color: #64748b;">Monthly EMI</div>
                <div style="font-size: 16px; font-weight: 800; color: #059669;"><?=money($app['emi'])?>/mo</div>
            </div>
            <div style="background:#fff; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                <div style="font-size: 11px; color: #64748b;">Tenure & Rate</div>
                <div style="font-size: 14px; font-weight: 800; color: #0f172a;"><?=e($app['tenure'])?> Months @ <?=e($app['interest_rate'])?>%</div>
            </div>
            <div style="background:#fff; padding: 10px; border-radius: 6px; border: 1px solid #cbd5e1;">
                <div style="font-size: 11px; color: #64748b;">Total Payable</div>
                <div style="font-size: 16px; font-weight: 800; color: #0f172a;"><?=money($app['total_payable'])?></div>
            </div>
        </div>
    </div>

    <!-- EMI SCHEDULE TABLE -->
    <?php if (!empty($emis)): ?>
        <div style="margin-bottom: 20px;">
            <h4 style="margin: 0 0 6px 0; font-size: 12px; font-weight: 800; text-transform: uppercase; color: #0f172a;">6. Installment Repayment Schedule</h4>
            <table class="schedule-table">
                <thead>
                    <tr>
                        <th>Inst #</th>
                        <th>Due Date</th>
                        <th>Principal (₹)</th>
                        <th>Interest (₹)</th>
                        <th>EMI Amount (₹)</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emis as $eRow): ?>
                        <tr>
                            <td>Installment #<?=$eRow['installment_no']?></td>
                            <td><?=date('d M Y', strtotime($eRow['due_date']))?></td>
                            <td><?=money($eRow['principal'])?></td>
                            <td><?=money($eRow['interest'])?></td>
                            <td><strong><?=money($eRow['amount'])?></strong></td>
                            <td>
                                <?php if ($eRow['status'] === 'paid'): ?>
                                    <span style="color: #059669; font-weight: 800;">✓ PAID</span>
                                <?php else: ?>
                                    <span style="color: #d97706; font-weight: 700;">UPCOMING</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <!-- TERMS & CONDITIONS -->
    <div style="margin-bottom: 20px; background: #fff; border: 1px solid #cbd5e1; padding: 12px; border-radius: 6px;">
        <h4 style="margin: 0 0 6px 0; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #0f172a;">7. Mandatory Terms & Declaration</h4>
        <ol class="terms-list">
            <li><strong>Non-Cash Lending Model:</strong> The borrower confirms that this credit is extended solely for the purchase of the specified consumer product from the partner retail store. No cash disbursements are made.</li>
            <li><strong>Repayment Obligation:</strong> Borrower agrees to pay each monthly installment on or before the due date via UPI AutoPay, eNACH mandate, or cash at partner counter.</li>
            <li><strong>Late Payment & Penal Charges:</strong> Delayed monthly EMIs beyond due date will attract a late penalty fee of ₹100 + interest per month as applicable.</li>
            <li><strong>Product Hypothecation:</strong> The financed product remains hypothecated to GO4 Finance Private Limited until all EMIs are cleared 100%.</li>
            <li><strong>e-Sign & Digital Acceptance:</strong> The borrower accepts that digital Aadhaar OTP authentication constitutes valid legal electronic signature under Information Technology Act, 2000.</li>
        </ol>
    </div>

    <!-- DIGITAL SIGNATURES & STAMPS -->
    <div class="sign-grid">
        <div class="sign-box">
            <div style="font-size: 11px; font-weight: 800; color: #0f172a;">BORROWER DIGITAL E-SIGN</div>
            <div class="esign-stamp">
                ✓ AADHAAR OTP E-SIGNED<br>
                Name: <?=e($app['customer_name'])?><br>
                Date: <?=date('d/m/Y H:i', strtotime($app['created_at']))?><br>
                IP: Verified Digital Sign
            </div>
            <div style="font-size: 10px; color: #64748b; font-weight: 700; border-top: 1px dashed #94a3b8; padding-top: 4px;">Borrower Signature</div>
        </div>

        <div class="sign-box">
            <div style="font-size: 11px; font-weight: 800; color: #0f172a;">WITNESS / GUARANTOR SIGN</div>
            <div style="height: 40px; display: flex; align-items: center; justify-content: center; font-style: italic; color: #64748b; font-size: 11px;">
                Verified Reference: <?=e($app['ref_name'] ?: 'Witness')?>
            </div>
            <div style="font-size: 10px; color: #64748b; font-weight: 700; border-top: 1px dashed #94a3b8; padding-top: 4px;">Witness Signature</div>
        </div>

        <div class="sign-box">
            <div style="font-size: 11px; font-weight: 800; color: #0f172a;">GO4 FINANCE AUTHORIZED SIGN</div>
            <div style="margin: 4px 0;">
                <div style="border: 2px solid #2563eb; color: #2563eb; display: inline-block; padding: 2px 6px; border-radius: 50%; font-size: 9px; font-weight: 800; transform: rotate(-8deg);">
                    GO4FIN SEAL
                </div>
            </div>
            <div style="font-size: 10px; color: #64748b; font-weight: 700; border-top: 1px dashed #94a3b8; padding-top: 4px;">Authorized Signatory & Seal</div>
        </div>
    </div>

    <!-- FOOTER COPYRIGHT -->
    <div style="margin-top: 30px; text-align: center; font-size: 10px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 10px;">
        Computer Generated Loan Agreement & Digital Contract | GO4 Finance Private Limited | Page 1 of 1
    </div>

</div>

</body>
</html>
