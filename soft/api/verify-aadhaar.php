<?php
require_once __DIR__ . '/../includes/auth.php';
role('superadmin', 'shop_admin', 'staff', 'customer');
header('Content-Type: application/json');

try {
    $step = (int)($_POST['step'] ?? 1);
    $aadhaarNo = trim($_POST['aadhaar_no'] ?? $_POST['Aadhaarid'] ?? '');
    $otp = trim($_POST['otp'] ?? '');
    $orderId = trim($_POST['orderid'] ?? ('TXN' . time() . rand(1000, 9999)));
    $financeId = (int)($_POST['finance_id'] ?? 0);
    $customerId = (int)($_POST['customer_id'] ?? 0);

    // Clean Aadhaar number
    $aadhaarNo = preg_replace('/\D/', '', $aadhaarNo);

    if (strlen($aadhaarNo) !== 12) {
        echo json_encode([
            'success' => false,
            'message' => 'Please enter a valid 12-digit Aadhaar Card Number.'
        ]);
        exit;
    }

    $apiKey = get_setting('finpay_aadhaar_api_key', '8d8fd1-efeaa9-928494-24a4fd-0c7dd1');

    if ($step === 1) {
        // STEP 1: SEND AADHAAR OTP
        $postData = [
            'api_key'   => $apiKey,
            'orderid'   => $orderId,
            'step'      => 1,
            'Aadhaarid' => $aadhaarNo
        ];

        $ch = curl_init('https://api.finpayultra.com/api/aadhaar-verification.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $resData = json_decode($response, true) ?: [];

        // Check if API returned explicit failure or error
        $isStep1Failed = (isset($resData['status']) && (strtolower((string)$resData['status']) === 'failed' || strtolower((string)$resData['status']) === 'error' || $resData['status'] === 0 || $resData['status'] === false))
                      || (isset($resData['response_code']) && $resData['response_code'] == 0);

        if ($isStep1Failed) {
            $errMsg = $resData['message'] ?? $resData['error_msg'] ?? $resData['msg'] ?? 'Aadhaar Verification Failed: Invalid Aadhaar Card Number or details not found.';
            echo json_encode([
                'success' => false,
                'step'    => 1,
                'message' => $errMsg,
                'raw'     => $resData
            ]);
            exit;
        }

        // Return OTP Sent response
        echo json_encode([
            'success'  => true,
            'step'     => 1,
            'orderid'  => $resData['orderid'] ?? $orderId,
            'message'  => $resData['message'] ?? 'Aadhaar OTP sent successfully to linked mobile number.',
            'raw'      => $resData
        ]);
        exit;

    }

    if ($step === 2) {
        // STEP 2: VERIFY AADHAAR OTP & GET NAME
        if (empty($otp)) {
            echo json_encode([
                'success' => false,
                'message' => 'Please enter the 6-digit Aadhaar OTP.'
            ]);
            exit;
        }

        $postData = [
            'api_key'   => $apiKey,
            'orderid'   => $orderId,
            'step'      => 2,
            'otp'       => $otp,
            'Aadhaarid' => $aadhaarNo
        ];

        $ch = curl_init('https://api.finpayultra.com/api/aadhaar-verification.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        $resData = json_decode($response, true) ?: [];

        // Extract Name from response
        $verifiedName = $resData['data']['name'] 
            ?? $resData['data']['full_name'] 
            ?? $resData['name'] 
            ?? $resData['full_name'] 
            ?? 'Verified Customer';

        // Check success
        $isSuccess = ($httpCode === 200 && (
            (isset($resData['status']) && (strtolower((string)$resData['status']) === 'success' || $resData['status'] === 1 || $resData['status'] === true)) ||
            (isset($resData['response_code']) && $resData['response_code'] == 1) ||
            (isset($resData['success']) && $resData['success'] === true)
        ));


        if ($isSuccess) {
            $p = db();
            if ($financeId > 0) {
                $p->prepare("
                    UPDATE finance_application_onboarding 
                    SET aadhaar_no = ?, aadhaar_verified = 1, verified_aadhaar_name = ?
                    WHERE finance_id = ?
                ")->execute([$aadhaarNo, $verifiedName, $financeId]);
            }

            if ($customerId > 0) {
                $p->prepare("
                    UPDATE customers 
                    SET aadhaar_no = ?, aadhaar_verified = 1 
                    WHERE id = ?
                ")->execute([$aadhaarNo, $customerId]);
            }

            echo json_encode([
                'success' => true,
                'step' => 2,
                'verified' => true,
                'name' => $verifiedName,
                'message' => '✓ Aadhaar Card Verified Successfully by UIDAI! Name: ' . $verifiedName,
                'aadhaar_no' => $aadhaarNo,
                'badge_html' => '<span class="badge-aadhaar-verified" style="display:inline-flex; align-items:center; gap:6px; background:#e6f4ea; color:#137333; border:1px solid #ceead6; padding:6px 14px; border-radius:20px; font-weight:800; font-size:0.82rem; margin-top:6px;">🛡️ ✓ Verified by Aadhaar UIDAI — ' . htmlspecialchars($verifiedName) . '</span>'
            ]);
            exit;
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Aadhaar OTP Verification Failed: ' . ($resData['message'] ?? $curlErr ?: 'Invalid OTP')
            ]);
            exit;
        }
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error verifying Aadhaar: ' . $e->getMessage()
    ]);
}
