<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/document_engine.php';

// Auth check: allow if logged in, or token supplied, or loan ID supplied
if (!empty($_GET['token'])) {
    $jwtPath = __DIR__ . '/../../go4fin_api/config/jwt_helper.php';
    if (file_exists($jwtPath)) {
        require_once $jwtPath;
        $jwtUser = AuthHelper::validateToken($_GET['token']);
        if (!$jwtUser) {
            die("Access Denied: Invalid or expired session token.");
        }
    }
} elseif (empty($_GET['id']) && empty($_GET['customer_id']) && empty($_GET['payment_id']) && empty($_GET['doc_no']) && (!function_exists('is_logged_in') || !is_logged_in())) {
    role('superadmin', 'shop_admin', 'staff', 'customer');
}

$docType   = trim($_GET['type'] ?? 'loan_agreement');
$financeId = (int)($_GET['id'] ?? 0);
$customerId = (int)($_GET['customer_id'] ?? 0);
$paymentId = (int)($_GET['payment_id'] ?? 0);
$docNo     = trim($_GET['doc_no'] ?? '');

if ($financeId <= 0 && $paymentId > 0) {
    $p = db();
    $s = $p->prepare("SELECT finance_id, customer_id FROM payments WHERE id = ?");
    $s->execute([$paymentId]);
    $pRow = $s->fetch();
    if ($pRow) {
        $financeId = (int)$pRow['finance_id'];
        $customerId = (int)$pRow['customer_id'];
    }
}

if ($financeId <= 0 && $customerId <= 0 && empty($docNo)) {
    die("Invalid request parameters.");
}

// If doc_no supplied, locate document
if (!empty($docNo) && $financeId <= 0) {
    $p = db();
    $dStmt = $p->prepare("SELECT * FROM documents WHERE document_no = ? LIMIT 1");
    $dStmt->execute([$docNo]);
    $docRow = $dStmt->fetch();
    if ($docRow) {
        $financeId = (int)$docRow['finance_id'];
        $customerId = (int)$docRow['customer_id'];
        $docType = $docRow['type'];
        $paymentId = (int)$docRow['payment_id'];
    }
}

$data = get_loan_document_data($financeId, $customerId, $paymentId);
if (!$data) {
    die("Loan or customer record not found.");
}

// Customer security check
if (function_exists('u')) {
    $currentUser = u();
    if ($currentUser && ($currentUser['role'] ?? '') === 'customer') {
        $custEmail = $currentUser['email'] ?? '';
        $custMobile = str_replace('@customer.local', '', $custEmail);
        $p = db();
        $chk = $p->prepare("SELECT id FROM customers WHERE (email = ? OR mobile = ? OR mobile = ?) AND id = ?");
        $chk->execute([$custEmail, $custEmail, $custMobile, $data['customer_id']]);
        if (!$chk->fetch()) {
            http_response_code(403);
            die("Forbidden: Unauthorized access to document.");
        }
    }
}

// Qualifications for NOC / Closure
if (in_array($docType, ['noc_certificate', 'loan_closure']) && !$data['is_fully_paid']) {
    die("Access Denied: NOC/Closure certificates require 100% repayment clearance.");
}

// Generate / retrieve PDF
$res = generate_pdf_document_file($docType, $data, $paymentId);

if (!file_exists($res['full_path'])) {
    die("Error: Failed to generate PDF on server.");
}

$filename = basename($res['full_path']);
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($res['full_path']));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
readfile($res['full_path']);
exit;
