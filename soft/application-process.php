<?php
require_once __DIR__ . '/includes/layout.php';
role('superadmin', 'shop_admin', 'staff');

$p = db();
$financeId = (int)($_GET['id'] ?? 0);

if ($financeId <= 0) {
    header('Location: ' . url('/admin/applications.php'));
    exit;
}

// Fetch application
$stmt = $p->prepare("
    SELECT f.*, c.name as cust_name, c.mobile as cust_mobile, c.email as cust_email, c.address as cust_address, c.pan as cust_pan, c.dob as cust_dob,
           p.name as product_name, s.name as shop_name
    FROM finance_applications f
    JOIN customers c ON c.id = f.customer_id
    LEFT JOIN products p ON p.id = f.product_id
    LEFT JOIN shops s ON s.id = f.shop_id
    WHERE f.id = ?
");
$stmt->execute([$financeId]);
$app = $stmt->fetch();

if (!$app) {
    exit('Application not found');
}

// Fetch existing onboarding details if any
$obStmt = $p->prepare("SELECT * FROM finance_application_onboarding WHERE finance_id = ?");
$obStmt->execute([$financeId]);
$ob = $obStmt->fetch() ?: [];

$msg = '';
$err = '';

// Form submit handling
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $uploadDir = __DIR__ . '/uploads/onboarding/';
        if (!file_exists($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        // Helper for file uploads
        $handleUpload = function($fieldName, $existing = '') use ($uploadDir) {
            if (isset($_FILES[$fieldName]) && $_FILES[$fieldName]['error'] === UPLOAD_ERR_OK) {
                $ext = pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION);
                $filename = $fieldName . '_' . time() . '_' . rand(1000,9999) . '.' . strtolower($ext);
                $target = $uploadDir . $filename;
                if (move_uploaded_file($_FILES[$fieldName]['tmp_name'], $target)) {
                    return 'uploads/onboarding/' . $filename;
                }
            }
            // Check base64 signature/photo data
            if (!empty($_POST[$fieldName . '_base64'])) {
                $base64Data = $_POST[$fieldName . '_base64'];
                if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
                    $data = substr($base64Data, strpos($base64Data, ',') + 1);
                    $data = base64_decode($data);
                    $ext = strtolower($type[1]);
                    $filename = $fieldName . '_' . time() . '_' . rand(1000,9999) . '.' . $ext;
                    file_put_contents($uploadDir . $filename, $data);
                    return 'uploads/onboarding/' . $filename;
                }
            }
            return $existing;
        };

        // Step 1: Personal
        $fullName        = trim($_POST['full_name'] ?? $app['cust_name']);
        $fatherName      = trim($_POST['father_name'] ?? '');
        $dob             = trim($_POST['dob'] ?? $app['cust_dob']);
        $gender          = trim($_POST['gender'] ?? 'male');
        $mobile          = trim($_POST['mobile'] ?? $app['cust_mobile']);
        $alternateMobile = trim($_POST['alternate_mobile'] ?? '');
        $email           = trim($_POST['email'] ?? $app['cust_email']);
        $address         = trim($_POST['address'] ?? $app['cust_address']);
        $city            = trim($_POST['city'] ?? '');
        $state           = trim($_POST['state'] ?? '');
        $pincode         = trim($_POST['pincode'] ?? '');

        // Step 2: KYC & Professional
        $aadhaarNo     = trim($_POST['aadhaar_no'] ?? '');
        $qualification = trim($_POST['qualification'] ?? '');
        $occupation    = trim($_POST['occupation'] ?? '');
        $monthlyIncome = (float)($_POST['monthly_income'] ?? 0);
        $clientPhoto   = $handleUpload('client_photo', $ob['client_photo'] ?? '');
        $clientSign    = $handleUpload('client_signature', $ob['client_signature'] ?? '');

        // Step 3: Upload Documents & Witness Details
        $panFront       = $handleUpload('pan_front', $ob['pan_front'] ?? '');
        $panBack        = $handleUpload('pan_back', $ob['pan_back'] ?? '');
        $aadhaarFront   = $handleUpload('aadhaar_front', $ob['aadhaar_front'] ?? '');
        $aadhaarBack    = $handleUpload('aadhaar_back', $ob['aadhaar_back'] ?? '');

        $witnessName    = trim($_POST['witness_name'] ?? '');
        $witnessMobile  = trim($_POST['witness_mobile'] ?? '');
        $witnessPhoto   = $handleUpload('witness_photo', $ob['witness_photo'] ?? '');
        $witnessSign    = $handleUpload('witness_signature', $ob['witness_signature'] ?? '');
        $witnessPanFront= $handleUpload('witness_pan_front', $ob['witness_pan_front'] ?? '');
        $witnessPanBack = $handleUpload('witness_pan_back', $ob['witness_pan_back'] ?? '');

        // Step 4: Installment & Bank/Auto Mandate
        $bankName      = trim($_POST['bank_name'] ?? '');
        $accountHolder = trim($_POST['account_holder'] ?? $fullName);
        $accountNo     = trim($_POST['account_no'] ?? '');
        $ifscCode      = trim($_POST['ifsc_code'] ?? '');
        $accountType   = trim($_POST['account_type'] ?? 'savings');
        $mandateMode   = trim($_POST['mandate_mode'] ?? 'enach');

        // Insert or Update onboarding record
        $upsertSql = "
            INSERT INTO finance_application_onboarding (
                finance_id, full_name, father_name, dob, gender, mobile, alternate_mobile, email, address, city, state, pincode,
                aadhaar_no, qualification, occupation, monthly_income, client_photo, client_signature,
                pan_front, pan_back, aadhaar_front, aadhaar_back,
                witness_name, witness_mobile, witness_photo, witness_signature, witness_pan_front, witness_pan_back,
                bank_name, account_holder, account_no, ifsc_code, account_type, mandate_mode,
                mandate_status, onboarding_status, completed_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?,
                'submitted', 'completed', NOW()
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
                onboarding_status='completed', completed_at=NOW()
        ";
        
        $p->prepare($upsertSql)->execute([
            $financeId, $fullName, $fatherName, $dob, $gender, $mobile, $alternateMobile, $email, $address, $city, $state, $pincode,
            $aadhaarNo, $qualification, $occupation, $monthlyIncome, $clientPhoto, $clientSign,
            $panFront, $panBack, $aadhaarFront, $aadhaarBack,
            $witnessName, $witnessMobile, $witnessPhoto, $witnessSign, $witnessPanFront, $witnessPanBack,
            $bankName, $accountHolder, $accountNo, $ifscCode, $accountType, $mandateMode
        ]);


        // Update application status to kyc_completed
        $p->prepare("UPDATE finance_applications SET status = 'kyc_completed' WHERE id = ?")->execute([$financeId]);

        $redirectRole = u()['role'] === 'superadmin' ? 'admin' : (u()['role'] === 'shop_admin' ? 'shop' : 'staff');
        header('Location: ' . url('/' . $redirectRole . '/applications.php?msg=kyc_done'));
        exit;
    } catch (Exception $e) {
        $err = 'Error saving onboarding: ' . $e->getMessage();
    }
}

