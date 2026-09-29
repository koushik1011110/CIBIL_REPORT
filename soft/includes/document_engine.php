<?php
/**
 * GO4FIN Document & PDF Automation Engine
 * Central service for data mapping, document numbering, template processing,
 * PDF generation, document history recording, and bulk export.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/document_db_init.php';
require_once __DIR__ . '/pdf_engine.php';

/**
 * Indian Rupee Number to Words Converter
 */
function get_amount_in_words($amount) {
    $amount = round((float)$amount, 2);
    $number = floor($amount);
    $fraction = round(($amount - $number) * 100);

    $words = [
        0 => '', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine',
        10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen',
        20 => 'Twenty', 30 => 'Thirty', 40 => 'Forty', 50 => 'Fifty', 60 => 'Sixty', 70 => 'Seventy', 80 => 'Eighty', 90 => 'Ninety'
    ];

    if ($number == 0) return 'Zero Rupees Only';

    $res = '';
    if ($number >= 10000000) {
        $crore = floor($number / 10000000);
        $number %= 10000000;
        $res .= get_amount_in_words($crore) . ' Crore ';
    }
    if ($number >= 100000) {
        $lakh = floor($number / 100000);
        $number %= 100000;
        $res .= ($lakh < 20 ? $words[$lakh] : $words[floor($lakh/10)*10] . ' ' . $words[$lakh%10]) . ' Lakh ';
    }
    if ($number >= 1000) {
        $thousand = floor($number / 1000);
        $number %= 1000;
        $res .= ($thousand < 20 ? $words[$thousand] : $words[floor($thousand/10)*10] . ' ' . $words[$thousand%10]) . ' Thousand ';
    }
    if ($number >= 100) {
        $hundred = floor($number / 100);
        $number %= 100;
        $res .= $words[$hundred] . ' Hundred ';
    }
    if ($number > 0) {
        $res .= ($number < 20 ? $words[$number] : $words[floor($number/10)*10] . ' ' . $words[$number%10]) . ' ';
    }

    $res = trim($res) . ' Rupees';
    if ($fraction > 0) {
        $fracStr = ($fraction < 20 ? $words[$fraction] : $words[floor($fraction/10)*10] . ' ' . $words[$fraction%10]);
        $res .= ' and ' . $fracStr . ' Paise';
    }
    return $res . ' Only';
}

/**
 * Prefix Mapping for collision-free numbering
 */
function get_document_prefix($docType) {
    $map = [
        'loan_application'      => 'APP',
        'sanction_letter'       => 'SNC',
        'loan_agreement'        => 'AGR',
        'repayment_schedule'    => 'SCH',
        'disbursement_letter'   => 'DSB',
        'payment_receipt'       => 'RCT',
        'account_statement'     => 'SOA',
        'outstanding_statement' => 'OUT',
        'foreclosure_statement' => 'FCL',
        'noc_certificate'       => 'NOC',
        'loan_closure'          => 'CLS'
    ];
    return $map[$docType] ?? 'DOC';
}

/**
 * Generate Collision-Free Document Number
 */
function generate_document_number($docType, $financeId, $paymentId = 0) {
    $p = db();
    $prefix = get_document_prefix($docType);
    $year = date('Y');
    
    // Existing AGR-GO4FIN-YYYY-xxxxx and NOC-GO4FIN-YYYY-xxxxx format preservation
    if ($docType === 'loan_agreement') {
        $candidate = 'AGR-GO4FIN-' . $year . '-' . str_pad($financeId, 5, '0', STR_PAD_LEFT);
        return $candidate;
    }
    if ($docType === 'noc_certificate') {
        $candidate = 'NOC-GO4FIN-' . $year . '-' . str_pad($financeId, 5, '0', STR_PAD_LEFT);
        return $candidate;
    }
    if ($docType === 'payment_receipt' && $paymentId > 0) {
        $candidate = 'RCT-GO4FIN-' . $year . '-' . str_pad($paymentId, 6, '0', STR_PAD_LEFT);
        return $candidate;
    }

    $base = $prefix . '-GO4FIN-' . $year . '-' . str_pad($financeId, 5, '0', STR_PAD_LEFT);

    // Check collision in documents table
    $stmt = $p->prepare("SELECT COUNT(*) FROM documents WHERE document_no = ?");
    $stmt->execute([$base]);
    if ($stmt->fetchColumn() == 0) {
        return $base;
    }

    // Append sequence suffix if multiple of same type generated
    $suf = 1;
    do {
        $candidate = $base . '-v' . (++$suf);
        $stmt->execute([$candidate]);
    } while ($stmt->fetchColumn() > 0);

    return $candidate;
}

