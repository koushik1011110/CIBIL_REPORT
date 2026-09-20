<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/document_engine.php';
require_once __DIR__ . '/includes/document_renderer.php';

role('superadmin', 'shop_admin', 'staff', 'customer');

$docType   = trim($_GET['type'] ?? 'loan_agreement');
$financeId = (int)($_GET['id'] ?? 0);
$customerId = (int)($_GET['customer_id'] ?? 0);
$paymentId = (int)($_GET['payment_id'] ?? 0);

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

if ($financeId <= 0 && $customerId <= 0) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px; background:#0f172a; color:#fff;'><h2 style='color:#ef4444;'>Invalid Request</h2><p>Please specify a valid Loan Application ID or Customer ID.</p><a href='javascript:history.back()' style='color:#3b82f6;'>← Go Back</a></div>");
}

// Fetch Document Data
$data = get_loan_document_data($financeId, $customerId, $paymentId);
if (!$data) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px; background:#0f172a; color:#fff;'><h2 style='color:#ef4444;'>Record Not Found</h2><p>The requested loan or customer record does not exist.</p><a href='javascript:history.back()' style='color:#3b82f6;'>← Go Back</a></div>");
}

// Security: Verify customer ownership if role is customer
$currentUser = u();
if ($currentUser['role'] === 'customer') {
    $custEmail = $currentUser['email'] ?? '';
    $custMobile = str_replace('@customer.local', '', $custEmail);
    if ($data['customer_id'] !== (int)($currentUser['id']) && 
        $data['customer_email'] !== $custEmail && 
        $data['customer_mobile'] !== $custMobile) {
        // Double check customer ID via customers table
        $p = db();
        $chk = $p->prepare("SELECT id FROM customers WHERE (email = ? OR mobile = ? OR mobile = ?) AND id = ?");
        $chk->execute([$custEmail, $custEmail, $custMobile, $data['customer_id']]);
        if (!$chk->fetch()) {
            http_response_code(403);
            die("<div style='font-family:sans-serif; text-align:center; padding:50px; background:#0f172a; color:#fff;'><h2 style='color:#ef4444;'>Access Denied</h2><p>You can only view documents belonging to your own loan account.</p></div>");
        }
    }
}

// Check qualifications for NOC & Closure Certificate
if (in_array($docType, ['noc_certificate', 'loan_closure'])) {
    if (!$data['is_fully_paid']) {
        $unpaid = $data['unpaid_emis_count'];
        die("<div style='font-family:sans-serif; text-align:center; padding:60px 20px; background:#0f172a; color:#fff; min-height:100vh;'>
            <div style='max-width:500px; margin:0 auto; background:rgba(30,41,59,0.95); padding:35px; border-radius:16px; border:1px solid #ef4444;'>
                <h2 style='color:#ef4444; margin-top:0;'>🔒 Document Locked</h2>
                <p style='color:#cbd5e1; font-size:0.95rem; line-height:1.6;'>
                    No Objection Certificate (NOC) and Loan Closure Certificates are issued only after <strong>100% EMI repayment</strong> on active loans.<br>
                    You have <strong style='color:#f87171;'>{$unpaid} unpaid EMI installment(s) remaining</strong>.
                </p>
                <p style='margin-top:25px;'>
                    <a href='javascript:history.back()' style='display:inline-block; padding:10px 22px; background:#2563eb; color:#fff; text-decoration:none; border-radius:8px; font-weight:700;'>← Go Back</a>
                </p>
            </div>
        </div>");
    }
}

// Generate Document Number & Fetch Template
$docNo = generate_document_number($docType, $data['finance_id'], $paymentId);
$data['document_no'] = $docNo;
$template = get_document_template($docType);

// Record in document history
record_document_history(
    $docType,
    $data['finance_id'],
    $data['customer_id'],
    $docNo,
    'uploads/documents/' . $docNo . '.pdf',
    $template['title'] ?? ucwords(str_replace('_', ' ', $docType)),
    $paymentId,
    ['viewed_at' => date('Y-m-d H:i:s')]
);

// Render Document HTML
render_document_html($docType, $data, $docNo, $template);
