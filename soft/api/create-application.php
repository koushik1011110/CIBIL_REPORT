<?php
require_once __DIR__.'/../includes/auth.php';
role('superadmin','shop_admin','staff');
header('Content-Type: application/json');

try {
    $customerId   = (int)($_POST['customer_id'] ?? 0);
    $productId    = (int)($_POST['product_id'] ?? 0);
    $productPrice = (float)($_POST['product_price'] ?? 0);
    $downPayment  = (float)($_POST['down_payment'] ?? 0);
    $tenure       = (int)($_POST['tenure'] ?? 6);
    $interestRate = (float)($_POST['interest_rate'] ?? 2.0);

    if ($customerId <= 0 || $productPrice <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters provided for finance application.']);
        exit;
    }

    // Verify Customer Credit Score eligibility: Bureau check is MANDATORY and score must be >= 600 or verified New to Credit
    $cStmt = db()->prepare('SELECT credit_score, credit_report_json FROM customers WHERE id = ?');
    $cStmt->execute([$customerId]);
    $cRow = $cStmt->fetch();
    $custScore = $cRow ? $cRow['credit_score'] : null;

    if ($custScore === false || $custScore === null) {
        echo json_encode([
            'success' => false,
            'message' => 'Loan application blocked: Credit bureau check has not been performed for this customer yet. A verified credit score or bureau inquiry is required before submitting a loan application.'
        ]);
        exit;
    }

    $isNtc = ((int)$custScore <= 0);
    if ($isNtc) {
        $allowNtc = get_setting('allow_new_to_credit_finance', '1') === '1';
        if (!$allowNtc) {
            echo json_encode([
                'success' => false,
                'message' => 'Loan application notice: Customer is New to Credit (NH / Score -1) with zero prior loan history. Under current store risk policy, automated financing requires a score >= 600.'
            ]);
            exit;
        }
    } elseif ((int)$custScore < 600) {
        echo json_encode([
            'success' => false,
            'message' => 'Loan application rejected: Customer credit score (' . (int)$custScore . ') is below the minimum required limit of 600.'
        ]);
        exit;
    }

    // Processing Fee (₹399 default) and Device Insurance (₹599 default)
    $processingFee = isset($_POST['processing_fee']) ? (float)$_POST['processing_fee'] : 399.00;
    $insuranceFee  = isset($_POST['insurance_fee']) ? (float)$_POST['insurance_fee'] : 599.00;

    $basePrincipal = max(0, $productPrice - $downPayment);
    $loanAmount    = $basePrincipal > 0 ? ($basePrincipal + $processingFee + $insuranceFee) : 0;

    // Calculate EMI (Per Month Flat Interest)
    if ($interestRate > 0) {
        $totalInterest = round(($loanAmount * $interestRate * $tenure) / 100, 2);
    } else {
        $totalInterest = 0;
    }
    $totalPayable = round($loanAmount + $totalInterest, 2);
    $emi = $tenure > 0 ? round($totalPayable / $tenure, 2) : 0;
    $productName   = trim($_POST['product_name'] ?? '');
    $imeiNumber    = trim($_POST['imei_number'] ?? '');

    if (empty($productName) && $productId > 0) {
        $pStmt = db()->prepare('SELECT name FROM products WHERE id = ?');
        $pStmt->execute([$productId]);
        $productName = $pStmt->fetchColumn() ?: '';
    }

    $appNo = 'APP-' . rand(100000, 999999);
    $user = u();
    $shopId = (int)($user['shop_id'] ?? 0);
    if ($shopId <= 0 && !empty($user['id'])) {
        $uStmt = db()->prepare('SELECT shop_id FROM users WHERE id = ?');
        $uStmt->execute([(int)$user['id']]);
        $shopId = (int)($uStmt->fetchColumn() ?: 0);
    }
    if ($shopId <= 0 && $customerId > 0) {
        $cStmt2 = db()->prepare('SELECT shop_id FROM customers WHERE id = ?');
        $cStmt2->execute([$customerId]);
        $shopId = (int)($cStmt2->fetchColumn() ?: 0);
    }
    if ($shopId <= 0) {
        $shopId = 1;
    }

    $stmt = db()->prepare('INSERT INTO finance_applications (application_no, shop_id, customer_id, product_id, product_name, imei_number, product_price, down_payment, finance_amount, interest_rate, tenure, emi, total_interest, processing_fee, insurance_fee, total_payable, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", ?)');

    $stmt->execute([
        $appNo,
        $shopId,
        $customerId,
        $productId > 0 ? $productId : null,
        $productName ?: null,
        $imeiNumber ?: null,
        $productPrice,
        $downPayment,
        $loanAmount,
        $interestRate,
        $tenure,
        $emi,
        $totalInterest,
        $processingFee,
        $insuranceFee,
        $totalPayable,
        u()['id'] ?? null
    ]);

    $financeId = (int)db()->lastInsertId();

    // Generate EMI Amortization Schedule with 20th Day Cutoff Condition:
    // If loan is taken after 20th of the month -> 1st installment starts in 2 months (e.g. Jan 21+ -> March)
    // If loan is taken on or before 20th -> 1st installment starts in 1 month (e.g. Jan <=20 -> February)
    $schedules = generate_emi_schedule($financeId, $loanAmount, $totalInterest, $emi, $tenure);
    $firstDueDate = $schedules[0]['due_date'] ?? null;
    $firstDueFormatted = $firstDueDate ? date('d M Y', strtotime($firstDueDate)) : '';

    echo json_encode([
        'success' => true,
        'message' => 'Finance application created successfully! Status is Pending until 1st installment/mandate or manual payment.',
        'app_no' => $appNo,
        'finance_id' => $financeId,
        'product_name' => $productName,
        'imei_number' => $imeiNumber,
        'first_emi_date' => $firstDueFormatted
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Application creation failed: ' . $e->getMessage()
    ]);
}
