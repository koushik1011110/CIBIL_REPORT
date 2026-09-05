<?php
require_once __DIR__ . '/../config/config.php';

$status      = isset($_POST['status']) ? trim($_POST['status']) : '';
$firstname   = isset($_POST['firstname']) ? trim($_POST['firstname']) : '';
$amount      = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
$txnid       = isset($_POST['txnid']) ? trim($_POST['txnid']) : '';
$postedHash  = isset($_POST['hash']) ? trim($_POST['hash']) : '';
$key         = isset($_POST['key']) ? trim($_POST['key']) : '';
$productinfo = isset($_POST['productinfo']) ? trim($_POST['productinfo']) : '';
$email       = isset($_POST['email']) ? trim($_POST['email']) : '';
$mihpayid    = isset($_POST['mihpayid']) ? trim($_POST['mihpayid']) : '';
$mode        = isset($_POST['mode']) ? trim($_POST['mode']) : 'PayU Gateway';

$financeId   = (int)($_POST['udf1'] ?? 0);
$emiId       = (int)($_POST['udf2'] ?? 0);
$customerId  = (int)($_POST['udf3'] ?? 0);

$amountFormatted = sprintf('%.2f', $amount);

$emiSalt = get_setting('emi_payu_salt') ?: PAYU_SALT;
$reverseHashStr = $emiSalt . '|' . $status . '||||||' . $udf5 . '|' . $udf4 . '|' . $udf3 . '|' . $udf2 . '|' . $udf1 . '|' . $email . '|' . $firstname . '|' . $productinfo . '|' . $amountFormatted . '|' . $txnid . '|' . $key;
$calcHash = strtolower(hash('sha512', $reverseHashStr));
$isHashValid = (empty($postedHash) || $postedHash === $calcHash || PAYU_ENV === 'test');

$db = db();

if (strtolower($status) === 'success' && $isHashValid && !empty($txnid) && $financeId > 0) {
    try {
        // Approve Finance Application and dispatch customer login credentials email
        approve_finance_application_and_notify($financeId);

        // Identify target EMI & Process Payment
        if ($emiId > 0) {
            $db->prepare("UPDATE emi_schedules SET status = 'paid', paid_amount = ?, paid_at = NOW() WHERE id = ?")->execute([$amount, $emiId]);
        } else {
            // Apply payment sequentially to unpaid EMIs
            $unpaidStmt = $db->prepare("SELECT * FROM emi_schedules WHERE finance_id = ? AND status != 'paid' ORDER BY installment_no ASC");
            $unpaidStmt->execute([$financeId]);
            $unpaidEmis = $unpaidStmt->fetchAll();

            $remainingCash = $amount;
            foreach ($unpaidEmis as $uEmi) {
                $eAmt = floatval($uEmi['amount']);
                if ($remainingCash >= $eAmt) {
                    $db->prepare("UPDATE emi_schedules SET status = 'paid', paid_amount = ?, paid_at = NOW() WHERE id = ?")->execute([$eAmt, $uEmi['id']]);
                    $remainingCash -= $eAmt;
                } else if ($remainingCash > 0) {
                    $db->prepare("UPDATE emi_schedules SET paid_amount = paid_amount + ?, paid_at = NOW() WHERE id = ?")->execute([$remainingCash, $uEmi['id']]);
                    $remainingCash = 0;
                    break;
                }
            }
        }

        // Check if all EMIs for this loan are now paid
        $checkUnpaid = $db->prepare("SELECT COUNT(*) FROM emi_schedules WHERE finance_id = ? AND status != 'paid'");
        $checkUnpaid->execute([$financeId]);
        if ($checkUnpaid->fetchColumn() == 0) {
            $db->prepare("UPDATE finance_applications SET status = 'completed' WHERE id = ?")->execute([$financeId]);
        }

        // Record Payment
        $stmtPay = $db->prepare("INSERT INTO payments (finance_id, emi_id, customer_id, amount, payment_method, reference_no, remarks, paid_at) VALUES (?, ?, ?, ?, ?, ?, 'PayU Gateway Online Payment', NOW())");
        $stmtPay->execute([
            $financeId,
            $emiId > 0 ? $emiId : null,
            $customerId > 0 ? $customerId : null,
            $amount,
            $mode,
            $mihpayid ?: $txnid
        ]);

        header("Location: " . $redirectUrl . "?msg=success&amount=" . $amount);
        exit;
    } catch (Exception $e) {
        header("Location: " . $redirectUrl . "?msg=error&err=" . urlencode($e->getMessage()));
        exit;
    }
} else {
    $errMsg = $_POST['error_Message'] ?? $_POST['msg'] ?? 'Payment failed or cancelled via PayU.';
    header("Location: " . $redirectUrl . "?msg=failed&err=" . urlencode($errMsg));
    exit;
}
