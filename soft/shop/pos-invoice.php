<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/onboarding_db_init.php';
role('shop_admin', 'superadmin', 'staff');

$p = db();
$saleId = (int)($_GET['id'] ?? 0);

if ($saleId <= 0) {
    die("Invalid Invoice ID.");
}

// Fetch Sale Header
$saleStmt = $p->prepare("SELECT * FROM pos_sales WHERE id = ?");
$saleStmt->execute([$saleId]);
$sale = $saleStmt->fetch();

if (!$sale) {
    die("POS Invoice record not found.");
}

// Fetch Shop Details
$shopStmt = $p->prepare("SELECT * FROM shops WHERE id = ?");
$shopStmt->execute([$sale['shop_id']]);
$shop = $shopStmt->fetch() ?: ['name' => 'Go4 Finance Partner Store', 'phone' => '', 'email' => '', 'gstin' => '', 'address' => ''];

// Fetch Sale Items
$itemsStmt = $p->prepare("SELECT * FROM pos_sale_items WHERE pos_sale_id = ? ORDER BY id ASC");
$itemsStmt->execute([$saleId]);
$items = $itemsStmt->fetchAll();

// Helper to convert number to words (Indian Rupees)
function getAmountInWords($amount) {
    $amount = round($amount, 2);
    $number = floor($amount);
    $fraction = round(($amount - $number) * 100);
    
    $words = [
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
        20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety'
    ];
    
    if ($number == 0) return 'Zero Rupees Only';
    
    $res = '';
    
    if ($number >= 10000000) {
        $crore = floor($number / 10000000);
        $number %= 10000000;
        $res .= getAmountInWords($crore) . ' Crore ';
    }
    if ($number >= 100000) {
        $lakh = floor($number / 100000);
        $number %= 100000;
        $res .= ($lakh < 20 ? $words[$lakh] : $words[floor($lakh/10)*10] . ' ' . $words[$lakh%10]) . ' Lakh ';
    }
    if ($number >= 1000) {
        $thousand = floor($number / 1000);
        $number %= 1000;
        $res .= ($thousand < 20 ? $words[$thousand] : $words[floor($thousand/10)*10] . ' ' . $words[$thousand%10]) . ' Thousand ';
    }
    if ($number >= 100) {
        $hundred = floor($number / 100);
        $number %= 100;
        $res .= $words[$hundred] . ' Hundred ';
    }
    if ($number > 0) {
        $res .= ($number < 20 ? $words[$number] : $words[floor($number/10)*10] . ' ' . $words[$number%10]) . ' ';
    }
    
    $res = trim($res) . ' Rupees';
    if ($fraction > 0) {
        $res .= ' and ' . ($fraction < 20 ? $words[$fraction] : $words[floor($fraction/10)*10] . ' ' . $words[$fraction%10]) . ' Paise';
    }
    return $res . ' Only';
}

