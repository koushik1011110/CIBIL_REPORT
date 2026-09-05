<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "message" => "Invalid Request Method. Use POST."]);
    exit;
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input) {
    $input = $_POST;
}

$name     = isset($input['name']) ? trim($input['name']) : '';
$mobile   = isset($input['mobile']) ? trim($input['mobile']) : '';
$fetchBy  = isset($input['fetch_by']) ? trim($input['fetch_by']) : 'pan';
$number   = isset($input['number']) ? trim($input['number']) : '';
$orderId  = isset($input['orderid']) && !empty($input['orderid']) ? trim($input['orderid']) : 'TXN' . time() . rand(1000, 9999);

if (empty($name) || empty($mobile) || empty($fetchBy) || empty($number)) {
    echo json_encode(["status" => "error", "message" => "Required parameters missing: name, mobile, fetch_by, number."]);
    exit;
}

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

$ch = curl_init(FINPAY_API_URL);
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
    echo json_encode(["status" => "error", "message" => "cURL Error: " . $curlError]);
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
        "status" => "error",
        "message" => "Credit Report Bureau API Error: " . $apiMsg,
        "orderid" => $orderId,
        "raw_response" => $responseData ?: $response
    ]);
    exit;
}

$creditScore = null;
if (isset($responseData['credit_score'])) {
    $creditScore = intval($responseData['credit_score']);
} elseif (isset($responseData['data']['credit_score'])) {
    $creditScore = intval($responseData['data']['credit_score']);
} elseif (isset($responseData['score'])) {
    $creditScore = intval($responseData['score']);
}

$jsonStore = json_encode($responseData);

try {
    $conn = getDBConnection();
    $stmt = $conn->prepare("INSERT INTO credit_reports (orderid, name, mobile, fetch_by, number, credit_score, api_status, response_json) VALUES (?, ?, ?, ?, ?, ?, 'success', ?)");
    $stmt->bind_param("sssssiss", $orderId, $name, $mobile, $fetchBy, $number, $creditScore, $jsonStore);
    $stmt->execute();
    $stmt->close();
    $conn->close();
} catch (Exception $e) {
}

$responseData['orderid'] = $orderId;
echo json_encode($responseData);
