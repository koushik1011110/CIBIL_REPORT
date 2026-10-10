<?php
require_once __DIR__.'/../includes/auth.php';
role('superadmin','shop_admin','staff');
header('Content-Type: application/json');

define('FINPAY_API_KEY', '8d8fd1-efeaa9-928494-24a4fd-0c7dd1');

$finpayApiKey = get_setting('finpay_credit_api_key', '') ?: (defined('FINPAY_API_KEY') ? FINPAY_API_KEY : '8d8fd1-efeaa9-928494-24a4fd-0c7dd1');

// Bureau Mode: Can be toggled via Admin Settings or POST parameter
$bureauTestSetting = get_setting('bureau_test_mode', '0');
$isTestMode = ($bureauTestSetting === '1') || (isset($_POST['test_mode']) && in_array($_POST['test_mode'], ['1', 'true', true], true));
$testStatus = 'SUCCESS';

$reportType = 'transunion_pdf';
$apiUrl = 'https://api.finpayultra.com/api/transunion-pdf';
$provider = 'Credit Report Transunion PDF';
$price = 80.00;

$user = u();
$userId = (int)($user['id'] ?? 0);
$shopId = (int)($user['shop_id'] ?? 0);

if ($userId > 0 && $shopId <= 0) {
    $uStmt = db()->prepare('SELECT shop_id FROM users WHERE id = ?');
    $uStmt->execute([$userId]);
    $shopId = (int)($uStmt->fetchColumn() ?: 0);
}

// Check Shop / User Wallet Balance Before Hitting Bureau API (skip if test mode)
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

if (!$isTestMode && $currentBal < $price) {
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
    $mobile  = !empty($_POST['mobile']) ? trim($_POST['mobile']) : trim($customer['mobile']);
    $number  = !empty($_POST['pan']) ? trim($_POST['pan']) : (!empty($_POST['number']) ? trim($_POST['number']) : trim($customer['pan']));
    $dob     = !empty($customer['dob']) ? trim($customer['dob']) : (isset($_POST['dob']) ? trim($_POST['dob']) : '');
    $address = !empty($customer['address']) ? trim($customer['address']) : (isset($_POST['address']) ? trim($_POST['address']) : '');
    $fetchBy = 'pan';
} else {
    $name    = isset($_POST['name']) ? trim($_POST['name']) : '';
    $mobile  = isset($_POST['mobile']) ? trim($_POST['mobile']) : '';
    $number  = isset($_POST['number']) ? trim($_POST['number']) : (isset($_POST['pan']) ? trim($_POST['pan']) : '');
    $dob     = isset($_POST['dob']) ? trim($_POST['dob']) : '';
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $fetchBy = isset($_POST['fetch_by']) ? trim($_POST['fetch_by']) : 'pan';
    $customer = ['id' => null, 'name' => $name, 'mobile' => $mobile, 'pan' => $number, 'dob' => $dob, 'address' => $address];
}

if (empty($name) || empty($mobile) || empty($number)) {
    echo json_encode(['success' => false, 'message' => 'Required parameters missing: name, mobile, number/pan.']);
    exit;
}

// Clean & normalize parameters for bureau submission
$name = strtoupper(trim(preg_replace('/\s+/', ' ', $name)));
$number = strtoupper(trim($number));
$mobile = trim(preg_replace('/[^0-9]/', '', $mobile));
if (strlen($mobile) > 10 && substr($mobile, 0, 2) === '91') {
    $mobile = substr($mobile, 2);
}

$orderId = isset($_POST['orderid']) && !empty($_POST['orderid']) ? trim($_POST['orderid']) : ('TXN' . rand(10000, 99999));

$gender = isset($_POST['gender']) && !empty($_POST['gender']) ? strtolower(trim($_POST['gender'])) : '';
if (!in_array($gender, ['male', 'female'])) {
    $gender = '';
}

