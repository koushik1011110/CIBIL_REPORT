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
    $interestRate = (float)($_POST['interest_rate'] ?? 12.0);

    if ($customerId <= 0 || $productPrice <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid parameters provided for finance application.']);
        exit;
    }

    $loanAmount = max(0, $productPrice - $downPayment);

    // Calculate EMI
    $monthlyRate = ($interestRate / 12) / 100;
    if ($monthlyRate > 0) {
        $emi = ($loanAmount * $monthlyRate * pow(1 + $monthlyRate, $tenure)) / (pow(1 + $monthlyRate, $tenure) - 1);
    } else {
        $emi = $loanAmount / $tenure;
    }
    $emi = round($emi, 2);
    $totalPayable = round($emi * $tenure, 2);
    $totalInterest = max(0, round($totalPayable - $loanAmount, 2));
    $processingFee = round($loanAmount * 0.015, 2);

    $appNo = 'APP-' . rand(100000, 999999);
    $shopId = (int)(u()['shop_id'] ?? 1) ?: 1;

    $stmt = db()->prepare('INSERT INTO finance_applications (application_no, shop_id, customer_id, product_id, product_price, down_payment, finance_amount, interest_rate, tenure, emi, total_interest, processing_fee, total_payable, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending", ?)');

    $stmt->execute([
        $appNo,
        $shopId,
        $customerId,
        $productId > 0 ? $productId : null,
        $productPrice,
        $downPayment,
        $loanAmount,
        $interestRate,
        $tenure,
        $emi,
        $totalInterest,
        $processingFee,
        $totalPayable,
        u()['id'] ?? null
    ]);

    $financeId = (int)db()->lastInsertId();

    // Generate EMI Amortization Schedule
    $today = new DateTime();
    for ($i = 1; $i <= $tenure; $i++) {
        $dueDate = clone $today;
        $dueDate->modify("+$i month");

        $s = db()->prepare('INSERT INTO emi_schedules (finance_id, installment_no, due_date, principal, interest, amount, status) VALUES (?, ?, ?, ?, ?, ?, "upcoming")');
        $s->execute([
            $financeId,
            $i,
            $dueDate->format('Y-m-d'),
            round($loanAmount / $tenure, 2),
            round($totalInterest / $tenure, 2),
            $emi
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Finance application created successfully! Status is Pending until 1st installment/mandate or manual payment.',
        'app_no' => $appNo,
        'finance_id' => $financeId
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Application creation failed: ' . $e->getMessage()
    ]);
}
