<?php
require_once __DIR__ . '/../includes/pdf_engine.php';

$orderId = isset($_GET['orderid']) ? htmlspecialchars(trim($_GET['orderid'])) : 'TXN' . time();
$name = isset($_GET['name']) ? htmlspecialchars(trim($_GET['name'])) : 'TEST CUSTOMER';
$pan = isset($_GET['pan']) ? htmlspecialchars(trim($_GET['pan'])) : 'ABCDE1234F';
$mobile = isset($_GET['mobile']) ? htmlspecialchars(trim($_GET['mobile'])) : '9876543210';
$score = isset($_GET['score']) ? intval($_GET['score']) : 765;
$dob = isset($_GET['dob']) ? htmlspecialchars(trim($_GET['dob'])) : '1990-01-01';
$gender = isset($_GET['gender']) ? ucfirst(strtolower(trim($_GET['gender']))) : 'Male';
$address = isset($_GET['address']) ? htmlspecialchars(trim($_GET['address'])) : 'Main Road, Guwahati, Assam - 781001';

$pdf = new Go4FinPDF();
$pdf->SetMargins(15, 15, 15);
$pdf->docTitle = 'Equifax Credit Report PDF v2';
$pdf->AddPage();

// Brand Header Banner
$pdf->SetFillColor(26, 43, 76); // Deep Navy Blue
$pdf->Rect(15, 15, 180, 22, 'F');

$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('helvetica', 'B', 16);
$pdf->SetXY(20, 18);
$pdf->Cell(100, 8, 'EQUIFAX CREDIT INFORMATION REPORT', 0, 0, 'L');

$pdf->SetFont('helvetica', '', 9);
$pdf->SetXY(20, 26);
$pdf->Cell(100, 6, 'Bureau Service: Credit Report EquiFax PDF v2 (Test Mode Sandbox)', 0, 0, 'L');

$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetXY(135, 18);
$pdf->Cell(55, 6, 'ORDER ID: ' . $orderId, 0, 0, 'R');
$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY(135, 25);
$pdf->Cell(55, 5, 'Date: ' . date('d M Y, H:i'), 0, 0, 'R');

// Score Box
$pdf->SetY(42);
$pdf->SetFillColor(245, 247, 250);
$pdf->SetDrawColor(218, 225, 233);
$pdf->Rect(15, 42, 180, 40, 'DF');

// Left Column of Score Box: Score Gauge Representation
$pdf->SetTextColor(30, 41, 59);
$pdf->SetFont('helvetica', 'B', 11);
$pdf->SetXY(20, 46);
$pdf->Cell(80, 6, 'EQUIFAX RISK SCORE (ERS)', 0, 0, 'L');

$pdf->SetTextColor(16, 185, 129); // Green
$pdf->SetFont('helvetica', 'B', 28);
$pdf->SetXY(20, 53);
$pdf->Cell(80, 14, (string)$score, 0, 0, 'L');

$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 9);
$pdf->SetXY(20, 69);
$pdf->Cell(80, 5, 'Score Range: 300 - 900 | Rating: EXCELLENT RISK', 0, 0, 'L');