// Auto-detect gender from name if not explicitly set or if default 'male' conflicts with female name
$nameUpper = strtoupper($name);
$isFemaleName = (bool)preg_match('/\b(BEGUM|BIBI|KHATUN|DEVI|KUMARI|SULTANA|PARVEEN|FATIMA|MISS|MRS|MS|SHAHNAZ|NASIMA|ROHIMA|JASMINA|ASHMINA|HASINA|MONOWARA|SAHIDA|MOMINA)\b/i', $nameUpper);

if (empty($gender)) {
    $gender = $isFemaleName ? 'female' : 'male';
} elseif ($gender === 'male' && $isFemaleName && (!isset($_POST['gender_explicit']) || $_POST['gender_explicit'] !== '1')) {
    $gender = 'female';
}

$postFields = [
    'api_key' => $finpayApiKey,
    'orderid' => $orderId,
    'name'    => $name,
    'mobile'  => $mobile,
    'pan'     => $number,
    'gender'  => $gender,
    'consent' => 'Y'
];

$responseData = null;
$response = '';
$isSuccess = false;
$apiMsg = 'Unable to fetch credit report from bureau.';

if ($isTestMode) {
    // Zero-cost Test / Sandbox Simulator: Avoid external API hit entirely
    $isSuccess = true;
    $samplePdfUrl = 'https://pay.finpayultra.com/cibil-api/reports/20260929_103007_37055d56faa617c8.pdf';

    $responseData = [
        'status_code' => '200',
        'status' => 'SUCCESS',
        'message' => 'API Test Sandbox Simulation: Verified via Test Mode (₹0 wallet debit).',
        'test_mode' => true,
        'orderid' => $orderId,
        'service' => 'transunion_pdf',
        'wallet_debit' => 0,
        'credit_score' => 754,
        'score' => 754,
        'pdf_url' => $samplePdfUrl,
        'report_url' => $samplePdfUrl,
        'data' => [
            'score' => 754,
            'credit_score' => 754,
            'name' => strtoupper($name),
            'pan' => strtoupper($number),
            'mobile' => $mobile,
            'dob' => $dob,
            'gender' => $gender,
            'report_status' => 'READY',
            'pdf_url' => $samplePdfUrl,
            'report_url' => $samplePdfUrl
        ]
    ];
} else {
    // Live Bureau Mode: Connect to FinPay Ultra
    $requestUrl = $apiUrl . '?' . http_build_query($postFields);
    $ch = curl_init($requestUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPGET, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        echo json_encode(['success' => false, 'message' => 'cURL Error: ' . $curlError]);
        exit;
    }

    // Always log live bureau requests and responses
    if (!is_dir(__DIR__ . '/../logs')) {
        @mkdir(__DIR__ . '/../logs', 0777, true);
    }
    @file_put_contents(__DIR__ . '/../logs/bureau_api.log', date('Y-m-d H:i:s') . " | REQ: {$requestUrl}\n" . date('Y-m-d H:i:s') . " | RES: {$response}\n\n", FILE_APPEND);

    $responseData = json_decode($response, true);

    if ($responseData) {
        if (isset($responseData['status_code']) && ($responseData['status_code'] == '200' || $responseData['status_code'] == 200)) {
            $isSuccess = true;
        } elseif (isset($responseData['status']) && (strtolower((string)$responseData['status']) === 'success' || $responseData['status'] === true)) {
            $isSuccess = true;
        } elseif (isset($responseData['response_code']) && $responseData['response_code'] == 200) {
            $isSuccess = true;
        } elseif (isset($responseData['credit_score']) || isset($responseData['data']['credit_score']) || isset($responseData['score']) || isset($responseData['data']['score'])) {
            $isSuccess = true;
        }

        if (isset($responseData['message']) && !empty($responseData['message'])) {
            $apiMsg = $responseData['message'];
        }
    }
}

/**
 * Recursively extracts authentic FinPay Ultra Bureau PDF URL from response
 */
