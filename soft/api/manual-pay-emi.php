<?php
require_once __DIR__ . '/../includes/auth.php';
role('superadmin', 'shop_admin', 'staff');

$financeId = isset($_POST['finance_id']) ? (int)$_POST['finance_id'] : 0;
$emiIdRaw   = isset($_POST['emi_id']) ? $_POST['emi_id'] : 0;
$emiId     = is_numeric($emiIdRaw) ? (int)$emiIdRaw : 0;
$amount    = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
$remarks   = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
$isForeclosure = (isset($_POST['is_foreclosure']) && $_POST['is_foreclosure'] == 1) || ($emiIdRaw === 'full' || strpos((string)$emiIdRaw, 'full') !== false);

$db = db();
$userId = (int)u()['id'];

if ($emiId > 0 && $financeId <= 0) {
    $stmtEmi = $db->prepare('SELECT finance_id FROM emi_schedules WHERE id = ?');
    $stmtEmi->execute([$emiId]);
    $financeId = (int)$stmtEmi->fetchColumn();
}

if ($financeId <= 0 || $amount <= 0) {
    header('Location: ' . $_SERVER['HTTP_REFERER'] . '?msg=invalid');
    exit;
}

try {
    // Fetch Finance Application
    $stmtApp = $db->prepare('SELECT * FROM finance_applications WHERE id = ?');
    $stmtApp->execute([$financeId]);
    $app = $stmtApp->fetch();

    if (!$app) {
        header('Location: ' . $_SERVER['HTTP_REFERER'] . '?msg=notfound');
        exit;
    }

    $isDownPayment = (isset($_POST['is_downpayment']) && $_POST['is_downpayment'] == 1) || (in_array($app['status'], ['pending', 'kyc_completed']) && $emiId === 0 && !$isForeclosure);

    // Approve application if still pending
    if (in_array($app['status'], ['pending', 'kyc_completed'])) {
        approve_finance_application_and_notify($financeId);
    }

    $installmentNo = null;
    $dueDateStr = '';

    if ($isDownPayment) {
        if (empty($remarks)) {
            $remarks = 'Down Payment Received (Application Approved)';
        }
    } else if ($isForeclosure) {
        // FULL FORECLOSURE / BULK SETTLEMENT
        // Mark ALL pending EMI schedules as paid
        $db->prepare("UPDATE emi_schedules SET status = 'paid', paid_amount = amount, paid_at = NOW() WHERE finance_id = ? AND status != 'paid'")->execute([$financeId]);
        
        // Mark application as completed / fully paid
        $db->prepare("UPDATE finance_applications SET status = 'completed' WHERE id = ?")->execute([$financeId]);
        
        if (empty($remarks)) {
            $remarks = 'Full Loan Foreclosure / Early Settlement - Loan Fully Repaid & Closed';
        }
    } else {
        // BULK / SINGLE EMI PAYMENT PROCESSOR
        $unpaidStmt = $db->prepare("SELECT * FROM emi_schedules WHERE finance_id = ? AND status != 'paid' ORDER BY installment_no ASC");
        $unpaidStmt->execute([$financeId]);
        $unpaidEmis = $unpaidStmt->fetchAll();

        $remainingCash = $amount;
        $clearedCount = 0;

        if ($emiId > 0) {
            // Target specific EMI first
            $db->prepare("UPDATE emi_schedules SET status = 'paid', paid_amount = ?, paid_at = NOW() WHERE id = ?")->execute([$amount, $emiId]);
            $eStmt = $db->prepare("SELECT installment_no, due_date FROM emi_schedules WHERE id = ?");
            $eStmt->execute([$emiId]);
            $eData = $eStmt->fetch();
            if ($eData) {
                $installmentNo = $eData['installment_no'];
                $dueDateStr = date('M Y', strtotime($eData['due_date']));
            }
        } else {
            // Sequentially clear unpaid EMIs with received lump sum
            foreach ($unpaidEmis as $uEmi) {
                $eAmt = floatval($uEmi['amount']);
                if ($remainingCash >= $eAmt) {
                    $db->prepare("UPDATE emi_schedules SET status = 'paid', paid_amount = ?, paid_at = NOW() WHERE id = ?")
                       ->execute([$eAmt, $uEmi['id']]);
                    $remainingCash -= $eAmt;
                    $clearedCount++;
                } else if ($remainingCash > 0) {
                    $db->prepare("UPDATE emi_schedules SET paid_amount = paid_amount + ?, paid_at = NOW() WHERE id = ?")
                       ->execute([$remainingCash, $uEmi['id']]);
                    $remainingCash = 0;
                    break;
                }
            }
        }

        // Check if all EMIs for this loan are now cleared
        $checkUnpaid = $db->prepare("SELECT COUNT(*) FROM emi_schedules WHERE finance_id = ? AND status != 'paid'");
        $checkUnpaid->execute([$financeId]);
        if ($checkUnpaid->fetchColumn() == 0) {
            $db->prepare("UPDATE finance_applications SET status = 'completed' WHERE id = ?")->execute([$financeId]);
            if (empty($remarks)) {
                $remarks = "Full Loan Settlement Completed (₹{$amount} Paid - Loan Closed)";
            }
        } else {
            if (empty($remarks)) {
                $remarks = 'Cash Payment' . ($installmentNo ? " for Installment #{$installmentNo} ({$dueDateStr})" : " ({$clearedCount} Month(s) Cleared)");
            }
        }
    }

    // Insert Payment Record
    $refNo = ($isForeclosure ? 'FCL' : 'MAN') . time() . rand(100, 999);
    $stmtPay = $db->prepare("INSERT INTO payments (finance_id, emi_id, customer_id, amount, payment_method, reference_no, remarks, paid_at, recorded_by) VALUES (?, ?, ?, ?, 'Cash', ?, ?, NOW(), ?)");
    $stmtPay->execute([
        $financeId,
        $emiId > 0 ? $emiId : null,
        $app['customer_id'],
        $amount,
        $refNo,
        $remarks,
        $userId
    ]);

    log_audit(
        $isForeclosure ? 'Loan Full Foreclosure' : 'Cash EMI Collection',
        'Collections',
        "User ID {$userId} recorded collection of ₹{$amount} for Application {$app['application_no']} (Ref: {$refNo}). Remarks: {$remarks}",
        $userId
    );

    header('Location: ' . $_SERVER['HTTP_REFERER'] . '?msg=paid_success');
    exit;

} catch (Exception $e) {
    header('Location: ' . $_SERVER['HTTP_REFERER'] . '?msg=error&err=' . urlencode($e->getMessage()));
    exit;
}