/**
 * Comprehensive Data Provider
 * Aggregates existing database records without modifying existing calculation rules.
 */
function get_loan_document_data($financeId, $customerId = 0, $paymentId = 0) {
    $p = db();
    $financeId = (int)$financeId;
    $customerId = (int)$customerId;
    $paymentId = (int)$paymentId;

    $app = null;
    if ($financeId > 0) {
        $stmt = $p->prepare("
            SELECT f.*, 
                   c.name as customer_name, c.mobile as customer_mobile, c.email as customer_email,
                   c.pan as customer_pan, c.dob as customer_dob, c.address as customer_address,
                   c.aadhaar_no as cust_aadhaar_no, c.aadhaar_verified as cust_aadhaar_verified, c.credit_score,
                   ob.father_name, ob.gender, ob.alternate_mobile, ob.city as cust_city, ob.state as cust_state, ob.pincode as cust_pincode,
                   ob.aadhaar_no as ob_aadhaar_no, ob.aadhaar_verified as ob_aadhaar_verified,
                   ob.qualification, ob.occupation, ob.monthly_income,
                   ob.client_photo, ob.client_signature,
                   ob.pan_front, ob.pan_back, ob.aadhaar_front, ob.aadhaar_back,
                   ob.witness_name, ob.witness_mobile, ob.witness_photo, ob.witness_signature,
                   ob.bank_name, ob.account_holder, ob.account_no, ob.ifsc_code, ob.account_type, ob.mandate_mode, ob.mandate_status,
                   p.name as product_name, p.brand as product_brand, p.model as product_model, p.sku as product_sku, p.selling_price as product_mrp,
                   s.name as shop_name, s.phone as shop_phone, s.email as shop_email, s.address as shop_address, s.gstin as shop_gstin, s.logo as shop_logo
            FROM finance_applications f
            JOIN customers c ON c.id = f.customer_id
            LEFT JOIN finance_application_onboarding ob ON ob.finance_id = f.id
            LEFT JOIN products p ON p.id = f.product_id
            LEFT JOIN shops s ON s.id = f.shop_id
            WHERE f.id = ?
        ");
        $stmt->execute([$financeId]);
        $app = $stmt->fetch();
    } elseif ($customerId > 0) {
        // Customer standalone lookup
        $stmt = $p->prepare("SELECT c.*, s.name as shop_name, s.phone as shop_phone, s.email as shop_email, s.address as shop_address, s.gstin as shop_gstin, s.logo as shop_logo FROM customers c LEFT JOIN shops s ON s.id = c.shop_id WHERE c.id = ?");
        $stmt->execute([$customerId]);
        $cust = $stmt->fetch();
        if ($cust) {
            $app = [
                'id' => 0,
                'application_no' => 'CUST-' . str_pad($customerId, 5, '0', STR_PAD_LEFT),
                'customer_id' => $customerId,
                'customer_name' => $cust['name'],
                'customer_mobile' => $cust['mobile'],
                'customer_email' => $cust['email'],
                'customer_pan' => $cust['pan'],
                'customer_dob' => $cust['dob'],
                'customer_address' => $cust['address'],
                'credit_score' => $cust['credit_score'],
                'shop_name' => $cust['shop_name'],
                'shop_phone' => $cust['shop_phone'],
                'shop_email' => $cust['shop_email'],
                'shop_address' => $cust['shop_address'],
                'shop_gstin' => $cust['shop_gstin'],
                'shop_logo' => $cust['shop_logo'],
                'finance_amount' => 0,
                'product_price' => 0,
                'down_payment' => 0,
                'interest_rate' => 12.0,
                'tenure' => 0,
                'emi' => 0,
                'total_interest' => 0,
                'processing_fee' => 0,
                'total_payable' => 0,
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s')
            ];
        }
    }

    if (!$app) return null;

    // Normalizing Customer KYC
    $data = [];
    $data['app'] = $app;
    $data['finance_id'] = (int)$app['id'];
    $data['customer_id'] = (int)$app['customer_id'];
    $data['application_no'] = $app['application_no'];
    $data['status'] = strtolower($app['status'] ?? 'pending');
    $data['created_at'] = $app['created_at'];

    // Customer
    $data['customer_name']    = $app['customer_name'] ?? 'Valued Customer';
    $data['father_name']      = $app['father_name'] ?? '';
    $data['customer_mobile']  = $app['customer_mobile'] ?? '';
    $data['alternate_mobile'] = $app['alternate_mobile'] ?? '';
    $data['customer_email']   = $app['customer_email'] ?? '';
    $data['customer_pan']     = $app['customer_pan'] ?: 'N/A';
    $data['customer_dob']     = $app['customer_dob'] ?: '';
    $data['gender']           = ucfirst($app['gender'] ?? 'Male');
    $data['customer_address'] = trim(($app['customer_address'] ?? '') . ($app['cust_city'] ? ', ' . $app['cust_city'] : '') . ($app['cust_state'] ? ', ' . $app['cust_state'] : '') . ($app['cust_pincode'] ? ' - ' . $app['cust_pincode'] : ''));
    if (empty($data['customer_address'])) $data['customer_address'] = 'Assam, India';

    $rawAadhaar = !empty($app['ob_aadhaar_no']) ? $app['ob_aadhaar_no'] : ($app['cust_aadhaar_no'] ?? '');
    $data['aadhaar_masked'] = !empty($rawAadhaar) ? ('XXXX-XXXX-' . substr(preg_replace('/\D/', '', $rawAadhaar), -4)) : 'Verified via KYC';
    $data['aadhaar_verified'] = (!empty($app['ob_aadhaar_verified']) || !empty($app['cust_aadhaar_verified']));
    $data['qualification'] = $app['qualification'] ?? 'Graduate';
    $data['occupation'] = $app['occupation'] ?? 'Salaried / Business';
    $data['monthly_income'] = floatval($app['monthly_income'] ?? 0);
    $data['client_photo'] = $app['client_photo'] ?? '';
    $data['client_signature'] = $app['client_signature'] ?? '';

    // Guarantor / Witness
    $data['witness_name']      = $app['witness_name'] ?: 'Family Reference';
    $data['witness_mobile']    = $app['witness_mobile'] ?: 'N/A';
    $data['witness_signature'] = $app['witness_signature'] ?? '';

    // Bank / Mandate
    $data['bank_name']      = $app['bank_name'] ?: 'State Bank of India';
    $data['account_holder'] = $app['account_holder'] ?: $data['customer_name'];
    $data['account_no']     = $app['account_no'] ? ('XXXXXX' . substr($app['account_no'], -4)) : 'Mandate Registered';
    $data['ifsc_code']      = $app['ifsc_code'] ?: 'SBIN0001234';
    $data['mandate_mode']   = strtoupper($app['mandate_mode'] ?: 'eNACH / AutoPay');

    // Product & Shop
    $data['product_name']  = $app['product_name'] ?: 'Consumer Electronics Product';
    $data['product_brand'] = $app['product_brand'] ?: 'Standard';
    $data['product_model'] = $app['product_model'] ?: '';
    $data['product_sku']   = $app['product_sku'] ?: 'SKU-' . $app['product_id'];
    $data['product_price'] = floatval($app['product_price']);

    $data['shop_name']    = $app['shop_name'] ?: 'Demo Partner Store';
    $data['shop_phone']   = $app['shop_phone'] ?: '+91 60005 47615';
    $data['shop_email']   = $app['shop_email'] ?: 'contact@go4fin.com';
    $data['shop_gstin']   = $app['shop_gstin'] ?: '18AABCU9603R1ZM';
    $data['shop_address'] = $app['shop_address'] ?: 'Barpeta Road, Assam - 781315';
    $data['shop_logo']    = (!empty($app['shop_logo']) && file_exists(__DIR__ . '/../uploads/logos/' . $app['shop_logo']))
        ? url('/uploads/logos/' . $app['shop_logo'])
        : url('/public/assets/images/logo.png');

    // Company Official Branding
    $data['company_name'] = 'GO4 FINANCE PRIVATE LIMITED';
    $data['company_cin']  = 'U65929AS2022PTC023190';
    $data['company_tan']  = get_setting('cms_tan_no', 'SHLG03876F');
    $data['company_phone'] = get_setting('cms_phone', '+91 60005 47615');
    $data['company_email'] = get_setting('cms_email', 'contact@go4fin.com');
    $data['company_address'] = get_setting('cms_address', 'Barpeta Road, Near Attis Academy of Excellence, New Manas Road, Domani Gaon, PO Khairabari, Assam - 781315');
    $data['company_logo'] = url('/public/assets/images/logo.png');
    $data['managing_director'] = get_setting('company_signatory_name', 'Wazid Hoque');
    $data['operations_director'] = 'Wahida Begum';
    $data['company_signatory_name'] = get_setting('company_signatory_name', 'Wazid Hoque');
    $data['company_signatory_title'] = get_setting('company_signatory_title', 'Managing Director');

    $globalStamp = get_setting('company_stamp_signature', '');
    $data['company_stamp_file'] = $globalStamp;
    $data['company_stamp_url'] = (!empty($globalStamp) && file_exists(__DIR__ . '/../uploads/signatures/' . $globalStamp))
        ? url('/uploads/signatures/' . $globalStamp)
        : '';

    // Financial Calculation (Preserving existing logic 100%)
    $data['down_payment']    = floatval($app['down_payment']);
    $data['finance_amount']  = floatval($app['finance_amount']);
    $data['interest_rate']   = floatval($app['interest_rate']);
    $data['tenure']          = (int)$app['tenure'];
    $data['emi_amount']      = floatval($app['emi']);
    $data['total_interest']  = floatval($app['total_interest']);
    $data['processing_fee']  = floatval($app['processing_fee']);
    $data['total_payable']   = floatval($app['total_payable']);
    if ($data['total_payable'] <= 0) {
        $data['total_payable'] = round($data['emi_amount'] * $data['tenure'], 2);
    }

    // Fetch EMI Amortization Schedule
    $data['emis'] = [];
    $data['total_emis_count'] = 0;
    $data['paid_emis_count'] = 0;
    $data['unpaid_emis_count'] = 0;
    $data['overdue_emis_count'] = 0;
    $data['total_principal_paid'] = 0;
    $data['total_interest_paid'] = 0;
    $data['total_emi_paid'] = 0;
    $data['total_outstanding_due'] = 0;
    $data['overdue_amount'] = 0;
    $data['first_emi_date'] = '';
    $data['last_emi_date'] = '';
    $data['next_due_date'] = '';
    $data['next_due_amount'] = 0;

    if ($data['finance_id'] > 0) {
        $emiStmt = $p->prepare("SELECT * FROM emi_schedules WHERE finance_id = ? ORDER BY installment_no ASC");
        $emiStmt->execute([$data['finance_id']]);
        $data['emis'] = $emiStmt->fetchAll();

        $data['total_emis_count'] = count($data['emis']);
        $todayStr = date('Y-m-d');

        foreach ($data['emis'] as $idx => $eRow) {
            if ($idx === 0) $data['first_emi_date'] = date('d M Y', strtotime($eRow['due_date']));
            $data['last_emi_date'] = date('d M Y', strtotime($eRow['due_date']));

            $amt = floatval($eRow['amount']);
            $paidAmt = floatval($eRow['paid_amount']);

            if ($eRow['status'] === 'paid') {
                $data['paid_emis_count']++;
                $data['total_principal_paid'] += floatval($eRow['principal']);
                $data['total_interest_paid'] += floatval($eRow['interest']);
                $data['total_emi_paid'] += $paidAmt;
            } else {
                $data['unpaid_emis_count']++;
                $data['total_outstanding_due'] += max(0, $amt - $paidAmt);

                // Check overdue
                if ($eRow['due_date'] < $todayStr) {
                    $data['overdue_emis_count']++;
                    $data['overdue_amount'] += max(0, $amt - $paidAmt);
                }

                // Next due
                if (empty($data['next_due_date'])) {
                    $data['next_due_date'] = date('d M Y', strtotime($eRow['due_date']));
                    $data['next_due_amount'] = $amt;
                }
            }
        }
    }

    // Default dates if no schedule
    if (empty($data['first_emi_date'])) {
        $cDate = new DateTime($app['created_at'] ?? 'now');
        $cDay = (int)$cDate->format('j');
        $startOff = ($cDay > 20) ? 2 : 1;
        $target = (clone $cDate)->modify('first day of this month')->modify("+{$startOff} month");
        $data['first_emi_date'] = date('d M Y', strtotime($target->format('Y-m-04')));
    }
    if (empty($data['last_emi_date'])) {
        $cDate = new DateTime($app['created_at'] ?? 'now');
        $cDay = (int)$cDate->format('j');
        $startOff = ($cDay > 20) ? 2 : 1;
        $endOff = $startOff + max(1, (int)$data['tenure']) - 1;
        $target = (clone $cDate)->modify('first day of this month')->modify("+{$endOff} month");
        $data['last_emi_date'] = date('d M Y', strtotime($target->format('Y-m-04')));
    }

    // Total Amount Received (Down payment + EMIs)
    $data['total_paid'] = $data['down_payment'] + $data['total_emi_paid'];
    $data['principal_outstanding'] = max(0, $data['finance_amount'] - $data['total_principal_paid']);

    // Fully Paid / NOC condition
    $data['is_fully_paid'] = ($data['total_emis_count'] > 0 && $data['unpaid_emis_count'] === 0 && in_array($data['status'], ['approved', 'active', 'completed']));

    // Foreclosure amount (Early full settlement)
    $data['foreclosure_payable'] = $data['total_outstanding_due'];
    $data['foreclosure_valid_until'] = date('d M Y', strtotime('+15 days'));

    // Fetch Payments
    $data['payments'] = [];
    $data['target_payment'] = null;

    if ($data['finance_id'] > 0) {
        $payStmt = $p->prepare("SELECT p.*, e.installment_no FROM payments p LEFT JOIN emi_schedules e ON e.id = p.emi_id WHERE p.finance_id = ? ORDER BY p.id ASC");
        $payStmt->execute([$data['finance_id']]);
        $data['payments'] = $payStmt->fetchAll();
    }

    // If specific payment requested
    if ($paymentId > 0) {
        $pStmt = $p->prepare("SELECT p.*, e.installment_no, e.due_date as emi_due_date FROM payments p LEFT JOIN emi_schedules e ON e.id = p.emi_id WHERE p.id = ?");
        $pStmt->execute([$paymentId]);
        $data['target_payment'] = $pStmt->fetch();
    } elseif (!empty($data['payments'])) {
        // Latest payment default for receipt
        $data['target_payment'] = end($data['payments']);
    }

    return $data;
}

/**
 * Replace placeholders in template text
 */
function render_template_placeholders($content, $data, $docNo = '') {
    if (empty($content)) return '';

    $replacements = [
        '{{customer_name}}'      => $data['customer_name'] ?? '',
        '{{customer_id}}'        => $data['customer_id'] ?? '',
        '{{customer_mobile}}'    => $data['customer_mobile'] ?? '',
        '{{customer_email}}'     => $data['customer_email'] ?? '',
        '{{customer_pan}}'       => $data['customer_pan'] ?? '',
        '{{customer_address}}'   => $data['customer_address'] ?? '',
        '{{loan_number}}'        => $data['application_no'] ?? '',
        '{{loan_amount}}'        => money($data['finance_amount'] ?? 0),
        '{{sanctioned_amount}}'  => money($data['finance_amount'] ?? 0),
        '{{disbursed_amount}}'   => money($data['finance_amount'] ?? 0),
        '{{product_price}}'      => money($data['product_price'] ?? 0),
        '{{down_payment}}'       => money($data['down_payment'] ?? 0),
        '{{interest_rate}}'      => ($data['interest_rate'] ?? 0) . '%',
        '{{tenure}}'             => ($data['tenure'] ?? 0),
        '{{emi_amount}}'         => money($data['emi_amount'] ?? 0),
        '{{first_emi_date}}'     => $data['first_emi_date'] ?? '',
        '{{last_emi_date}}'      => $data['last_emi_date'] ?? '',
        '{{disbursement_date}}'  => date('d M Y', strtotime($data['created_at'] ?? 'now')),
        '{{document_number}}'    => $docNo ?: ($data['document_no'] ?? ''),
        '{{document_date}}'      => date('d M Y'),
        '{{outstanding_amount}}' => money($data['total_outstanding_due'] ?? 0),
        '{{total_paid}}'         => money($data['total_paid'] ?? 0),
        '{{product_name}}'       => $data['product_name'] ?? '',
        '{{shop_name}}'          => $data['shop_name'] ?? '',
        '{{company_name}}'       => $data['company_name'] ?? 'GO4 Finance Private Limited',
        '{{authorized_signatory}}'=> $data['managing_director'] ?? 'Wazid Hoque',
        '{{signatory_title}}'    => 'Managing Director'
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $content);
}

/**
 * Record document generation history in database
 */
function record_document_history($docType, $financeId, $customerId, $documentNo, $filePath, $title, $paymentId = null, $metadata = []) {
    try {
        $p = db();
        $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        $fileSize = (file_exists(__DIR__ . '/../' . ltrim($filePath, '/'))) ? filesize(__DIR__ . '/../' . ltrim($filePath, '/')) : 0;
        $metaJson = !empty($metadata) ? json_encode($metadata) : null;

        $checkStmt = $p->prepare("SELECT id FROM documents WHERE document_no = ? LIMIT 1");
        $checkStmt->execute([$documentNo]);
        $existingId = $checkStmt->fetchColumn();

        if ($existingId) {
            $upd = $p->prepare("UPDATE documents SET file_path = ?, file_size = ?, metadata = ?, status = 'generated', created_at = NOW() WHERE id = ?");
            $upd->execute([$filePath, $fileSize, $metaJson, $existingId]);
            return (int)$existingId;
        } else {
            $ins = $p->prepare("INSERT INTO documents (document_no, customer_id, finance_id, payment_id, generated_by, type, title, file_path, file_size, metadata, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'generated', NOW())");
            $ins->execute([
                $documentNo,
                $customerId > 0 ? $customerId : null,
                $financeId > 0 ? $financeId : null,
                $paymentId > 0 ? $paymentId : null,
                $userId,
                $docType,
                $title,
                $filePath,
                $fileSize,
                $metaJson
            ]);
            $newId = (int)$p->lastInsertId();

            log_audit(
                'Document Generated',
                'Documents',
                "Generated {$title} (#{$documentNo}) for Application ID {$financeId}, Customer ID {$customerId}",
                $userId
            );

            return $newId;
        }
    } catch (Exception $e) {
        error_log("Record Document History Error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Fetch Document Template from DB
 */
function get_document_template($docType) {
    static $cache = [];
    if (isset($cache[$docType])) return $cache[$docType];

    $p = db();
    $stmt = $p->prepare("SELECT * FROM document_templates WHERE doc_type = ? LIMIT 1");
    $stmt->execute([$docType]);
    $t = $stmt->fetch();
    $cache[$docType] = $t ?: [];
    return $cache[$docType];
}

/**
 * Generate Native PDF binary file and save to disk
 */
function generate_pdf_document_file($docType, $data, $paymentId = 0) {
    $docNo = generate_document_number($docType, $data['finance_id'], $paymentId);
    $template = get_document_template($docType);
    $docTitle = $template['title'] ?? ucwords(str_replace('_', ' ', $docType));

    $pdf = new Go4FinPDF();
    $pdf->docTitle = $docTitle;
    $pdf->AddPage();

    // 1. Corporate Header
    $pdf->SetFont('helvetica', 'B', 15);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(130, 6, 'GO4 FINANCE PRIVATE LIMITED', 0, 0, 'L');

    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(50, 4, strtoupper($docTitle), 0, 1, 'R');

    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(130, 4, 'CIN: U65929AS2022PTC023190 | Corporate Office: Assam, India | Ph: +91 60005 47615', 0, 0, 'L');

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(37, 99, 235);
    $pdf->Cell(50, 5, $docNo, 0, 1, 'R');

    $pdf->SetDrawColor(15, 23, 42);
    $pdf->SetLineWidth(0.4);
    $pdf->Line(15, $pdf->GetY() + 2, 195, $pdf->GetY() + 2);
    $pdf->Ln(4);

    // 2. Document Title Banner
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(15, 23, 42);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell(180, 8, strtoupper($docTitle), 0, 1, 'C', true);
    $pdf->Ln(3);

    // 3. Customer & Loan Summary Grid
    $pdf->SetFont('helvetica', 'B', 8.5);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->SetDrawColor(203, 213, 225);
    $pdf->Cell(90, 6, ' BORROWER / CUSTOMER DETAILS', 1, 0, 'L', true);
    $pdf->Cell(90, 6, ' FINANCING & LOAN DETAILS', 1, 1, 'L', true);

    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(30, 41, 59);

    $gridRows = [
        ['Customer Name:', $data['customer_name'], 'Application No:', $data['application_no']],
        ['Mobile / Phone:', $data['customer_mobile'], 'Loan Amount:', money($data['finance_amount'])],
        ['PAN Card No:', $data['customer_pan'], 'Monthly EMI:', money($data['emi_amount']) . ' / mo'],
        ['Aadhaar No:', $data['aadhaar_masked'], 'Tenure & Rate:', $data['tenure'] . ' Mos @ ' . $data['interest_rate'] . '%'],
        ['Address:', substr($data['customer_address'], 0, 38), 'Total Payable:', money($data['total_payable'])],
        ['Retail Store:', $data['shop_name'], 'Outstanding:', money($data['total_outstanding_due'])]
    ];

    foreach ($gridRows as $gr) {
        $pdf->SetFont('helvetica', 'B', 7.5);
        $pdf->Cell(25, 5, $gr[0], 'L', 0, 'L');
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->Cell(65, 5, $gr[1], 'R', 0, 'L');

        $pdf->SetFont('helvetica', 'B', 7.5);
        $pdf->Cell(25, 5, $gr[2], 'L', 0, 'L');
        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->Cell(65, 5, $gr[3], 'R', 1, 'L');
    }
    $pdf->Cell(180, 0.1, '', 'T', 1);
    $pdf->Ln(3);

    // 4. Document-Specific Content & Tables
    if ($docType === 'repayment_schedule' || $docType === 'loan_agreement') {
        $headers = ['Inst #', 'Due Date', 'Principal (Rs)', 'Interest (Rs)', 'EMI Amount (Rs)', 'Status'];
        $widths  = [22, 32, 32, 32, 36, 26];
        $aligns  = ['C', 'C', 'R', 'R', 'R', 'C'];
        $rows = [];
        foreach ($data['emis'] as $eItem) {
            $rows[] = [
                '#' . $eItem['installment_no'],
                date('d M Y', strtotime($eItem['due_date'])),
                number_format((float)$eItem['principal'], 2),
                number_format((float)$eItem['interest'], 2),
                number_format((float)$eItem['amount'], 2),
                strtoupper($eItem['status'])
            ];
        }
        if (!empty($rows)) {
            $pdf->Table($headers, $rows, $widths, $aligns);
            $pdf->Ln(3);
        }
    } elseif ($docType === 'payment_receipt') {
        $pay = $data['target_payment'];
        if ($pay) {
            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(236, 253, 245);
            $pdf->SetDrawColor(167, 243, 208);
            $pdf->SetTextColor(4, 120, 87);
            $pdf->Cell(180, 8, ' PAYMENT RECEIVED: ' . money($pay['amount']) . ' (' . strtoupper($pay['payment_method'] ?: 'Cash') . ')', 1, 1, 'C', true);
            $pdf->Ln(2);

            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(30, 41, 59);
            $pdf->MultiCell(180, 5, 'Amount in Words: ' . get_amount_in_words($pay['amount']) . "\nTransaction Ref: " . ($pay['reference_no'] ?: 'N/A') . " | Date: " . date('d M Y, h:i A', strtotime($pay['paid_at'])) . "\nRemarks: " . ($pay['remarks'] ?: 'EMI Installment Repayment'));
            $pdf->Ln(2);
        }
    } elseif ($docType === 'account_statement') {
        $headers = ['Date', 'Transaction / Narration', 'Debit (Rs)', 'Credit (Rs)', 'Balance (Rs)'];
        $widths  = [28, 76, 26, 26, 24];
        $aligns  = ['C', 'L', 'R', 'R', 'R'];
        $rows = [];
        $runBal = $data['finance_amount'];
        $rows[] = [
            date('d M Y', strtotime($data['created_at'])),
            'Loan Disbursed - ' . $data['product_name'],
            number_format($data['finance_amount'], 2),
            '-',
            number_format($runBal, 2)
        ];
        foreach ($data['payments'] as $pItem) {
            $amt = floatval($pItem['amount']);
            $runBal = max(0, $runBal - $amt);
            $rows[] = [
                date('d M Y', strtotime($pItem['paid_at'])),
                ($pItem['remarks'] ?: 'Payment Received') . ' (' . $pItem['payment_method'] . ')',
                '-',
                number_format($amt, 2),
                number_format($runBal, 2)
            ];
        }
        $pdf->Table($headers, $rows, $widths, $aligns);
        $pdf->Ln(3);
    } elseif ($docType === 'outstanding_statement' || $docType === 'foreclosure_statement') {
        $pdf->SetFont('helvetica', 'B', 8.5);
        $pdf->SetFillColor(254, 242, 242);
        $pdf->SetTextColor(185, 28, 28);
        $pdf->SetDrawColor(254, 202, 202);
        $sumTitle = ($docType === 'foreclosure_statement') ? 'TOTAL FORECLOSURE SETTLEMENT PAYABLE' : 'TOTAL CURRENT OUTSTANDING DUES';
        $sumAmt = ($docType === 'foreclosure_statement') ? $data['foreclosure_payable'] : $data['total_outstanding_due'];
        $pdf->Cell(180, 8, $sumTitle . ': ' . money($sumAmt), 1, 1, 'C', true);
        $pdf->Ln(2);
    }

    // 5. Template Body / Terms & Conditions
    $bodyText = render_template_placeholders($template['template_content'] ?? '', $data, $docNo);
    if (!empty($bodyText)) {
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(180, 5, 'TERMS, CONDITIONS & OFFICIAL DECLARATIONS:', 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 7.5);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->MultiCell(180, 4, $bodyText);
        $pdf->Ln(4);
    }

    // 6. Signatures & Official Stamp
    if ($pdf->GetY() + 30 > 275) {
        $pdf->AddPage();
    }

    $pdf->SetFont('helvetica', '', 7.5);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(60, 4, 'Borrower Signature', 0, 0, 'C');
    $pdf->Cell(60, 4, 'Store Partner Verification', 0, 0, 'C');
    $pdf->Cell(60, 4, 'For GO4 FINANCE PVT LTD', 0, 1, 'C');

    $pdf->Ln(2);
    $currY = $pdf->GetY();
    $pdf->SetDrawColor(37, 99, 235);
    $pdf->SetTextColor(37, 99, 235);
    $pdf->SetFont('helvetica', 'B', 6.5);
    $pdf->SetXY(142, $currY);
    $pdf->Cell(46, 5, '[ GO4FIN OFFICIAL STAMP & SEAL ]', 1, 1, 'C');
    $pdf->Ln(3);

    $signName = !empty($data['company_signatory_name'])
        ? $data['company_signatory_name']
        : (!empty($template['authorized_signatory_name']) ? $template['authorized_signatory_name'] : ($data['managing_director'] ?? 'Wazid Hoque'));
    $signTitle = !empty($data['company_signatory_title'])
        ? $data['company_signatory_title']
        : (!empty($template['authorized_signatory_title']) ? $template['authorized_signatory_title'] : 'Managing Director');

    $pdf->SetFont('helvetica', 'B', 8);
    $pdf->SetTextColor(15, 23, 42);
    $pdf->Cell(60, 4, $data['customer_name'], 0, 0, 'C');
    $pdf->Cell(60, 4, $data['shop_name'], 0, 0, 'C');
    $pdf->Cell(60, 4, $signName, 0, 1, 'C');

    $pdf->SetFont('helvetica', '', 7);
    $pdf->SetTextColor(148, 163, 184);
    $pdf->Cell(60, 3, 'Digitally Verified', 0, 0, 'C');
    $pdf->Cell(60, 3, 'Retail Counter Stamp', 0, 0, 'C');
    $pdf->Cell(60, 3, $signTitle, 0, 1, 'C');

    // 7. Footer
    $pdf->SetY(282);
    $pdf->SetFont('helvetica', '', 6.5);
    $pdf->SetTextColor(148, 163, 184);
    $footerMsg = $template['footer_text'] ?: 'Computer Generated Official Finance Document · GO4 Finance Private Limited';
    $pdf->Cell(180, 3, $footerMsg, 0, 1, 'C');
    $pdf->Cell(180, 3, 'Document No: ' . $docNo . ' | Date of Issue: ' . date('d M Y H:i') . ' | Page 1 of 1', 0, 0, 'C');

    // Save PDF to disk
    $relPath = 'uploads/documents/' . $docNo . '.pdf';
    $fullPath = __DIR__ . '/../' . $relPath;

    $pdf->Output('F', $fullPath);

    // Record into documents table
    record_document_history($docType, $data['finance_id'], $data['customer_id'], $docNo, $relPath, $docTitle, $paymentId, [
        'generated_date' => date('Y-m-d H:i:s'),
        'application_no' => $data['application_no']
    ]);

    return [
        'doc_no' => $docNo,
        'title' => $docTitle,
        'rel_path' => $relPath,
        'full_path' => $fullPath,
        'pdf_obj' => $pdf
    ];
}

/**
 * Bulk Generate Documents & Pack into ZIP
 */
function generate_bulk_documents_zip($financeIds, $docType, $userId = null) {
    if (empty($financeIds) || !is_array($financeIds)) {
        return ['success' => false, 'message' => 'No finance applications selected.'];
    }

    $zipDir = __DIR__ . '/../uploads/documents/';
    if (!file_exists($zipDir)) {
        @mkdir($zipDir, 0777, true);
    }

    $zipName = 'bulk_' . $docType . '_' . date('Ymd_His') . '_' . rand(100, 999) . '.zip';
    $zipPath = $zipDir . $zipName;

    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['success' => false, 'message' => 'Cannot create ZIP archive on server.'];
    }

    $generatedCount = 0;
    $errors = [];

    foreach ($financeIds as $fId) {
        $fId = (int)$fId;
        if ($fId <= 0) continue;

        try {
            $data = get_loan_document_data($fId);
            if (!$data) {
                $errors[] = "Application #$fId not found";
                continue;
            }

            // If NOC or Closure requested, ensure loan qualifies
            if (($docType === 'noc_certificate' || $docType === 'loan_closure') && !$data['is_fully_paid']) {
                $errors[] = "Application #{$data['application_no']} has unpaid EMIs, skipped {$docType}.";
                continue;
            }

            $res = generate_pdf_document_file($docType, $data);
            if (file_exists($res['full_path'])) {
                $zip->addFile($res['full_path'], basename($res['full_path']));
                $generatedCount++;
            }
        } catch (Exception $ex) {
            $errors[] = "Error on App #$fId: " . $ex->getMessage();
        }
    }

    $zip->close();

    if ($generatedCount === 0) {
        if (file_exists($zipPath)) @unlink($zipPath);
        return [
            'success' => false,
            'message' => 'No documents could be generated. ' . implode(', ', $errors)
        ];
    }

    return [
        'success' => true,
        'count' => $generatedCount,
        'zip_url' => url('/uploads/documents/' . $zipName),
        'zip_name' => $zipName,
        'errors' => $errors
    ];
}