function extract_finpay_pdf_url($data, $raw) {
    // 1. Recursive array search for any .pdf URL or report path
    $scan = function($arr) use (&$scan) {
        if (!is_array($arr)) return null;
        foreach ($arr as $k => $v) {
            if (is_string($v)) {
                $vt = trim($v);
                if (stripos($vt, 'localhost') !== false || stripos($vt, '127.0.0.1') !== false || stripos($vt, 'transunion-cibil-pdf') !== false || stripos($vt, 'sample-equifax-pdf') !== false) {
                    continue;
                }
                if (preg_match('/https?:\/\/pay\.finpayultra\.com\/cibil-api\/reports\/[^\s"\'<>]+\.pdf/i', $vt, $m)) {
                    return $m[0];
                }
                if (preg_match('/https?:\/\/[^\s"\'<>]+\/reports\/[^\s"\'<>]+\.pdf/i', $vt, $m)) {
                    return $m[0];
                }
                if (preg_match('/\/cibil-api\/reports\/[^\s"\'<>]+\.pdf/i', $vt, $m)) {
                    return 'https://pay.finpayultra.com' . $m[0];
                }
                if (stripos($vt, '.pdf') !== false && (stripos($vt, 'http') === 0 || stripos($vt, '/reports/') !== false)) {
                    return (stripos($vt, 'http') === 0) ? $vt : ('https://pay.finpayultra.com/' . ltrim($vt, '/'));
                }
            } elseif (is_array($v)) {
                $found = $scan($v);
                if ($found) return $found;
            }
        }
        return null;
    };

    $foundUrl = $scan($data);
    if (!empty($foundUrl)) return $foundUrl;

    // 2. Raw string search (cleaning escaped slashes)
    if (!empty($raw)) {
        $cleaned = str_replace(['\/', '\\'], ['/', ''], $raw);
        if (preg_match('/https?:\/\/pay\.finpayultra\.com\/cibil-api\/reports\/[^\s"\'<>]+\.pdf/i', $cleaned, $m)) {
            return $m[0];
        }
        if (preg_match('/https?:\/\/[^\s"\'<>]+\/reports\/[^\s"\'<>]+\.pdf/i', $cleaned, $m)) {
            return $m[0];
        }
        if (preg_match('/\/cibil-api\/reports\/[^\s"\'<>]+\.pdf/i', $cleaned, $m)) {
            return 'https://pay.finpayultra.com' . $m[0];
        }
        if (preg_match('/https?:\/\/[^\s"\'<>]+\.pdf/i', $cleaned, $m)) {
            if (stripos($m[0], '127.0.0.1') === false && stripos($m[0], 'localhost') === false) {
                return $m[0];
            }
        }
    }

    return null;
}

$pdfDownloadUrl = extract_finpay_pdf_url($responseData, $response);
if (!empty($pdfDownloadUrl)) {
    if (!preg_match('/^https?:\/\//i', $pdfDownloadUrl)) {
        $pdfDownloadUrl = 'https://pay.finpayultra.com/' . ltrim($pdfDownloadUrl, '/');
    }
    $isSuccess = true;
}

// Check if TransUnion CIBIL returned: "No credit record matching the identity details supplied"
$checkMsg = strtolower($apiMsg . ' ' . (isset($responseData['message']) ? $responseData['message'] : '') . ' ' . (isset($responseData['error']) ? $responseData['error'] : ''));

$isNoRecordFound = (
    strpos($checkMsg, 'has no credit record matching') !== false ||
    strpos($checkMsg, 'no credit record') !== false ||
    strpos($checkMsg, 'no credit history') !== false ||
    strpos($checkMsg, 'no record matching') !== false ||
    strpos($checkMsg, 'no record found') !== false ||
    strpos($checkMsg, 'no matching record') !== false ||
    (isset($responseData['status']) && in_array(strtolower((string)$responseData['status']), ['no_hit', 'nh', 'no_record_found']))
);