$autoPrint = isset($_GET['auto_print']) && $_GET['auto_print'] == '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Retail Invoice - <?=e($sale['invoice_no'])?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background: #f8fafc; color: #0f172a; padding: 20px; font-size: 13px; }
        .invoice-box { max-width: 850px; margin: 0 auto; background: #fff; border: 1px solid #cbd5e1; padding: 30px; border-radius: 8px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .header-table { width: 100%; border-bottom: 2px solid #0f172a; padding-bottom: 15px; margin-bottom: 15px; }
        .invoice-title { text-align: right; }
        .invoice-title h2 { font-size: 22px; color: #2563eb; text-transform: uppercase; font-weight: 800; letter-spacing: 1px; }
        .invoice-title p { font-weight: 700; color: #475569; font-size: 12px; margin-top: 2px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; background: #f1f5f9; padding: 14px; border-radius: 6px; border: 1px solid #e2e8f0; }
        .info-box h4 { font-size: 11px; text-transform: uppercase; color: #64748b; margin-bottom: 4px; letter-spacing: 0.5px; }
        .info-box p { font-size: 13px; line-height: 1.5; color: #0f172a; }
        .item-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .item-table th { background: #1e293b; color: #fff; text-align: left; padding: 8px 10px; font-size: 11px; text-transform: uppercase; }
        .item-table td { padding: 10px; border-bottom: 1px solid #e2e8f0; font-size: 12px; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .summary-grid { display: grid; grid-template-columns: 1fr 300px; gap: 20px; margin-top: 10px; }
        .amount-words { background: #eff6ff; border: 1px solid #bfdbfe; padding: 12px; border-radius: 6px; font-size: 12px; color: #1e40af; }
        .totals-table { width: 100%; border-collapse: collapse; }
        .totals-table td { padding: 6px 8px; font-size: 12px; }
        .totals-table tr.grand-total { font-weight: 800; font-size: 14px; background: #1e293b; color: #fff; }
        .totals-table tr.grand-total td { padding: 10px 8px; }
        .footer-sign { margin-top: 40px; display: flex; justify-content: space-between; align-items: flex-end; }
        .sign-box { text-align: center; width: 200px; border-top: 1px dashed #94a3b8; padding-top: 8px; font-size: 11px; color: #475569; font-weight: 700; }
        .no-print { margin-bottom: 20px; text-align: center; }
        .btn-print { background: #2563eb; color: #fff; border: none; padding: 10px 20px; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 14px; }
        .btn-back { background: #64748b; color: #fff; text-decoration: none; padding: 10px 20px; border-radius: 6px; font-weight: 700; font-size: 14px; margin-right: 10px; display: inline-block; }
        @media print {
            body { background: #fff; padding: 0; }
            .invoice-box { border: none; box-shadow: none; padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <a href="pos.php" class="btn-back">← Back to POS Terminal</a>
    <button onclick="window.print()" class="btn-print">🖨️ Print Sale Invoice</button>
</div>

<div class="invoice-box">
    <!-- HEADER -->
    <table class="header-table">
        <tr>
            <td style="width: 60%;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <?php 
                    $shopLogoUrl = (!empty($shop['logo']) && file_exists(__DIR__ . '/../uploads/logos/' . $shop['logo']))
                        ? url('/uploads/logos/' . $shop['logo'])
                        : url('/public/assets/images/logo.png');
                    ?>
                    <img src="<?=$shopLogoUrl?>" alt="Shop Logo" style="height: 54px; max-width: 120px; object-fit: contain; border-radius: 4px;">
                    <div>
                        <h1 style="font-size: 20px; font-weight: 800; color: #0f172a;"><?=e($shop['name'])?></h1>
                        <p style="color: #64748b; font-size: 12px; margin-top: 2px;"><?=e($shop['address'] ?: 'Authorized Retail & Finance Partner')?></p>
                        <p style="color: #64748b; font-size: 12px;">Ph: <?=e($shop['phone'] ?: 'N/A')?> | Email: <?=e($shop['email'] ?: 'N/A')?></p>
                    </div>
                </div>
            </td>
            <td class="invoice-title">
                <h2>RETAIL SALE INVOICE</h2>
                <p>CUSTOMER RECEIPT</p>
            </td>
        </tr>
    </table>

    <!-- INFO GRID -->
    <div class="info-grid">
        <div class="info-box">
            <h4>Billed To (Customer Details)</h4>
            <p><strong>Customer Name:</strong> <?=e($sale['customer_name'])?></p>
            <p><strong>Contact Mobile:</strong> <?=e($sale['customer_mobile'] ?: 'N/A')?></p>
        </div>
        <div class="info-box">
            <h4>Invoice & Payment Summary</h4>
            <p><strong>Invoice Number:</strong> <span style="font-weight:800; color:#0f172a;"><?=e($sale['invoice_no'])?></span></p>
            <p><strong>Invoice Date:</strong> <?=date('d M Y, h:i A', strtotime($sale['created_at']))?></p>
            <p><strong>Payment Method:</strong> <span style="text-transform:uppercase; font-weight:700; color:#059669;"><?=e($sale['payment_method'])?></span></p>
        </div>
    </div>

    <!-- ITEM TABLE -->
    <table class="item-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 35px;">#</th>
                <th>Description of Goods / Services</th>
                <th class="text-center">HSN/SAC</th>
                <th class="text-center">Qty</th>
                <th class="text-right">Unit Price (₹)</th>
                <th class="text-right">Total Amount (₹)</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $idx => $it): ?>
                <tr>
                    <td class="text-center"><?=$idx + 1?></td>
                    <td>
                        <strong><?=e($it['product_name'])?></strong>
                    </td>
                    <td class="text-center"><?=e($it['hsn_code'] ?: '8517')?></td>
                    <td class="text-center"><?=$it['quantity']?></td>
                    <td class="text-right"><?=number_format($it['unit_price'], 2)?></td>
                    <td class="text-right"><strong><?=number_format($it['total_amount'], 2)?></strong></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- SUMMARY & TOTALS -->
    <div class="summary-grid">
        <div>
            <div class="amount-words">
                <strong style="display:block; margin-bottom: 2px; color: #1e3a8a;">Total Invoice Amount in Words:</strong>
                <em><?=getAmountInWords($sale['grand_total'])?></em>
            </div>
            <div style="margin-top: 14px; font-size: 11px; color: #64748b; line-height: 1.5;">
                <p><strong>Terms & Conditions:</strong></p>
                <p>1. Goods once sold will not be taken back without valid bill receipt.</p>
                <p>2. Subject to local jurisdiction. Computer generated invoice receipt.</p>
            </div>
        </div>

        <div>
            <table class="totals-table">
                <tr>
                    <td>Subtotal:</td>
                    <td class="text-right">₹<?=number_format($sale['subtotal'], 2)?></td>
                </tr>
                <?php if ($sale['discount'] > 0): ?>
                    <tr style="color: #dc2626;">
                        <td>Discount:</td>
                        <td class="text-right">-₹<?=number_format($sale['discount'], 2)?></td>
                    </tr>
                <?php endif; ?>
                <tr class="grand-total">
                    <td>Grand Total:</td>
                    <td class="text-right">₹<?=number_format($sale['grand_total'], 2)?></td>
                </tr>
            </table>
        </div>
    </div>

    <!-- SIGNATURE -->
    <div class="footer-sign">
        <div style="font-size: 11px; color: #64748b;">
            <p>Thank you for shopping with us!</p>
            <p>Powered by <strong>Go4 Finance ERP</strong></p>
        </div>
        <div class="sign-box">
            For <?=e($shop['name'])?><br><br><br>
            Authorized Signatory
        </div>
    </div>
</div>

<?php if ($autoPrint): ?>
<script>
    window.addEventListener('DOMContentLoaded', () => {
        setTimeout(() => { window.print(); }, 500);
    });
</script>
<?php endif; ?>

</body>
</html>