// Right Column: Summary Stats
$pdf->SetTextColor(30, 41, 59);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetXY(110, 46);
$pdf->Cell(40, 6, 'Total Accounts:', 0, 0, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(40, 6, '2 Active', 0, 1, 'L');

$pdf->SetXY(110, 54);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(40, 6, 'Total Outstanding:', 0, 0, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(40, 6, 'INR 16,700', 0, 1, 'L');

$pdf->SetXY(110, 62);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(40, 6, 'Overdue Balance:', 0, 0, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(40, 6, 'INR 0 (Nil Overdue)', 0, 1, 'L');

$pdf->SetXY(110, 70);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(40, 6, 'Recent Inquiries (30d):', 0, 0, 'L');
$pdf->SetFont('helvetica', '', 9);
$pdf->Cell(40, 6, '1 Bureau Pull', 0, 1, 'L');

// Consumer Personal Information Section
$pdf->SetY(87);
$pdf->SetFillColor(234, 240, 248);
$pdf->Rect(15, 87, 180, 7, 'F');
$pdf->SetTextColor(26, 43, 76);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetXY(18, 88);
$pdf->Cell(170, 5, 'CONSUMER DEMOGRAPHIC INFORMATION', 0, 0, 'L');

$pdf->SetDrawColor(226, 232, 240);
$pdf->SetFillColor(255, 255, 255);
$pdf->Rect(15, 94, 180, 45, 'D');

$infoY = 97;
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);

$pdf->SetXY(20, $infoY);
$pdf->Cell(40, 5, 'Full Legal Name:', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(50, 5, strtoupper($name), 0, 0, 'L');

$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(35, 5, 'PAN Card Number:', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(40, 5, strtoupper($pan), 0, 1, 'L');

$infoY += 8;
$pdf->SetXY(20, $infoY);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(40, 5, 'Mobile Number:', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(50, 5, '+91 ' . $mobile, 0, 0, 'L');

$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(35, 5, 'Date of Birth (DOB):', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(40, 5, $dob, 0, 1, 'L');

$infoY += 8;
$pdf->SetXY(20, $infoY);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(40, 5, 'Gender:', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(50, 5, $gender, 0, 0, 'L');

$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(35, 5, 'Consent Flag:', 0, 0, 'L');
$pdf->SetTextColor(16, 185, 129);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->Cell(40, 5, 'Y (Consent Verified)', 0, 1, 'L');

$infoY += 8;
$pdf->SetXY(20, $infoY);
$pdf->SetTextColor(100, 116, 139);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(40, 5, 'Registered Address:', 0, 0, 'L');
$pdf->SetTextColor(15, 23, 42);
$pdf->SetFont('helvetica', '', 8.5);
$pdf->Cell(125, 5, $address, 0, 1, 'L');

// Credit Account Trade Lines Table
$pdf->SetY(145);
$pdf->SetFillColor(234, 240, 248);
$pdf->Rect(15, 145, 180, 7, 'F');
$pdf->SetTextColor(26, 43, 76);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetXY(18, 146);
$pdf->Cell(170, 5, 'ACTIVE CREDIT TRADE LINES & REPAYMENT TRACK RECORD', 0, 0, 'L');

// Table Headers
$tableY = 153;
$pdf->SetFillColor(241, 245, 249);
$pdf->Rect(15, $tableY, 180, 7, 'F');
$pdf->SetDrawColor(203, 213, 225);
$pdf->Rect(15, $tableY, 180, 7, 'D');

$pdf->SetTextColor(51, 65, 85);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->SetXY(17, $tableY + 1.5);
$pdf->Cell(45, 4, 'Credit Facility / Type', 0, 0, 'L');
$pdf->Cell(40, 4, 'Account Reference', 0, 0, 'L');
$pdf->Cell(30, 4, 'Sanctioned Limit', 0, 0, 'R');
$pdf->Cell(30, 4, 'Current Balance', 0, 0, 'R');
$pdf->Cell(30, 4, 'Status / Past Due', 0, 0, 'R');

$trades = [
    ['type' => 'Consumer Loan / Electronics', 'ref' => 'HDFC-XXXX8412', 'sanction' => 'INR 50,000', 'balance' => 'INR 12,500', 'status' => 'REGULAR / NIL'],
    ['type' => 'Credit Card (Revolving)', 'ref' => 'SBI-XXXX3190', 'sanction' => 'INR 60,000', 'balance' => 'INR 4,200', 'status' => 'REGULAR / NIL'],
];

$rowY = $tableY + 7;
foreach ($trades as $t) {
    $pdf->SetDrawColor(226, 232, 240);
    $pdf->Rect(15, $rowY, 180, 7, 'D');
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetXY(17, $rowY + 1.5);
    $pdf->Cell(45, 4, $t['type'], 0, 0, 'L');
    $pdf->Cell(40, 4, $t['ref'], 0, 0, 'L');
    $pdf->Cell(30, 4, $t['sanction'], 0, 0, 'R');
    $pdf->Cell(30, 4, $t['balance'], 0, 0, 'R');
    $pdf->SetTextColor(16, 185, 129);
    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->Cell(30, 4, $t['status'], 0, 0, 'R');
    $rowY += 7;
}

// 24 Month Repayment Matrix
$pdf->SetY($rowY + 6);
$pdf->SetFillColor(234, 240, 248);
$pdf->Rect(15, $rowY + 6, 180, 7, 'F');
$pdf->SetTextColor(26, 43, 76);
$pdf->SetFont('helvetica', 'B', 10);
$pdf->SetXY(18, $rowY + 7);
$pdf->Cell(170, 5, '24-MONTH PAYMENT STRING HISTORY (000 = ON TIME)', 0, 0, 'L');

$matrixY = $rowY + 14;
$pdf->SetDrawColor(226, 232, 240);
$pdf->Rect(15, $matrixY, 180, 16, 'D');
$pdf->SetTextColor(71, 85, 105);
$pdf->SetFont('helvetica', '', 8);
$pdf->SetXY(20, $matrixY + 2);
$pdf->Cell(170, 4, 'Payment History: 000/000/000/000/000/000/000/000/000/000/000/000 (Past 12 Months)', 0, 1, 'L');
$pdf->SetXY(20, $matrixY + 7);
$pdf->Cell(170, 4, 'Settlement / Written-off Accounts: NONE | Default Count: 0', 0, 1, 'L');
$pdf->SetXY(20, $matrixY + 11);
$pdf->SetTextColor(16, 185, 129);
$pdf->SetFont('helvetica', 'B', 8);
$pdf->Cell(170, 4, 'Bureau Recommendation: ELIGIBLE FOR INSTORE EMI FINANCING (SCORE >= 600)', 0, 1, 'L');

// Legal Disclaimer & Test Mode Watermark Notice
$pdf->SetY(240);
$pdf->SetFillColor(254, 242, 242);
$pdf->SetDrawColor(254, 202, 202);
$pdf->Rect(15, 240, 180, 28, 'DF');

$pdf->SetTextColor(153, 27, 27);
$pdf->SetFont('helvetica', 'B', 9);
$pdf->SetXY(20, 243);
$pdf->Cell(170, 5, 'API Test Mode Certificate Notice', 0, 1, 'L');

$pdf->SetTextColor(127, 29, 29);
$pdf->SetFont('helvetica', '', 7.5);
$pdf->SetXY(20, 249);
$pdf->MultiCell(170, 3.8, "This document was generated under API Test Mode (test_mode=1 & test_status=SUCCESS). FinPay Ultra API Key and IP were verified; no live wallet balance was debited (wallet_debit: 0). This certified document satisfies bureau inquiry test protocols for method: Credit Report EquiFax PDF v2.");

// Output PDF Stream
$pdf->Output('I', 'Equifax_Report_' . $orderId . '.pdf');
exit;