start('Store Finance Onboarding Process · App #' . $app['application_no']);
?>

<style>
.step-nav { display: flex; gap: 8px; margin-bottom: 24px; overflow-x: auto; padding-bottom: 8px; }
.step-nav-btn { flex: 1; min-width: 140px; padding: 12px; background: rgba(0,0,0,0.05); border: 1px solid var(--border-color); border-radius: var(--radius-md); text-align: center; font-size: 0.85rem; font-weight: 700; color: var(--text-muted); cursor: pointer; transition: all 0.25s; }
.step-nav-btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.step-nav-btn.completed { background: rgba(16, 185, 129, 0.15); color: var(--accent); border-color: var(--accent); }
.step-card { display: none; }
.step-card.active { display: block; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media(max-width: 768px) { .form-grid { grid-template-columns: 1fr; } }
.doc-box { border: 2px dashed var(--border-color); padding: 16px; border-radius: var(--radius-md); text-align: center; background: rgba(0,0,0,0.02); margin-top: 6px; }
.signature-canvas { border: 1px solid var(--border-color); border-radius: 8px; width: 100%; height: 120px; background: #fff; touch-action: none; cursor: crosshair; }
</style>

<div class="card" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <div>
        <h3 style="font-size: 1.25rem; font-weight: 800;">Customer Verification & 4-Step Onboarding</h3>
        <p class="muted">Application No: <strong><?=e($app['application_no'])?></strong> | Customer: <strong><?=e($app['cust_name'])?></strong> (<?=e($app['cust_mobile'])?>)</p>
    </div>
    <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
        <?php if (in_array($app['status'], ['approved', 'active'])): ?>
            <span class="badge badge-success" style="font-size: 0.85rem; padding: 8px 14px;">✓ APPROVED / ACTIVE</span>
        <?php elseif ($app['status'] === 'kyc_completed'): ?>
            <span class="badge badge-primary" style="font-size: 0.85rem; padding: 8px 14px;">✓ KYC COMPLETED</span>
        <?php else: ?>
            <span class="badge badge-warning" style="font-size: 0.85rem; padding: 8px 14px;">PENDING ONBOARDING</span>
        <?php endif; ?>
        <span class="badge" style="font-size: 0.85rem; padding: 8px 14px; background: rgba(59,130,246,0.1); color: var(--primary); border: 1px solid var(--border-color);">Product: <?=e($app['product_name'] ?: 'Mobile Finance')?> (<?=money($app['finance_amount'])?>)</span>
    </div>

</div>

<?php if ($err): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 14px; border-radius: 12px; margin-bottom: 20px;">
        <?=$err?>
    </div>
<?php endif; ?>

<!-- STEP INDICATOR NAV -->
<div class="step-nav">
    <div class="step-nav-btn active" id="navStep1" onclick="goToStep(1)">1. Personal Info</div>
    <div class="step-nav-btn" id="navStep2" onclick="goToStep(2)">2. KYC & Professional</div>
    <div class="step-nav-btn" id="navStep3" onclick="goToStep(3)">3. Documents & Witness</div>
    <div class="step-nav-btn" id="navStep4" onclick="goToStep(4)">4. Installment & Mandate</div>
</div>

<form method="POST" enctype="multipart/form-data" id="onboardingForm" novalidate>

    <!-- STEP 1: CUSTOMER PERSONAL -->
    <div class="step-card active" id="stepCard1">
        <div class="card">
            <h4 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 18px;">Step 1: Customer Personal Details</h4>
            <div class="form-grid">
                <div class="field">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" required value="<?=e($ob['full_name'] ?? $app['cust_name'])?>">
                </div>
                <div class="field">
                    <label>Father's Name *</label>
                    <input type="text" name="father_name" required placeholder="e.g. Shri Rajesh Sharma" value="<?=e($ob['father_name'] ?? '')?>">
                </div>
                <div class="field">
                    <label>Date of Birth *</label>
                    <input type="date" name="dob" required value="<?=e($ob['dob'] ?? $app['cust_dob'] ?? '1995-01-01')?>">
                </div>
                <div class="field">
                    <label>Gender *</label>
                    <select name="gender">
                        <option value="male" <?=($ob['gender']??'')==='male'?'selected':''?>>Male</option>
                        <option value="female" <?=($ob['gender']??'')==='female'?'selected':''?>>Female</option>
                        <option value="other" <?=($ob['gender']??'')==='other'?'selected':''?>>Other</option>
                    </select>
                </div>
                <div class="field">
                    <label>Mobile Number *</label>
                    <input type="text" name="mobile" required value="<?=e($ob['mobile'] ?? $app['cust_mobile'])?>">
                </div>
                <div class="field">
                    <label>Alternate Mobile Number</label>
                    <input type="text" name="alternate_mobile" placeholder="e.g. 9876543211" value="<?=e($ob['alternate_mobile'] ?? '')?>">
                </div>
                <div class="field">
                    <label>Email Address</label>
                    <input type="email" name="email" value="<?=e($ob['email'] ?? $app['cust_email'])?>">
                </div>

                <div class="field">
                    <label>City *</label>
                    <input type="text" name="city" required value="<?=e($ob['city'] ?? 'New Delhi')?>">
                </div>
                <div class="field">
                    <label>State *</label>
                    <input type="text" name="state" required value="<?=e($ob['state'] ?? 'Delhi')?>">
                </div>
                <div class="field">
                    <label>Pincode *</label>
                    <input type="text" name="pincode" required value="<?=e($ob['pincode'] ?? '110001')?>">
                </div>
                <div class="field full">
                    <label>Full Residential Address *</label>
                    <textarea name="address" rows="2" required><?=e($ob['address'] ?? $app['cust_address'])?></textarea>
                </div>
            </div>
            <div style="text-align: right; margin-top: 20px;">
                <button type="button" class="btn" onclick="nextStep(2)">Next: KYC & Professional Details →</button>
            </div>
        </div>
    </div>

    <!-- STEP 2: KYC & PROFESSIONAL -->
    <div class="step-card" id="stepCard2">
        <div class="card">
            <h4 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 18px;">Step 2: Customer KYC & Professional Details</h4>
            <div class="form-grid">
                <div class="field">
                    <label>Aadhaar Card Number *</label>
                    <div style="display: flex; gap: 8px;">
                        <input type="text" name="aadhaar_no" id="aadhaarInput" placeholder="12 Digit Aadhaar Number" required value="<?=e($ob['aadhaar_no'] ?? '')?>" maxlength="12" style="flex: 1;">
                        <button type="button" class="btn" id="btnVerifyAadhaar" style="padding: 10px 14px; font-size: 0.8rem; background: linear-gradient(135deg, #059669, #10b981);" onclick="sendAadhaarOtp()">
                            📲 Send Aadhaar OTP
                        </button>
                    </div>
                    <div id="aadhaarOtpBox" style="display: none; margin-top: 10px; background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.3); padding: 12px; border-radius: 10px;">
                        <label style="font-size: 0.82rem; font-weight: 700; color: #047857; margin-bottom: 6px; display: block;">Enter 6-Digit OTP received on Aadhaar Mobile *</label>
                        <div style="display: flex; gap: 8px;">
                            <input type="text" id="aadhaarOtpInput" placeholder="Enter OTP e.g. 123456" maxlength="6" style="flex: 1; text-align: center; font-weight: 800; letter-spacing: 2px;">
                            <button type="button" class="btn" id="btnSubmitOtp" style="padding: 8px 16px; background: #059669; font-size: 0.82rem;" onclick="submitAadhaarOtp()">
                                ✓ Verify OTP
                            </button>
                        </div>
                    </div>
                    <div id="aadhaarVerifyBadge" style="margin-top: 6px;">
                        <?php if (!empty($ob['aadhaar_verified'])): ?>
                            <span class="badge-aadhaar-verified" style="display:inline-flex; align-items:center; gap:6px; background:#e6f4ea; color:#137333; border:1px solid #ceead6; padding:6px 14px; border-radius:20px; font-weight:800; font-size:0.82rem;">
                                🛡️ ✓ Verified by Aadhaar UIDAI <?=!empty($ob['verified_aadhaar_name']) ? '— ' . e($ob['verified_aadhaar_name']) : ''?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>


                <div class="field">
                    <label>Educational Qualification</label>
                    <select name="qualification">
                        <option value="Graduate" <?=($ob['qualification']??'')==='Graduate'?'selected':''?>>Graduate / Bachelor</option>
                        <option value="Post Graduate" <?=($ob['qualification']??'')==='Post Graduate'?'selected':''?>>Post Graduate / Master</option>
                        <option value="12th Pass" <?=($ob['qualification']??'')==='12th Pass'?'selected':''?>>12th Senior Secondary</option>
                        <option value="10th Pass" <?=($ob['qualification']??'')==='10th Pass'?'selected':''?>>10th Secondary</option>
                        <option value="Other" <?=($ob['qualification']??'')==='Other'?'selected':''?>>Other</option>
                    </select>
                </div>
                <div class="field">
                    <label>Occupation / Employment Type *</label>
                    <select name="occupation" required>
                        <option value="Salaried Person" <?=($ob['occupation']??'')==='Salaried Person'?'selected':''?>>Salaried Employee</option>
                        <option value="Self Employed Business" <?=($ob['occupation']??'')==='Self Employed Business'?'selected':''?>>Self Employed / Business Owner</option>
                        <option value="Professional" <?=($ob['occupation']??'')==='Professional'?'selected':''?>>Independent Professional</option>
                        <option value="Government Servant" <?=($ob['occupation']??'')==='Government Servant'?'selected':''?>>Government Sector Employee</option>
                    </select>
                </div>
                <div class="field">
                    <label>Monthly Gross Income (₹) *</label>
                    <input type="number" name="monthly_income" required value="<?=e($ob['monthly_income'] ?? '35000')?>">
                </div>

                <?php 
                $renderBox = function($fieldKey, $fieldLabel, $accept = 'image/*,.pdf') use ($ob) {
                    $val = $ob[$fieldKey] ?? '';
                    $hasVal = !empty($val) && file_exists(__DIR__ . '/' . $val);
                    $fileUrl = url('/' . $val);
                    $ext = strtolower(pathinfo($val, PATHINFO_EXTENSION));
                    $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif']);
                    ?>
                    <div class="field">
                        <label><?=$fieldLabel?> *</label>
                        <div class="doc-box" style="text-align: left; padding: 12px;">
                            <?php if ($hasVal): ?>
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 8px; background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.25); padding: 8px 12px; border-radius: 8px;">
                                    <?php if ($isImg): ?>
                                        <a href="<?=$fileUrl?>" target="_blank"><img src="<?=$fileUrl?>" style="width: 50px; height: 50px; object-fit: cover; border-radius: 6px; border: 1px solid var(--border-color);"></a>
                                    <?php else: ?>
                                        <a href="<?=$fileUrl?>" target="_blank" style="font-weight: 700; color: var(--primary);">📄 PDF File</a>
                                    <?php endif; ?>
                                    <div style="flex: 1;">
                                        <span style="color: var(--success); font-weight: 800; font-size: 0.82rem;">✓ Uploaded</span><br>
                                        <a href="<?=$fileUrl?>" target="_blank" style="font-size: 0.76rem; color: var(--primary); text-decoration: underline;">👁️ Click to Preview File</a>
                                    </div>
                                </div>
                                <label style="font-size: 0.76rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">🔄 Re-upload / Replace File:</label>
                            <?php endif; ?>

                            <input type="file" name="<?=$fieldKey?>" accept="<?=$accept?>" data-uploaded="<?=$hasVal ? '1' : '0'?>" <?=$hasVal ? '' : 'required'?>>
                        </div>
                    </div>
                <?php }; ?>

                <?php $renderBox('client_photo', 'Client Passport Photo', 'image/*'); ?>
                <?php $renderBox('client_signature', 'Client Photo / Digital Signature', 'image/*'); ?>
            </div>

            <div style="display: flex; justify-content: space-between; margin-top: 20px;">
                <button type="button" class="btn" style="background: var(--text-muted);" onclick="goToStep(1)">← Previous</button>
                <button type="button" class="btn" onclick="nextStep(3)">Next: Upload Documents & Witness →</button>
            </div>
        </div>
    </div>

    <!-- STEP 3: UPLOAD DOCUMENTS & WITNESS DETAILS -->
    <div class="step-card" id="stepCard3">
        <div class="card">
            <h4 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 18px;">Step 3: Document Uploads & Witness Verification</h4>
            
            <h5 style="font-weight:700; font-size:0.92rem; margin-bottom:10px; color:var(--primary);">📄 Client Documents</h5>
            <div class="form-grid" style="margin-bottom: 24px;">
                <?php $renderBox('pan_front', 'Client PAN Card — Front'); ?>
                <?php $renderBox('pan_back', 'Client PAN Card — Back'); ?>
                <?php $renderBox('aadhaar_front', 'Client Aadhaar Card — Front'); ?>
                <?php $renderBox('aadhaar_back', 'Client Aadhaar Card — Back'); ?>
            </div>

            <h5 style="font-weight:700; font-size:0.92rem; margin-bottom:10px; color:var(--primary);">👤 Witness Details & Documents</h5>
            <div class="form-grid">
                <div class="field">
                    <label>Witness Full Name *</label>
                    <input type="text" name="witness_name" required value="<?=e($ob['witness_name'] ?? '')?>">
                </div>
                <div class="field">
                    <label>Witness Mobile Number *</label>
                    <input type="text" name="witness_mobile" required value="<?=e($ob['witness_mobile'] ?? '')?>">
                </div>
                <?php $renderBox('witness_photo', 'Witness Passport Photo', 'image/*'); ?>
                <?php $renderBox('witness_signature', 'Witness Signature', 'image/*'); ?>
                <?php $renderBox('witness_pan_front', 'Witness PAN Card — Front'); ?>
                <?php $renderBox('witness_pan_back', 'Witness PAN Card — Back'); ?>
            </div>



            <div style="display: flex; justify-content: space-between; margin-top: 20px;">
                <button type="button" class="btn" style="background: var(--text-muted);" onclick="goToStep(2)">← Previous</button>
                <button type="button" class="btn" onclick="nextStep(4)">Next: Bank & Auto Mandate →</button>
            </div>
        </div>
    </div>

    <!-- STEP 4: INSTALLMENT & BANK/AUTO MANDATE -->
    <div class="step-card" id="stepCard4">
        <div class="card" style="margin-bottom: 20px;">
            <h4 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 14px;">Step 4: Installment Summary & Bank / Auto Mandate</h4>
            
            <!-- Installment Summary -->
            <div style="background: rgba(59, 130, 246, 0.08); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 16px; margin-bottom: 20px;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; text-align: center;">
                    <div><span class="muted" style="font-size: 0.75rem;">PRODUCT PRICE</span><div style="font-size: 1.1rem; font-weight: 800;"><?=money($app['product_price'])?></div></div>
                    <div><span class="muted" style="font-size: 0.75rem;">DOWN PAYMENT</span><div style="font-size: 1.1rem; font-weight: 800; color: var(--accent);"><?=money($app['down_payment'])?></div></div>
                    <div><span class="muted" style="font-size: 0.75rem;">FINANCED AMOUNT</span><div style="font-size: 1.1rem; font-weight: 800; color: var(--primary);"><?=money($app['finance_amount'])?></div></div>
                    <div><span class="muted" style="font-size: 0.75rem;">MONTHLY EMI</span><div style="font-size: 1.1rem; font-weight: 800; color: var(--success);"><?=money($app['emi'])?>/mo</div></div>
                    <div><span class="muted" style="font-size: 0.75rem;">TENURE</span><div style="font-size: 1.1rem; font-weight: 800;"><?=e($app['tenure'])?> Months</div></div>
                </div>
            </div>

            <!-- Bank & Auto Mandate Fields -->
            <div class="form-grid">
                <div class="field">
                    <label>Bank Name *</label>
                    <input type="text" name="bank_name" required placeholder="e.g. HDFC Bank / SBI / ICICI" value="<?=e($ob['bank_name'] ?? '')?>">
                </div>
                <div class="field">
                    <label>Account Holder Name *</label>
                    <input type="text" name="account_holder" required value="<?=e($ob['account_holder'] ?? $app['cust_name'])?>">
                </div>
                <div class="field">
                    <label>Bank Account Number *</label>
                    <input type="text" name="account_no" required placeholder="e.g. 5010023456789" value="<?=e($ob['account_no'] ?? '')?>">
                </div>
                <div class="field">
                    <label>IFSC Code *</label>
                    <input type="text" name="ifsc_code" required placeholder="e.g. HDFC0001234" value="<?=e($ob['ifsc_code'] ?? '')?>">
                </div>
                <div class="field">
                    <label>Account Type *</label>
                    <select name="account_type">
                        <option value="savings" <?=($ob['account_type']??'')==='savings'?'selected':''?>>Savings Account</option>
                        <option value="current" <?=($ob['account_type']??'')==='current'?'selected':''?>>Current Account</option>
                    </select>
                </div>
                <div class="field">
                    <label>Auto Mandate Mode *</label>
                    <select name="mandate_mode">
                        <option value="enach" <?=($ob['mandate_mode']??'')==='enach'?'selected':''?>>eNACH / NetBanking AutoDebit</option>
                        <option value="upi_autopay" <?=($ob['mandate_mode']??'')==='upi_autopay'?'selected':''?>>UPI AutoPay Mandate</option>
                        <option value="cheque" <?=($ob['mandate_mode']??'')==='cheque'?'selected':''?>>Post Dated Cheque (PDC)</option>
                    </select>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; margin-top: 24px;">
                <button type="button" class="btn" style="background: var(--text-muted);" onclick="goToStep(3)">← Previous</button>
                <button type="button" class="btn" style="background: linear-gradient(135deg, var(--success), #059669); padding: 12px 24px; font-size: 1rem;" onclick="submitOnboardingForm()">
                    ✓ Complete Onboarding & Submit Verification
                </button>
            </div>

        </div>
    </div>
</form>

<script>
function checkInputValid(input) {
    if (input.type === 'file') {
        const isUploaded = input.dataset.uploaded === '1';
        const hasFile = input.files && input.files.length > 0;
        if (!isUploaded && !hasFile) {
            const box = input.closest('.doc-box');
            if (box) box.style.borderColor = 'var(--danger)';
            return false;
        } else {
            const box = input.closest('.doc-box');
            if (box) box.style.borderColor = '';
            return true;
        }
    } else {
        if (!input.value || input.value.trim() === '') {
            input.style.borderColor = 'var(--danger)';
            return false;
        } else {
            input.style.borderColor = '';
            return true;
        }
    }
}

function goToStep(stepNum) {
    // If attempting to move to a future step, validate current step required fields first
    const currentActiveCard = document.querySelector('.step-card.active');
    if (currentActiveCard) {
        const currentNum = parseInt(currentActiveCard.id.replace('stepCard', '')) || 1;
        if (stepNum > currentNum) {
            const requiredInputs = currentActiveCard.querySelectorAll('[required]');
            let isValid = true;
            let firstInvalid = null;
            requiredInputs.forEach(input => {
                if (!checkInputValid(input)) {
                    isValid = false;
                    if (!firstInvalid) firstInvalid = input;
                }
            });
            if (!isValid) {
                if (firstInvalid) {
                    firstInvalid.focus();
                    alert('Please fill out/upload all required fields & documents marked with * in this step before proceeding.');
                }
                return false;
            }
        }
    }

    document.querySelectorAll('.step-card').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.step-nav-btn').forEach(el => el.classList.remove('active'));
    
    document.getElementById('stepCard' + stepNum).classList.add('active');
    document.getElementById('navStep' + stepNum).classList.add('active');
    window.scrollTo({ top: 100, behavior: 'smooth' });
}

let currentAadhaarOrderId = '';

document.addEventListener('DOMContentLoaded', () => {
    const savedStep = <?= (int)($ob['current_step'] ?? 1) ?>;
    if (savedStep > 1 && savedStep <= 4) {
        for (let i = 1; i < savedStep; i++) {
            const btn = document.getElementById('navStep' + i);
            if (btn) btn.classList.add('completed');
        }
        goToStep(savedStep);
    }
});

function nextStep(stepNum) {
    const currentStep = stepNum - 1;
    if (currentStep >= 1) {
        document.getElementById('navStep' + currentStep).classList.add('completed');
    }

    // Auto save step draft via AJAX so refresh restores progress
    const form = document.getElementById('onboardingForm');
    const formData = new FormData(form);
    formData.append('finance_id', '<?=$financeId?>');
    formData.append('current_step', stepNum);

    fetch('<?=url('/api/save-onboarding-draft.php')?>', {
        method: 'POST',
        body: formData
    }).catch(e => console.log('Draft save error:', e));

    goToStep(stepNum);
}

function submitOnboardingForm() {
    const form = document.getElementById('onboardingForm');
    const requiredInputs = form.querySelectorAll('[required]');
    let isValid = true;
    let firstInvalid = null;
    let invalidStepNum = 1;

    requiredInputs.forEach(input => {
        if (!checkInputValid(input)) {
            isValid = false;
            if (!firstInvalid) {
                firstInvalid = input;
                const parentStep = input.closest('.step-card');
                if (parentStep) {
                    invalidStepNum = parseInt(parentStep.id.replace('stepCard', '')) || 1;
                }
            }
        }
    });

    if (!isValid) {
        goToStep(invalidStepNum);
        if (firstInvalid) {
            firstInvalid.focus();
            alert('Please fill out/upload all required fields & documents marked with * before submitting.');
        }
        return false;
    }

    form.submit();
}

function sendAadhaarOtp() {
    const aadhaarInput = document.getElementById('aadhaarInput');
    const aadhaarNo = aadhaarInput.value.replace(/\D/g, '');
    const btn = document.getElementById('btnVerifyAadhaar');
    const otpBox = document.getElementById('aadhaarOtpBox');

    if (aadhaarNo.length !== 12) {
        alert('Please enter a valid 12-digit Aadhaar Card Number.');
        aadhaarInput.focus();
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '⏳ Sending OTP...';

    const formData = new FormData();
    formData.append('step', '1');
    formData.append('aadhaar_no', aadhaarNo);
    formData.append('finance_id', '<?=$financeId?>');
    formData.append('customer_id', '<?=$app['customer_id']?>');

    fetch('<?=url('/api/verify-aadhaar.php')?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        if (data.success) {
            btn.innerHTML = '📲 Resend OTP';
            currentAadhaarOrderId = data.orderid || '';
            otpBox.style.display = 'block';
            document.getElementById('aadhaarOtpInput').focus();
            alert('✓ ' + (data.message || 'Aadhaar OTP sent to registered mobile number!'));
        } else {
            btn.innerHTML = '📲 Send Aadhaar OTP';
            otpBox.style.display = 'none';
            alert('❌ ' + (data.message || 'Invalid Aadhaar Card Number or Verification Failed.'));
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '📲 Send Aadhaar OTP';
        otpBox.style.display = 'none';
        alert('Error connecting to Aadhaar Verification Server.');
    });
}


function submitAadhaarOtp() {
    const aadhaarInput = document.getElementById('aadhaarInput');
    const otpInput = document.getElementById('aadhaarOtpInput');
    const aadhaarNo = aadhaarInput.value.replace(/\D/g, '');
    const otp = otpInput.value.trim();
    const btn = document.getElementById('btnSubmitOtp');
    const badgeBox = document.getElementById('aadhaarVerifyBadge');
    const otpBox = document.getElementById('aadhaarOtpBox');

    if (!otp || otp.length < 4) {
        alert('Please enter the OTP received.');
        otpInput.focus();
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '⏳ Verifying...';

    const formData = new FormData();
    formData.append('step', '2');
    formData.append('aadhaar_no', aadhaarNo);
    formData.append('otp', otp);
    formData.append('orderid', currentAadhaarOrderId);
    formData.append('finance_id', '<?=$financeId?>');
    formData.append('customer_id', '<?=$app['customer_id']?>');

    fetch('<?=url('/api/verify-aadhaar.php')?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '✓ Verify OTP';
        if (data.success) {
            otpBox.style.display = 'none';
            badgeBox.innerHTML = '<span class="badge-aadhaar-verified" style="display:inline-flex; align-items:center; gap:6px; background:#e6f4ea; color:#137333; border:1px solid #ceead6; padding:6px 14px; border-radius:20px; font-weight:800; font-size:0.82rem; margin-top:4px;">🛡️ ✓ Verified by Aadhaar UIDAI — ' + (data.name || 'Verified Customer') + '</span>';
            alert('✓ Aadhaar Card Verified Successfully by UIDAI!\nHolder Name: ' + (data.name || 'Verified Customer'));
        } else {
            alert(data.message || 'OTP verification failed.');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '✓ Verify OTP';
        alert('Error verifying OTP.');
    });
}
</script>





<?php render_end(); ?>
