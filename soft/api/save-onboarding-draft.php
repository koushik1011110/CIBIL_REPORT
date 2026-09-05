<?php
require_once __DIR__ . '/../includes/auth.php';
role('superadmin', 'shop_admin', 'staff', 'customer');
header('Content-Type: application/json');

try {
    $financeId = (int)($_POST['finance_id'] ?? 0);
    $currentStep = (int)($_POST['current_step'] ?? 1);

    if ($financeId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid Finance ID']);
        exit;
    }

    $p = db();

    $uploadDir = __DIR__ . '/../uploads/onboarding/';
    if (!file_exists($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    // Fetch existing onboarding record
    $obStmt = $p->prepare("SELECT * FROM finance_application_onboarding WHERE finance_id = ?");
    $obStmt->execute([$financeId]);
    $ob = $obStmt->fetch() ?: [];

    $handleUpload = function($fieldName, $existing = '') use ($uploadDir) {
        if (isset($_FILES[$fieldName]) && $_FILES[$fieldName]['error'] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION);
            $filename = $fieldName . '_' . time() . '_' . rand(1000,9999) . '.' . strtolower($ext);
            $target = $uploadDir . $filename;
            if (move_uploaded_file($_FILES[$fieldName]['tmp_name'], $target)) {
                return 'uploads/onboarding/' . $filename;
            }
        }
        return $existing;
    };

    // Fields mapping
    $fullName        = trim($_POST['full_name'] ?? $ob['full_name'] ?? '');
    $fatherName      = trim($_POST['father_name'] ?? $ob['father_name'] ?? '');
    $dob             = !empty($_POST['dob']) ? $_POST['dob'] : ($ob['dob'] ?? null);
    $gender          = trim($_POST['gender'] ?? $ob['gender'] ?? 'male');
    $mobile          = trim($_POST['mobile'] ?? $ob['mobile'] ?? '');
    $alternateMobile = trim($_POST['alternate_mobile'] ?? $ob['alternate_mobile'] ?? '');
    $email           = trim($_POST['email'] ?? $ob['email'] ?? '');
    $address         = trim($_POST['address'] ?? $ob['address'] ?? '');
    $city            = trim($_POST['city'] ?? $ob['city'] ?? '');
    $state           = trim($_POST['state'] ?? $ob['state'] ?? '');
    $pincode         = trim($_POST['pincode'] ?? $ob['pincode'] ?? '');

    $aadhaarNo       = trim($_POST['aadhaar_no'] ?? $ob['aadhaar_no'] ?? '');
    $qualification   = trim($_POST['qualification'] ?? $ob['qualification'] ?? '');
    $occupation      = trim($_POST['occupation'] ?? $ob['occupation'] ?? '');
    $monthlyIncome   = isset($_POST['monthly_income']) ? (float)$_POST['monthly_income'] : ($ob['monthly_income'] ?? 0);

    $clientPhoto     = $handleUpload('client_photo', $ob['client_photo'] ?? '');
    $clientSign      = $handleUpload('client_signature', $ob['client_signature'] ?? '');

    $panFront        = $handleUpload('pan_front', $ob['pan_front'] ?? '');
    $panBack         = $handleUpload('pan_back', $ob['pan_back'] ?? '');
    $aadhaarFront    = $handleUpload('aadhaar_front', $ob['aadhaar_front'] ?? '');
    $aadhaarBack     = $handleUpload('aadhaar_back', $ob['aadhaar_back'] ?? '');

    $witnessName     = trim($_POST['witness_name'] ?? $ob['witness_name'] ?? '');
    $witnessMobile   = trim($_POST['witness_mobile'] ?? $ob['witness_mobile'] ?? '');
    $witnessPhoto    = $handleUpload('witness_photo', $ob['witness_photo'] ?? '');
    $witnessSign     = $handleUpload('witness_signature', $ob['witness_signature'] ?? '');
    $witnessPanFront = $handleUpload('witness_pan_front', $ob['witness_pan_front'] ?? '');
    $witnessPanBack  = $handleUpload('witness_pan_back', $ob['witness_pan_back'] ?? '');

    $bankName        = trim($_POST['bank_name'] ?? $ob['bank_name'] ?? '');
    $accountHolder   = trim($_POST['account_holder'] ?? $ob['account_holder'] ?? '');
    $accountNo       = trim($_POST['account_no'] ?? $ob['account_no'] ?? '');
    $ifscCode        = trim($_POST['ifsc_code'] ?? $ob['ifsc_code'] ?? '');
    $accountType     = trim($_POST['account_type'] ?? $ob['account_type'] ?? 'savings');
    $mandateMode     = trim($_POST['mandate_mode'] ?? $ob['mandate_mode'] ?? 'enach');

    $upsertSql = "
        INSERT INTO finance_application_onboarding (
            finance_id, full_name, father_name, dob, gender, mobile, alternate_mobile, email, address, city, state, pincode,
            aadhaar_no, qualification, occupation, monthly_income, client_photo, client_signature,
            pan_front, pan_back, aadhaar_front, aadhaar_back,
            witness_name, witness_mobile, witness_photo, witness_signature, witness_pan_front, witness_pan_back,
            bank_name, account_holder, account_no, ifsc_code, account_type, mandate_mode,
            current_step
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?, ?,
            ?
        ) ON DUPLICATE KEY UPDATE
            full_name=VALUES(full_name), father_name=VALUES(father_name), dob=VALUES(dob), gender=VALUES(gender),
            mobile=VALUES(mobile), alternate_mobile=VALUES(alternate_mobile), email=VALUES(email),
            address=VALUES(address), city=VALUES(city), state=VALUES(state), pincode=VALUES(pincode),
            aadhaar_no=VALUES(aadhaar_no), qualification=VALUES(qualification), occupation=VALUES(occupation), monthly_income=VALUES(monthly_income),
            client_photo=COALESCE(NULLIF(VALUES(client_photo), ''), client_photo),
            client_signature=COALESCE(NULLIF(VALUES(client_signature), ''), client_signature),
            pan_front=COALESCE(NULLIF(VALUES(pan_front), ''), pan_front),
            pan_back=COALESCE(NULLIF(VALUES(pan_back), ''), pan_back),
            aadhaar_front=COALESCE(NULLIF(VALUES(aadhaar_front), ''), aadhaar_front),
            aadhaar_back=COALESCE(NULLIF(VALUES(aadhaar_back), ''), aadhaar_back),
            witness_name=VALUES(witness_name), witness_mobile=VALUES(witness_mobile),
            witness_photo=COALESCE(NULLIF(VALUES(witness_photo), ''), witness_photo),
            witness_signature=COALESCE(NULLIF(VALUES(witness_signature), ''), witness_signature),
            witness_pan_front=COALESCE(NULLIF(VALUES(witness_pan_front), ''), witness_pan_front),
            witness_pan_back=COALESCE(NULLIF(VALUES(witness_pan_back), ''), witness_pan_back),
            bank_name=VALUES(bank_name), account_holder=VALUES(account_holder), account_no=VALUES(account_no),
            ifsc_code=VALUES(ifsc_code), account_type=VALUES(account_type), mandate_mode=VALUES(mandate_mode),
            current_step=VALUES(current_step)
    ";

    $p->prepare($upsertSql)->execute([
        $financeId, $fullName, $fatherName, $dob, $gender, $mobile, $alternateMobile, $email, $address, $city, $state, $pincode,
        $aadhaarNo, $qualification, $occupation, $monthlyIncome, $clientPhoto, $clientSign,
        $panFront, $panBack, $aadhaarFront, $aadhaarBack,
        $witnessName, $witnessMobile, $witnessPhoto, $witnessSign, $witnessPanFront, $witnessPanBack,
        $bankName, $accountHolder, $accountNo, $ifscCode, $accountType, $mandateMode,
        $currentStep
    ]);

    echo json_encode([
        'success' => true,
        'current_step' => $currentStep,
        'message' => 'Step saved successfully'
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
