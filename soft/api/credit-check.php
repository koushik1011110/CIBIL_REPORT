<?php
require_once __DIR__.'/../includes/auth.php';
role('superadmin','shop_admin','staff');
header('Content-Type: application/json');

define('FINPAY_API_KEY', '8d8fd1-efeaa9-928494-24a4fd-0c7dd1');

$reportType = isset($_POST['report_type']) ? trim($_POST['report_type']) : 'equifax_json';

if ($reportType === 'experian_pdf') {
    $apiUrl = 'https://api.finpayultra.com/api/credit-report-experian-pdf.php';
    $provider = 'Experian PDF';
    $price = 60.00;
} else {
    $apiUrl = 'https://api.finpayultra.com/api/credit-report.php';
    $provider = 'Equifax / CIBIL Detailed';
    $price = 70.00;
}

$user = u();
$userId = (int)($user['id'] ?? 0);
$shopId = (int)($user['shop_id'] ?? 0);

if ($userId > 0 && $shopId <= 0) {
    $uStmt = db()->prepare('SELECT shop_id FROM users WHERE id = ?');
    $uStmt->execute([$userId]);
    $shopId = (int)($uStmt->fetchColumn() ?: 0);
}

// Check Shop / User Wallet Balance Before Hitting Bureau API
$currentBal = 0.00;
if ($shopId > 0) {
    $balStmt = db()->prepare('SELECT wallet_balance FROM shops WHERE id = ?');
    $balStmt->execute([$shopId]);
    $currentBal = floatval($balStmt->fetchColumn() ?: 0);
} else if ($userId > 0) {
    $balStmt = db()->prepare('SELECT wallet_balance FROM users WHERE id = ?');
    $balStmt->execute([$userId]);
    $currentBal = floatval($balStmt->fetchColumn() ?: 0);
}

if ($currentBal < $price) {
    echo json_encode([
        'success' => false,
        'message' => 'Insufficient Shop Wallet Balance! Required: ₹' . number_format($price, 2) . ', Available: ₹' . number_format($currentBal, 2) . '. Please topup shop wallet via PayU.'
    ]);
    exit;
}

$customerId = (int)($_POST['customer_id'] ?? 0);

if ($customerId > 0) {
    $stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();
    if (!$customer) {
        echo json_encode(['success' => false, 'message' => 'Selected customer not found in database.']);
        exit;
    }
    $name    = trim($customer['name']);
    $mobile  = trim($customer['mobile']);
    $number  = trim($customer['pan']);
    $fetchBy = 'pan';
} else {
    $name    = isset($_POST['name']) ? trim($_POST['name']) : '';
    $mobile  = isset($_POST['mobile']) ? trim($_POST['mobile']) : '';
    $number  = isset($_POST['number']) ? trim($_POST['number']) : (isset($_POST['pan']) ? trim($_POST['pan']) : '');
    $fetchBy = isset($_POST['fetch_by']) ? trim($_POST['fetch_by']) : 'pan';
    $customer = ['id' => null, 'name' => $name, 'mobile' => $mobile, 'pan' => $number];
}

if (empty($name) || empty($mobile) || empty($number)) {
    echo json_encode(['success' => false, 'message' => 'Required parameters missing: name, mobile, number/pan.']);
    exit;
}

$orderId = isset($_POST['orderid']) && !empty($_POST['orderid']) ? trim($_POST['orderid']) : 'TXN' . time() . rand(1000, 9999);

$postFields = [
    'api_key'  => FINPAY_API_KEY,
    'orderid'  => $orderId,
    'name'     => $name,
    'pan'      => $number,
    'number'   => $number,
    'fetch_by' => $fetchBy,
    'mobile'   => $mobile,
    'consent'  => 'Y'
];

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/x-www-form-urlencoded'
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$response = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo json_encode(['success' => false, 'message' => 'cURL Error: ' . $curlError]);
    exit;
}

$responseData = json_decode($response, true);

$isSuccess = false;
$apiMsg = 'Unable to fetch credit report from bureau.';