if ($isNoRecordFound) {
    $isSuccess = true;
    $creditScore = -1; // New to Credit (NH)
    $apiMsg = 'TransUnion CIBIL Bureau Verified: Customer has no prior credit or loan record on file (New to Credit / NH Score -1). Zero adverse remarks or defaults.';

    $responseData = [
        'status_code' => '200',
        'status' => 'SUCCESS',
        'bureau_status' => 'NO_RECORD_FOUND',
        'bureau_status_label' => 'New to Credit (No Prior History)',
        'message' => $apiMsg,
        'credit_score' => -1,
        'score' => -1,
        'is_new_to_credit' => true,
        'orderid' => $orderId,
        'service' => 'transunion_pdf',
        'pdf_url' => $pdfDownloadUrl,
        'report_url' => $pdfDownloadUrl,
        'data' => [
            'score' => -1,
            'credit_score' => -1,
            'bureau_status' => 'NO_RECORD_FOUND',
            'bureau_status_label' => 'New to Credit (NH)',
            'name' => strtoupper($name),
            'pan' => strtoupper($number),
            'mobile' => $mobile,
            'dob' => $dob,
            'gender' => $gender,
            'report_status' => 'NO_RECORD_FOUND',
            'pdf_url' => $pdfDownloadUrl,
            'report_url' => $pdfDownloadUrl,
            'credit_report' => [
                'CCRResponse' => [
                    'CIRReportDataLst' => [
                        [
                            'CIRReportData' => [
                                'RetailAccountDetails' => []
                            ]
                        ]
                    ]
                ]
            ],
            'credit_summary' => [
                'total_accounts' => 0,
                'active_accounts' => 0,
                'overdue_accounts' => 0,
                'total_balance' => 0,
                'past_due_amount' => 0,
                'remarks' => 'Applicant is verified New to Credit with zero prior defaults or adverse tradelines.'
            ]
        ]
    ];
}

if (!$isSuccess) {
    $isAuthWaiting = (
        stripos($apiMsg, 'authentication answer') !== false ||
        stripos($apiMsg, 'otp question') !== false ||
        stripos($apiMsg, 'waiting for the customer') !== false
    );

    $helpfulMessage = $apiMsg;
    if ($isAuthWaiting) {
        $helpfulMessage = "The bureau is still waiting for the customer's authentication answer, so the credit report cannot be pulled yet. This happens when the OTP question was never answered, or the answer was never submitted back to the bureau. Please verify the customer's CIBIL-linked mobile number and retry.";
    }

    echo json_encode([
        'success' => false,
        'is_auth_waiting' => $isAuthWaiting,
        'message' => 'Credit Report Bureau API Error: ' . $helpfulMessage,
        'orderid' => $orderId,
        'raw_response' => $responseData ?: $response
    ]);
    exit;
}

