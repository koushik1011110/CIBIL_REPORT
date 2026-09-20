<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/onboarding_db_init.php';
require_once __DIR__ . '/../includes/document_db_init.php';

$p = db();

echo "Starting test loans seeding...\n";

// Ensure customers exist
$c1 = $p->query("SELECT id FROM customers WHERE id = 1")->fetchColumn();
$c2 = $p->query("SELECT id FROM customers WHERE id = 2")->fetchColumn();
if (!$c1 || !$c2) {
    echo "Customers 1 and 2 must exist.\n";
    exit;
}

// 1. Check or insert Loan 1: Active Loan (Partially Paid)
$stmt = $p->prepare("SELECT id FROM finance_applications WHERE application_no = 'APP-700101'");
$stmt->execute();
$l1 = $stmt->fetchColumn();

if (!$l1) {
    $p->prepare("INSERT INTO finance_applications (id, application_no, shop_id, customer_id, product_id, product_name, product_price, down_payment, finance_amount, interest_rate, tenure, emi, total_interest, processing_fee, total_payable, status, created_by, created_at)
        VALUES (101, 'APP-700101', 1, 1, 1, 'Demo Smartphone (Samsung 5G)', 49999.00, 9999.00, 40000.00, 12.00, 6, 6902.10, 1412.60, 600.00, 41412.60, 'approved', 1, '2026-05-10 10:30:00')
    ")->execute();
    $l1 = 101;

    // Generate 6 EMI schedules: 2 Paid, 4 Upcoming
    for ($i = 1; $i <= 6; $i++) {
        $dueDate = date('Y-m-d', strtotime("2026-05-10 +$i month"));
        $isPaid = ($i <= 2);
        $status = $isPaid ? 'paid' : 'upcoming';
        $paidAmt = $isPaid ? 6902.10 : 0.00;
        $paidAt = $isPaid ? date('Y-m-d 11:00:00', strtotime("2026-05-10 +$i month -2 days")) : null;

        $p->prepare("INSERT INTO emi_schedules (finance_id, installment_no, due_date, principal, interest, amount, paid_amount, status, paid_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$l1, $i, $dueDate, 6666.67, 235.43, 6902.10, $paidAmt, $status, $paidAt]);
        $emiId = $p->lastInsertId();

        if ($isPaid) {
            $p->prepare("INSERT INTO payments (finance_id, emi_id, customer_id, amount, payment_method, reference_no, remarks, paid_at, recorded_by)
                VALUES (?, ?, 1, 6902.10, 'UPI AutoPay', ?, ?, ?, 1)
            ")->execute([$l1, $emiId, 'UPI' . time() . $i, "Monthly EMI Installment #$i Repayment", $paidAt]);
        }
    }

    // Down payment
    $p->prepare("INSERT INTO payments (finance_id, emi_id, customer_id, amount, payment_method, reference_no, remarks, paid_at, recorded_by)
        VALUES (?, NULL, 1, 9999.00, 'Cash', ?, 'Down Payment Cleared at Retail Counter', '2026-05-10 10:45:00', 2)
    ")->execute([$l1, 'DP700101']);

    // Onboarding KYC
    $p->prepare("INSERT INTO finance_application_onboarding (finance_id, full_name, father_name, dob, gender, mobile, alternate_mobile, email, address, city, state, pincode, aadhaar_no, aadhaar_verified, qualification, occupation, monthly_income, witness_name, witness_mobile, bank_name, account_holder, account_no, ifsc_code, account_type, mandate_mode, mandate_status, onboarding_status, completed_at)
        VALUES (?, 'Demo Customer', 'Shri Ramesh Sharma', '1998-05-10', 'male', '9876543210', '9876543211', 'customer@example.com', 'Barpeta Road, Ward No 4', 'Barpeta Road', 'Assam', '781315', '548912348765', 1, 'Graduate', 'Senior Sales Executive', 35000.00, 'Bikash Roy', '9101234567', 'State Bank of India', 'Demo Customer', '38901245678', 'SBIN0002042', 'savings', 'enach', 'active', 'completed', '2026-05-10 10:35:00')
        ON DUPLICATE KEY UPDATE full_name=VALUES(full_name)
    ")->execute([$l1]);

    echo "Created Loan 1 (Active, 2 EMIs paid, 4 pending): ID $l1\n";
} else {
    echo "Loan 1 already exists: ID $l1\n";
}

// 2. Check or insert Loan 2: Completed Loan (100% Repaid - NOC & Closure Unlocked)
$stmt = $p->prepare("SELECT id FROM finance_applications WHERE application_no = 'APP-700202'");
$stmt->execute();
$l2 = $stmt->fetchColumn();

if (!$l2) {
    $p->prepare("INSERT INTO finance_applications (id, application_no, shop_id, customer_id, product_id, product_name, product_price, down_payment, finance_amount, interest_rate, tenure, emi, total_interest, processing_fee, total_payable, status, created_by, created_at)
        VALUES (102, 'APP-700202', 1, 2, 3, 'Mobile Phone Standard', 6000.00, 1000.00, 5000.00, 10.00, 3, 1694.44, 83.33, 100.00, 5083.33, 'completed', 1, '2026-01-15 14:00:00')
    ")->execute();
    $l2 = 102;

    // Generate 3 EMI schedules: ALL 3 Paid (100% cleared!)
    for ($i = 1; $i <= 3; $i++) {
        $dueDate = date('Y-m-d', strtotime("2026-01-15 +$i month"));
        $paidAt = date('Y-m-d 12:00:00', strtotime("2026-01-15 +$i month -1 days"));

        $p->prepare("INSERT INTO emi_schedules (finance_id, installment_no, due_date, principal, interest, amount, paid_amount, status, paid_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', ?)
        ")->execute([$l2, $i, $dueDate, 1666.67, 27.77, 1694.44, 1694.44, $paidAt]);
        $emiId = $p->lastInsertId();

        $p->prepare("INSERT INTO payments (finance_id, emi_id, customer_id, amount, payment_method, reference_no, remarks, paid_at, recorded_by)
            VALUES (?, ?, 2, 1694.44, 'NetBanking', ?, ?, ?, 1)
        ")->execute([$l2, $emiId, 'NET' . time() . $i, "Final EMI Installment #$i Repayment", $paidAt]);
    }

    // Down payment
    $p->prepare("INSERT INTO payments (finance_id, emi_id, customer_id, amount, payment_method, reference_no, remarks, paid_at, recorded_by)
        VALUES (?, NULL, 2, 1000.00, 'UPI', ?, 'Down Payment Cleared', '2026-01-15 14:10:00', 1)
    ")->execute([$l2, 'DP700202']);

    // Onboarding KYC
    $p->prepare("INSERT INTO finance_application_onboarding (finance_id, full_name, father_name, dob, gender, mobile, alternate_mobile, email, address, city, state, pincode, aadhaar_no, aadhaar_verified, qualification, occupation, monthly_income, witness_name, witness_mobile, bank_name, account_holder, account_no, ifsc_code, account_type, mandate_mode, mandate_status, onboarding_status, completed_at)
        VALUES (?, 'KOUSHIK DEKA', 'Prabin Deka', '1999-11-22', 'male', '09401633995', '9401633995', 'koushik@kkwebmart.com', 'New Manas Road, Ward 5', 'Barpeta Road', 'Assam', '781315', '987612344321', 1, 'B.Tech IT', 'Software Engineer', 65000.00, 'Hamida Khatun', '9101259396', 'HDFC Bank', 'KOUSHIK DEKA', '5010023456789', 'HDFC0001234', 'savings', 'upi_autopay', 'completed', 'completed', '2026-01-15 14:05:00')
        ON DUPLICATE KEY UPDATE full_name=VALUES(full_name)
    ")->execute([$l2]);

    echo "Created Loan 2 (Completed, 100% Repaid, NOC ready): ID $l2\n";
} else {
    echo "Loan 2 already exists: ID $l2\n";
}

echo "Seeding completed successfully!\n";