if ($responseData) {
    if (isset($responseData['status_code']) && ($responseData['status_code'] == '200' || $responseData['status_code'] == 200)) {
        $isSuccess = true;
    } elseif (isset($responseData['status']) && (strtolower((string)$responseData['status']) === 'success' || $responseData['status'] === true)) {
        $isSuccess = true;
    } elseif (isset($responseData['response_code']) && $responseData['response_code'] == 200) {
        $isSuccess = true;
    } elseif (isset($responseData['credit_score']) || isset($responseData['data']['credit_score']) || isset($responseData['score'])) {
        $isSuccess = true;
    }

    if (isset($responseData['message']) && !empty($responseData['message'])) {
        $apiMsg = $responseData['message'];
    }
}

if (!$isSuccess) {
    echo json_encode([
        'success' => false,
        'message' => 'Credit Report Bureau API Error: ' . $apiMsg,
        'orderid' => $orderId,
        'raw_response' => $responseData ?: $response
    ]);
    exit;
}

// Successful Bureau Response -> Deduct Price (₹70 or ₹60) from Wallet
if ($userId > 0 || $shopId > 0) {
    try {
        if ($shopId > 0) {
            db()->prepare('UPDATE shops SET wallet_balance = GREATEST(0, wallet_balance - ?) WHERE id = ?')->execute([$price, $shopId]);
        }

        if ($userId > 0) {
            db()->prepare('UPDATE users SET wallet_balance = GREATEST(0, wallet_balance - ?) WHERE id = ?')->execute([$price, $userId]);
        }

        // Record Debit Transaction
        $txnidDebit = 'CHK' . time() . rand(100, 999);
        $remarks = 'Credit Check Fee (' . $provider . ' - ₹' . number_format($price, 2) . ')';
        $dbTx = db()->prepare("INSERT INTO wallet_transactions (user_id, shop_id, txnid, amount, type, status, payment_gateway, remarks) VALUES (?, ?, ?, ?, 'debit', 'success', 'Wallet', ?)");
        $dbTx->execute([$userId, ($shopId > 0 ? $shopId : null), $txnidDebit, $price, $remarks]);
    } catch (Exception $e) {
        error_log('Wallet deduction error: ' . $e->getMessage());
    }
}

$creditScore = null;
if (isset($responseData['credit_score'])) {
    $creditScore = intval($responseData['credit_score']);
} elseif (isset($responseData['data']['credit_score'])) {
    $creditScore = intval($responseData['data']['credit_score']);
} elseif (isset($responseData['score'])) {
    $creditScore = intval($responseData['score']);
}

$pdfDownloadUrl = null;
if (!empty($responseData['pdf_url'])) {
    $pdfDownloadUrl = $responseData['pdf_url'];
} elseif (!empty($responseData['data']['pdf_url'])) {
    $pdfDownloadUrl = $responseData['data']['pdf_url'];
} elseif (!empty($responseData['report_url'])) {
    $pdfDownloadUrl = $responseData['report_url'];
} elseif (!empty($responseData['data']['report_url'])) {
    $pdfDownloadUrl = $responseData['data']['report_url'];
} elseif (!empty($responseData['download_url'])) {
    $pdfDownloadUrl = $responseData['download_url'];
}

$jsonStore = json_encode($responseData);

if ($customerId > 0) {
    try {
        db()->prepare('UPDATE customers SET credit_score = ?, credit_report_json = ? WHERE id = ?')->execute([$creditScore, $jsonStore, $customerId]);
        
        $q = db()->prepare('INSERT INTO credit_checks (customer_id, provider, reference_no, score, request_json, response_json, consent, checked_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
        $q->execute([$customerId, $provider, $orderId, $creditScore, json_encode($postFields), $jsonStore, $userId]);
    } catch (Exception $e) {
    }
}

echo json_encode([
    'success' => true,
    'status' => 'SUCCESS',
    'provider' => $provider,
    'report_type' => $reportType,
    'price_deducted' => $price,
    'score' => $creditScore,
    'credit_score' => $creditScore,
    'customer' => $customer,
    'report' => $responseData['data']['credit_report'] ?? $responseData['credit_report'] ?? $responseData,
    'data' => $responseData['data'] ?? $responseData,
    'overall_json' => $responseData,
    'orderid' => $orderId,
    'pdf_url' => $pdfDownloadUrl,
    'raw_response' => $jsonStore
]);
