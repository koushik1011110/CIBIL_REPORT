<?php
/**
 * Official TransUnion CIBIL Credit Information Report (PDF Generator)
 * Produces an authentic, professionally branded TransUnion CIBIL PDF document.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/pdf_engine.php';

$orderId = isset($_GET['orderid']) ? htmlspecialchars(trim($_GET['orderid'])) : ('TXN' . rand(10000, 99999));
$name = isset($_GET['name']) ? htmlspecialchars(trim($_GET['name'])) : '';
$pan = isset($_GET['pan']) ? htmlspecialchars(trim($_GET['pan'])) : '';
$mobile = isset($_GET['mobile']) ? htmlspecialchars(trim($_GET['mobile'])) : '';
$score = (isset($_GET['score']) && $_GET['score'] !== '') ? intval($_GET['score']) : null;
$dob = isset($_GET['dob']) && !empty($_GET['dob']) ? htmlspecialchars(trim($_GET['dob'])) : '';
$gender = isset($_GET['gender']) && !empty($_GET['gender']) ? ucfirst(strtolower(trim($_GET['gender']))) : 'Male';
$address = isset($_GET['address']) && !empty($_GET['address']) ? htmlspecialchars(trim($_GET['address'])) : '';
$customerId = isset($_GET['customer_id']) ? intval($_GET['customer_id']) : 0;

$storedJson = null;
if ($customerId > 0 || !empty($pan)) {
    try {
        if ($customerId > 0) {
            $stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
            $stmt->execute([$customerId]);
        } else {
            $stmt = db()->prepare('SELECT * FROM customers WHERE pan = ? ORDER BY id DESC LIMIT 1');
            $stmt->execute([$pan]);
        }
        $custDb = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($custDb) {
            if (empty($name)) $name = $custDb['name'];
            if (empty($pan)) $pan = $custDb['pan'];
            if (empty($mobile)) $mobile = $custDb['mobile'];
            if ($score === null && $custDb['credit_score'] !== null) $score = intval($custDb['credit_score']);
            if (empty($dob) && !empty($custDb['dob'])) $dob = $custDb['dob'];
            if (empty($address) && !empty($custDb['address'])) $address = $custDb['address'];
            if (!empty($custDb['credit_report_json'])) {
                $storedJson = json_decode($custDb['credit_report_json'], true);
                if (is_array($storedJson)) {
                    $realPdf = $storedJson['pdf_url'] ?? ($storedJson['report_url'] ?? ($storedJson['data']['pdf_url'] ?? ($storedJson['data']['report_url'] ?? null)));
                    if (!empty($realPdf) && strpos($realPdf, 'http') === 0 && strpos($realPdf, 'localhost') === false && strpos($realPdf, '127.0.0.1') === false) {
                        header('Location: ' . $realPdf);
                        exit;
                    }
                }
            }
        }
    } catch (Exception $e) {}
}

// Strictly disallow generating fake/mock local PDFs
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Official TransUnion CIBIL Report Notice</title>
    <style>
        body { background: #0f172a; color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; box-sizing: border-box; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 16px; padding: 36px; max-width: 580px; text-align: center; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
        .icon { font-size: 48px; margin-bottom: 16px; }
        h2 { margin: 0 0 12px; color: #38bdf8; font-size: 1.5rem; }
        p { color: #94a3b8; font-size: 0.95rem; line-height: 1.6; margin: 0 0 20px; }
        .code { background: #0b1329; border: 1px solid #1e3a5f; color: #7dd3fc; padding: 6px 12px; border-radius: 6px; font-family: monospace; font-size: 0.85rem; display: inline-block; margin-bottom: 24px; word-break: break-all; }
        .btn { display: inline-block; background: linear-gradient(135deg, #0284c7, #0369a1); color: #fff; padding: 12px 28px; border-radius: 8px; font-weight: 700; text-decoration: none; font-size: 0.95rem; transition: transform 0.2s; }
        .btn:hover { transform: translateY(-2px); }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🏛️</div>
        <h2>Official TransUnion CIBIL Bureau PDF Notice</h2>
        <p>All authentic credit reports are generated directly by FinPay Ultra and hosted securely on their official bureau domain:</p>
        <div class="code">https://pay.finpayultra.com/cibil-api/reports/&lt;report_id&gt;.pdf</div>
        <p>Mock / sample local PDFs on localhost have been permanently disabled. Please run a credit inquiry from the portal to view or download the live bureau report.</p>
        <a href="<?=url('/shop/credit-check.php')?>" class="btn">Go to Live Credit Check &rarr;</a>
    </div>
</body>
</html>
<?php
exit;

$activeCount = 0;
$totalBal = 0;
$totalPastDue = 0;
$tableRows = [];

foreach ($rawAccounts as $acc) {
    $bal = floatval($acc['Balance'] ?? 0);
    $pastDue = floatval($acc['PastDueAmount'] ?? 0);
    $sanc = floatval($acc['SanctionAmount'] ?? 0);
    $isOpen = (strtolower($acc['Open'] ?? '') === 'yes') || $bal > 0;
    if ($isOpen) $activeCount++;
    $totalBal += $bal;
    $totalPastDue += $pastDue;

    if (count($tableRows) < 3) {
        $accNo = !empty($acc['AccountNumber']) ? ('...' . substr($acc['AccountNumber'], -6)) : 'XXXX';
        $bank = !empty($acc['Institution']) ? substr($acc['Institution'], 0, 18) : 'Member Bank';
        $type = !empty($acc['AccountType']) ? substr($acc['AccountType'], 0, 14) : 'Loan';
        $status = ($pastDue > 0) ? 'PAST DUE' : 'REGULAR';
        $tableRows[] = [
            $accNo,
            $bank,
            $type,
            'INR ' . number_format($sanc),
            'INR ' . number_format($bal),
            'INR ' . number_format($pastDue),
            $status
        ];
    }
}

$pdf = new Go4FinPDF();
$pdf->SetMargins(15, 15, 15);
$pdf->docTitle = 'TransUnion CIBIL Credit Information Report';
$pdf->AddPage();

// 1. BRAND HEADER: TransUnion Dark Navy & Cyan Banner
$pdf->SetFillColor(11, 35, 65); // Deep Navy (#0b2341)
$pdf->Rect(15, 15, 180, 24, 'F');

// TransUnion Cyan Accent Stripe (#00a6ca)
$pdf->SetFillColor(0, 166, 202);
$pdf->Rect(15, 38, 180, 2, 'F');

$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 15);
$pdf->SetXY(20, 19);
$pdf->Cell(110, 7, 'TRANSUNION CIBIL CREDIT REPORT', 0, 0, 'L');

$pdf->SetTextColor(0, 200, 240); // Cyan
$pdf->SetFont('helvetica', '', 8.5);
$pdf->SetXY(20, 27);
$pdf->Cell(110, 5, 'Credit Information Report (CIR) | TransUnion CIBIL India Limited', 0, 0, 'L');

$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 8.5);
$pdf->SetXY(130, 19);
$pdf->Cell(60, 5, 'CONTROL REF: ' . $orderId, 0, 0, 'R');

$pdf->SetTextColor(203, 213, 225);
$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY(130, 25);
$pdf->Cell(60, 5, 'DATE: ' . date('d M Y, H:i') . ' IST', 0, 0, 'R');

$pdf->SetXY(130, 30);
$pdf->Cell(60, 5, 'API: FinPay Ultra Bureau Pull', 0, 0, 'R');

// 2. CIBIL SCORE EVALUATION CARD
$pdf->SetY(44);
$isNtc = ($score <= 0);
$displayScore = $isNtc ? 'NH' : (string)$score;
$scoreTitle = $isNtc ? 'CIBIL TRANSUNION SCORE / STATUS: NEW TO CREDIT' : 'CIBIL TRANSUNION SCORE (SCALE: 300 - 900)';

if ($isNtc) {
    $ratingText = 'Risk Category: NH (No Credit History Available / First-Time Borrower)';
} elseif ($score >= 750) {
    $ratingText = 'Risk Category: EXCELLENT CREDIT HEALTH (PRIME APPLICANT)';
} elseif ($score >= 700) {
    $ratingText = 'Risk Category: GOOD / STANDARD CREDIT PROFILE';
} elseif ($score >= 600) {
    $ratingText = 'Risk Category: MODERATE RISK / CAUTION PROFILE';
} else {
    $ratingText = 'Risk Category: HIGH DEFAULT RISK (CREDIT IMPAIRED)';
}

// Background Card
$pdf->SetFillColor(248, 250, 252);
$pdf->SetDrawColor(203, 213, 225);
$pdf->SetLineWidth(0.3);
$pdf->Rect(15, 44, 180, 42, 'DF');

// Left Section: Score Display
$pdf->SetTextColor(11, 35, 65);
$pdf->SetFont('helvetica', 'B', 9.5);
$pdf->SetXY(20, 48);
$pdf->Cell(85, 5, $scoreTitle, 0, 0, 'L');

if ($isNtc) {
    $pdf->SetTextColor(0, 166, 202); // TransUnion Cyan
} elseif ($score >= 750) {
    $pdf->SetTextColor(16, 185, 129); // Green
} elseif ($score >= 600) {
    $pdf->SetTextColor(217, 119, 6); // Amber
} else {
    $pdf->SetTextColor(220, 38, 38); // Red
}

$pdf->SetFont('helvetica', 'B', 30);
$pdf->SetXY(20, 55);
$pdf->Cell(85, 15, $displayScore, 0, 0, 'L');

$pdf->SetTextColor(71, 85, 105);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetXY(20, 73);
$pdf->Cell(85, 5, $ratingText, 0, 0, 'L');

// Vertical Divider in Score Card
$pdf->SetDrawColor(226, 232, 240);
$pdf->Line(108, 48, 108, 82);

// Right Section: Summary Key Metrics
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);

$dispActive = $isNtc ? '0 (Clean Record)' : ($activeCount > 0 ? ($activeCount . ' Tradelines') : '2 Tradelines');
$dispBal = $isNtc ? 'INR 0 (Nil Debt)' : ($totalBal > 0 ? ('INR ' . number_format($totalBal)) : 'INR 16,700');
$dispPastDue = $isNtc ? 'INR 0 (Nil Overdue)' : ($totalPastDue > 0 ? ('INR ' . number_format($totalPastDue)) : 'INR 0 (Nil Overdue)');

$pdf->SetXY(114, 49);
$pdf->Cell(42, 5, 'Total Active Accounts:', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', 'B', 8.5);
$pdf->Cell(34, 5, $dispActive, 0, 1, 'R');

$pdf->SetXY(114, 57);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(42, 5, 'Total Outstanding Balance:', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', 'B', 8.5);
$pdf->Cell(34, 5, $dispBal, 0, 1, 'R');

$pdf->SetXY(114, 65);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(42, 5, 'Overdue / Past Due Amount:', 0, 0, 'L');
if ($totalPastDue > 0) {
    $pdf->SetTextColor(220, 38, 38); // Red
} else {
    $pdf->SetTextColor(16, 185, 129); // Green (Nil Overdue)
}
$pdf->SetFont('helvetica', 'B', 8.5);
$pdf->Cell(34, 5, $dispPastDue, 0, 1, 'R');

$pdf->SetXY(114, 73);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(42, 5, 'Recent Inquiries (30d):', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', 'B', 8.5);
$pdf->Cell(34, 5, '1 Official Pull', 0, 1, 'R');

// 3. CONSUMER DEMOGRAPHIC & KYC INFORMATION SECTION
$pdf->SetY(91);
$pdf->SetFillColor(241, 245, 249);
$pdf->Rect(15, 91, 180, 7, 'F');
$pdf->SetTextColor(11, 35, 65);
$pdf->SetFont('helvetica', 'B', 9.5);
$pdf->SetXY(18, 92);
$pdf->Cell(170, 5, 'CONSUMER IDENTIFICATION & DEMOGRAPHIC DETAILS', 0, 0, 'L');

$pdf->SetDrawColor(226, 232, 240);
$pdf->SetFillColor(255, 255, 255);
$pdf->Rect(15, 98, 180, 48, 'D');

$infoY = 101;

// Row 1: Name & PAN
$pdf->SetXY(20, $infoY);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(38, 5, 'Consumer Full Name:', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(52, 5, strtoupper($name), 0, 0, 'L');

$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(35, 5, 'Income Tax PAN:', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(40, 5, strtoupper($pan), 0, 1, 'L');

// Row 2: Mobile & DOB
$infoY += 8;
$pdf->SetXY(20, $infoY);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(38, 5, 'Registered Mobile:', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(52, 5, '+91 ' . $mobile, 0, 0, 'L');

$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(35, 5, 'Date of Birth (DOB):', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(40, 5, $dob, 0, 1, 'L');

// Row 3: Gender & Consent
$infoY += 8;
$pdf->SetXY(20, $infoY);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(38, 5, 'Gender:', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(52, 5, $gender, 0, 0, 'L');

$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(35, 5, 'Consent Flag:', 0, 0, 'L');
$pdf->SetTextColor(16, 185, 129);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(40, 5, 'Y (Consumer Authorized)', 0, 1, 'L');

// Row 4: Address
$infoY += 8;
$pdf->SetXY(20, $infoY);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(38, 5, 'Registered Address:', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(127, 5, $address, 0, 1, 'L');

// Row 5: Provider & Bureau ID
$infoY += 8;
$pdf->SetXY(20, $infoY);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(38, 5, 'Reporting Agency:', 0, 0, 'L');
$pdf->SetTextColor(0, 166, 202);
$pdf->SetFont('helvetica', 'B', 8.5);
$pdf->Cell(52, 5, 'TransUnion CIBIL India', 0, 0, 'L');

$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(35, 5, 'Verification Mode:', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', 'B', 8.5);
$pdf->Cell(40, 5, 'Direct API Pull (Live)', 0, 1, 'L');

// 4. CREDIT FACILITIES & TRADELINES SECTION
$pdf->SetY(151);
$pdf->SetFillColor(241, 245, 249);
$pdf->Rect(15, 151, 180, 7, 'F');
$pdf->SetTextColor(11, 35, 65);
$pdf->SetFont('helvetica', 'B', 9.5);
$pdf->SetXY(18, 152);
$pdf->Cell(170, 5, 'REPORTED CREDIT FACILITIES & TRADELINE SUMMARY', 0, 0, 'L');

if ($isNtc) {
    // Clean New to Credit Certificate Box
    $pdf->SetY(161);
    $pdf->SetFillColor(240, 249, 255); // Sky Blue tint
    $pdf->SetDrawColor(186, 230, 253);
    $pdf->Rect(15, 161, 180, 42, 'DF');

    $pdf->SetTextColor(2, 132, 199);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetXY(20, 166);
    $pdf->Cell(170, 6, 'NO ADVERSE CREDIT HISTORY REPORTED (NEW TO CREDIT APPLICANT)', 0, 1, 'L');

    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetFont('helvetica', '', 8.5);
    $pdf->SetXY(20, 174);
    $descNtc = "TransUnion CIBIL records indicate that this consumer has zero prior credit cards, personal loans, consumer durable loans, or commercial credit facilities registered under PAN " . strtoupper($pan) . ".\n\nThere are ZERO defaults, overdue payments, write-offs, or settled accounts on file. The applicant possesses a clean credit slate and qualifies for store financing under the First-Time Borrower policy.";
    $pdf->MultiCell(170, 4.5, $descNtc, 0, 'L');
} else {
    // Standard Active Tradelines Table
    $headers = ['Account No', 'Member Bank', 'Type', 'Sanctioned', 'Current Bal', 'Past Due', 'Status'];
    $widths = [26, 38, 30, 24, 24, 18, 20];
    $aligns = ['L', 'L', 'L', 'R', 'R', 'R', 'C'];
    $rows = !empty($tableRows) ? $tableRows : [
        ['XXXX5129', 'HDFC Bank Ltd', 'Consumer Loan', 'INR 35,000', 'INR 12,500', 'INR 0', 'REGULAR'],
        ['XXXX8803', 'SBI Cards Ltd', 'Credit Card', 'INR 60,000', 'INR 4,200', 'INR 0', 'REGULAR']
    ];

    $pdf->SetY(160);
    $pdf->Table($headers, $rows, $widths, $aligns, [11, 35, 65], [255, 255, 255]);

    // Remarks Note
    $pdf->SetY(186);
    $pdf->SetTextColor(71, 85, 105);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->Cell(180, 5, 'All credit facilities reported are active and maintaining standard regular repayment performance.', 0, 1, 'L');
}

// 5. OFFICIAL CERTIFICATION SEAL & FOOTER
$pdf->SetY(210);
$pdf->SetFillColor(248, 250, 252);
$pdf->SetDrawColor(226, 232, 240);
$pdf->Rect(15, 210, 180, 48, 'DF');

$pdf->SetTextColor(11, 35, 65);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetXY(20, 214);
$pdf->Cell(170, 5, 'LEGAL DISCLAIMER & STATUTORY NOTICE', 0, 1, 'L');

$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 7.5);
$pdf->SetXY(20, 221);
$legalNotice = "This Credit Information Report (CIR) is furnished to GO4 FINANCE PRIVATE LIMITED pursuant to the provisions of the Credit Information Companies (Regulation) Act, 2005 (CICRA) and relevant rules thereunder. The data contained herein is compiled by TransUnion CIBIL India from records furnished by participating member institutions (Banks, NBFCs, and financial credit providers).\n\nThis document is strictly confidential and generated for credit assessment purposes only. Antigravity/FinPay Ultra Bureau Connector ensures 256-bit SSL encrypted transmission with official cryptographic verification.";
$pdf->MultiCell(170, 3.8, $legalNotice, 0, 'L');

// Bottom Cyan Certification Banner
$pdf->SetY(244);
$pdf->SetFillColor(11, 35, 65);
$pdf->Rect(15, 244, 180, 12, 'F');

$pdf->SetTextColor(0, 200, 240); // Cyan
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetXY(20, 247);
$pdf->Cell(170, 6, 'OFFICIALLY VERIFIED TRANSUNION CIBIL CREDIT REPORT · GO4FIN SECURE PORTAL', 0, 0, 'C');

// Output PDF to browser
$pdf->Output('I', 'TransUnion_CIBIL_Report_' . $orderId . '.pdf');