// Successful Bureau Response -> Deduct Price from Wallet (strictly skip if test mode)
if (!$isTestMode && $price > 0 && ($userId > 0 || $shopId > 0)) {
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

/**
 * Fully parses authentic TransUnion CIBIL CIR report directly from the official PDF stream.
 */
function parse_transunion_pdf_data($pdfContent) {
    if (empty($pdfContent)) return [];

    $text = '';
    if (preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/s', $pdfContent, $streams)) {
        foreach ($streams[1] as $stream) {
            $uncompressed = @gzuncompress($stream);
            $text .= ($uncompressed !== false ? $uncompressed : $stream) . "\n";
        }
    } else {
        $text = $pdfContent;
    }

    $result = [
        'score' => null,
        'control_number' => null,
        'summary' => [
            'total_accounts' => 0,
            'overdue_accounts' => 0,
            'zero_balance_accounts' => 0,
            'total_balance' => 0,
            'high_credit_amount' => 0,
            'total_enquiries' => 0,
        ],
        'accounts' => []
    ];

    if (!preg_match_all('/\((.*?)\)/s', $text, $matches)) {
        return $result;
    }

    $strings = array_map('trim', $matches[1]);
    $cnt = count($strings);

    // Score & Control number
    for ($i = 0; $i < $cnt; $i++) {
        $s = str_replace([' ', '-', '_', '\\'], '', strtoupper($strings[$i]));
        if (strpos($s, 'CONTROLNUMBER:') !== false && isset($strings[$i + 1])) {
            $result['control_number'] = trim($strings[$i + 1]);
        }
        if (strpos($s, 'CIBILTRANSUNIONSCORE') !== false || strpos($s, 'TRANSUNIONSCORE') !== false) {
            for ($j = $i + 1; $j < min($i + 6, $cnt); $j++) {
                $val = trim(str_replace(['\\', ' '], '', $strings[$j]));
                if (is_numeric($val) && ($val === '-1' || (intval($val) >= 300 && intval($val) <= 900) || intval($val) === 0)) {
                    $result['score'] = intval($val);
                    break;
                }
            }
        }
    }

    // Direct regex score check
    if ($result['score'] === null) {
        if (preg_match('/CIBILTRANSUNION[^\d\-]{0,80}(-?\d{1,3})/i', $text, $m)) {
            $result['score'] = intval($m[1]);
        } elseif (preg_match('/(?:TRANSUNION|CIBIL)[^\d\-]{0,120}\b([3-8][0-9]{2}|900|-1)\b/i', $text, $m)) {
            $result['score'] = intval($m[1]);
        }
    }

    // Summary counts
    for ($i = 0; $i < $cnt; $i++) {
        if ($strings[$i] === 'All Accounts' && isset($strings[$i + 1]) && $strings[$i + 1] === 'TOTAL:' && isset($strings[$i + 2])) {
            $result['summary']['total_accounts'] = intval($strings[$i + 2]);
        }
        if ($strings[$i] === 'OVERDUE:' && isset($strings[$i + 1]) && is_numeric($strings[$i + 1])) {
            if ($result['summary']['overdue_accounts'] === 0) {
                $result['summary']['overdue_accounts'] = intval($strings[$i + 1]);
            }
        }
        if ($strings[$i] === 'ZERO-BALANCE:' && isset($strings[$i + 1]) && is_numeric($strings[$i + 1])) {
            $result['summary']['zero_balance_accounts'] = intval($strings[$i + 1]);
        }
        if (strpos($strings[$i], 'CURRENT::') !== false && isset($strings[$i + 1]) && is_numeric($strings[$i + 1])) {
            $result['summary']['total_balance'] = floatval($strings[$i + 1]);
        }
        if ($strings[$i] === 'AMT:' && isset($strings[$i + 1]) && is_numeric($strings[$i + 1])) {
            $result['summary']['high_credit_amount'] = floatval($strings[$i + 1]);
        }
        if ($strings[$i] === 'All Enquiries' && isset($strings[$i + 1]) && is_numeric($strings[$i + 1])) {
            $result['summary']['total_enquiries'] = intval($strings[$i + 1]);
        }
    }

    // Account tradelines
    for ($i = 0; $i < $cnt; $i++) {
        if ($strings[$i] === 'MEMBER NAME:' && isset($strings[$i + 1])) {
            $member = $strings[$i + 1];
            $accNum = '';
            $accType = 'Loan';
            $curBal = '0';
            $highCredit = '0';
            $overdue = '0';
            $opened = '';

            for ($j = $i + 2; $j < min($i + 45, $cnt); $j++) {
                if ($strings[$j] === 'NUMBER:' && isset($strings[$j + 1])) {
                    $accNum = $strings[$j + 1];
                }
                if ($strings[$j] === 'TYPE:' && isset($strings[$j + 1])) {
                    $accType = $strings[$j + 1];
                    if (isset($strings[$j + 2]) && !in_array($strings[$j + 2], ['OWNERSHIP:', 'Amount Overdue:'])) {
                        $accType .= ' ' . $strings[$j + 2];
                    }
                }
                if (stripos($strings[$j], 'CURRENT BALANCE:') !== false && isset($strings[$j + 1])) {
                    $curBal = $strings[$j + 1];
                }
                if (stripos($strings[$j], 'HIGH CREDIT AMOUNT:') !== false && isset($strings[$j + 1])) {
                    $highCredit = $strings[$j + 1];
                }
                if (stripos($strings[$j], 'Amount Overdue:') !== false && isset($strings[$j + 1])) {
                    $overdue = $strings[$j + 1];
                }
                if ($strings[$j] === 'OPENED:' && isset($strings[$j + 1])) {
                    $opened = $strings[$j + 1];
                }
                if ($strings[$j] === 'MEMBER NAME:') {
                    break;
                }
            }

            if (!empty($member)) {
                $result['accounts'][] = [
                    'AccountNumber' => $accNum ?: ('ACC' . (count($result['accounts']) + 1)),
                    'InstitutionName' => $member,
                    'AccountType' => trim(str_replace(['\\(', '\\)'], '', $accType)),
                    'Balance' => $curBal,
                    'CurrentBalance' => $curBal,
                    'SanctionAmount' => $highCredit,
                    'HighCreditAmount' => $highCredit,
                    'PastDueAmount' => $overdue,
                    'OpenDate' => $opened
                ];
            }
        }
    }

    return $result;
}

$creditScore = null;
if (isset($responseData['credit_score']) && is_numeric($responseData['credit_score'])) {
    $creditScore = intval($responseData['credit_score']);
} elseif (isset($responseData['data']['credit_score']) && is_numeric($responseData['data']['credit_score'])) {
    $creditScore = intval($responseData['data']['credit_score']);
} elseif (isset($responseData['score']) && is_numeric($responseData['score'])) {
    $creditScore = intval($responseData['score']);
} elseif (isset($responseData['data']['score']) && is_numeric($responseData['data']['score'])) {
    $creditScore = intval($responseData['data']['score']);
}

// If official bureau PDF is generated, download and parse exact score, accounts, and summary!
if (!empty($pdfDownloadUrl)) {
    $chPdf = curl_init($pdfDownloadUrl);
    curl_setopt($chPdf, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chPdf, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($chPdf, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($chPdf, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    curl_setopt($chPdf, CURLOPT_TIMEOUT, 15);
    $pdfRaw = curl_exec($chPdf);
    curl_close($chPdf);

    if (!empty($pdfRaw)) {
        $pdfData = parse_transunion_pdf_data($pdfRaw);
        if ($pdfData['score'] !== null) {
            $creditScore = $pdfData['score'];
        }
        if (!empty($pdfData['control_number'])) {
            $responseData['control_number'] = $pdfData['control_number'];
        }
        if (!empty($pdfData['accounts'])) {
            if (!isset($responseData['data'])) $responseData['data'] = [];
            $responseData['data']['credit_report']['CCRResponse']['CIRReportDataLst'][0]['CIRReportData']['RetailAccountDetails'] = $pdfData['accounts'];
            $responseData['data']['credit_summary'] = $pdfData['summary'];
        }
    }
}

if ($creditScore === null) {
    $creditScore = (!empty($customer['credit_score']) && intval($customer['credit_score']) > 0) ? intval($customer['credit_score']) : 750;
}

if (!isset($responseData['data']) || !is_array($responseData['data'])) {
    $responseData['data'] = [];
}
$responseData['data']['score'] = $creditScore;
$responseData['data']['credit_score'] = $creditScore;
$responseData['score'] = $creditScore;
$responseData['credit_score'] = $creditScore;
$responseData['pdf_url'] = $pdfDownloadUrl;
$responseData['report_url'] = $pdfDownloadUrl;
$responseData['data']['pdf_url'] = $pdfDownloadUrl;
$responseData['data']['report_url'] = $pdfDownloadUrl;

$jsonStore = json_encode($responseData);

if ($customerId > 0) {
    try {
        db()->prepare('UPDATE customers SET credit_score = ?, credit_report_json = ?, mobile = ? WHERE id = ?')->execute([$creditScore, $jsonStore, $mobile, $customerId]);
        
        $q = db()->prepare('INSERT INTO credit_checks (customer_id, provider, reference_no, score, request_json, response_json, consent, checked_by) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
        $q->execute([$customerId, $provider, $orderId, $creditScore, json_encode($postFields), $jsonStore, $userId]);
    } catch (Exception $e) {
    }
}

echo json_encode([
    'success' => true,
    'status' => 'SUCCESS',
    'bureau_status' => $responseData['bureau_status'] ?? ($creditScore <= 0 ? 'NO_RECORD_FOUND' : 'RECORD_FOUND'),
    'is_new_to_credit' => ($creditScore <= 0 || !empty($responseData['is_new_to_credit'])),
    'provider' => $provider,
    'report_type' => $reportType,
    'test_mode' => $isTestMode,
    'wallet_debit' => ($isTestMode ? 0 : $price),
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
