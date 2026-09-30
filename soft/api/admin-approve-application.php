<?php
require_once __DIR__ . '/../includes/auth.php';
role('superadmin');

$financeId = isset($_POST['finance_id']) ? (int)$_POST['finance_id'] : 0;
$action    = isset($_POST['action']) ? trim($_POST['action']) : '';
$reason    = isset($_POST['rejection_reason']) ? trim($_POST['rejection_reason']) : '';

if ($financeId <= 0 || !in_array($action, ['approve', 'reject'])) {
    header('Location: ' . url('/admin/applications.php?msg=invalid'));
    exit;
}

$db = db();
$adminUser = u();
$adminId   = (int)$adminUser['id'];

try {
    // Fetch Application
    $stmt = $db->prepare("
        SELECT f.*, c.name as customer_name, c.mobile as customer_mobile, c.email as customer_email, s.name as shop_name
        FROM finance_applications f
        JOIN customers c ON c.id = f.customer_id
        LEFT JOIN shops s ON s.id = f.shop_id
        WHERE f.id = ?
    ");
    $stmt->execute([$financeId]);
    $app = $stmt->fetch();

    if (!$app) {
        header('Location: ' . url('/admin/applications.php?msg=notfound'));
        exit;
    }

    if ($action === 'approve') {
        // Officially approve the application, activate the loan, and dispatch customer credentials
        approve_finance_application_and_notify($financeId);

        log_audit(
            'Loan Approved by Superadmin',
            'Applications',
            "Superadmin {$adminUser['name']} (ID {$adminId}) officially approved and sanctioned Loan Application #{$app['application_no']} (₹{$app['finance_amount']}) for customer {$app['customer_name']} (Shop: {$app['shop_name']}).",
            $adminId
        );

        header('Location: ' . url('/admin/applications.php?msg=app_approved&app_no=' . urlencode($app['application_no'])));
        exit;

    } elseif ($action === 'reject') {
        if (empty($reason)) {
            $reason = 'Application rejected by Superadmin after document & KYC review.';
        }

        $db->prepare("UPDATE finance_applications SET status = 'rejected' WHERE id = ?")->execute([$financeId]);

        log_audit(
            'Loan Rejected by Superadmin',
            'Applications',
            "Superadmin {$adminUser['name']} (ID {$adminId}) rejected Loan Application #{$app['application_no']}. Reason: {$reason}",
            $adminId
        );

        header('Location: ' . url('/admin/applications.php?msg=app_rejected&app_no=' . urlencode($app['application_no'])));
        exit;
    }

} catch (Exception $e) {
    header('Location: ' . url('/admin/applications.php?msg=error&err=' . urlencode($e->getMessage())));
    exit;
}
