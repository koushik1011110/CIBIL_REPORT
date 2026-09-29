<?php
/**
 * WhatsApp Business API (WABA) Integration Helper
 * Provider: KKWebMart WABA API (Meta Cloud API Wrapper)
 * Supports WhatsApp Template Message broadcasting and automated 3-day EMI due reminders.
 */

require_once __DIR__ . '/../config/config.php';

function waba_get_config()
{
    $apiUrl = trim(get_setting('waba_api_url') ?: 'https://waba.kkwebmart.in/api/v1');
    $apiKey = trim(get_setting('waba_api_key') ?: 'kkwaba_live_SzhxqdAvEuDwbwCF5RB43u1tRaFV7nkL1JAEdExSQo0');

    // Clean double slashes in API URL
    $apiUrl = rtrim(preg_replace('#([^:])//+#', '$1/', $apiUrl), '/');

    return [
        'api_url' => $apiUrl,
        'api_key' => $apiKey
    ];
}

/**
 * Ensure whatsapp_logs table exists in database
 */
function ensure_whatsapp_tables()
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $p = db();
        $p->exec("CREATE TABLE IF NOT EXISTS whatsapp_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NULL,
            finance_id INT NULL,
            emi_id INT NULL,
            mobile VARCHAR(25) NOT NULL,
            template_name VARCHAR(100) NOT NULL,
            parameters_json TEXT NULL,
            response_json LONGTEXT NULL,
            message_id VARCHAR(100) NULL,
            status VARCHAR(30) DEFAULT 'SENT',
            error_message TEXT NULL,
            sent_by VARCHAR(50) DEFAULT 'system',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wl_cust (customer_id),
            INDEX idx_wl_fin (finance_id),
            INDEX idx_wl_emi (emi_id),
            INDEX idx_wl_stat (status),
            INDEX idx_wl_date (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $e) {
        error_log("ensure_whatsapp_tables error: " . $e->getMessage());
    }
}

/**
 * Send a WhatsApp Template Message via KKWebMart WABA
 *
 * @param string $mobile Phone number (with or without 91)
 * @param string $templateName Name of approved template (e.g. 'emi_reminder')
 * @param array $bodyParams Ordered array of strings for {{1}}, {{2}}, {{3}}...
 * @param string $language Language code (default 'en')
 * @param array $context Optional metadata: ['customer_id' => ..., 'finance_id' => ..., 'emi_id' => ..., 'sent_by' => ...]
 * @return array ['success' => bool, 'message' => string, 'message_id' => string, 'data' => array]
 */
function waba_send_template($mobile, $templateName, array $bodyParams = [], $language = 'en', array $context = [])
{
    ensure_whatsapp_tables();
    $cfg = waba_get_config();

    if (empty($cfg['api_key'])) {
        return [
            'success' => false,
            'message' => 'WhatsApp API Key is not configured.'
        ];
    }

    // Clean Phone Number: remove spaces, dashes, +
    $cleanMobile = preg_replace('/\D/', '', (string)$mobile);
    if (strlen($cleanMobile) === 10) {
        $cleanMobile = '91' . $cleanMobile; // Default to India country code
    } elseif (strlen($cleanMobile) === 12 && substr($cleanMobile, 0, 2) === '91') {
        // already has 91
    } elseif (strlen($cleanMobile) < 10) {
        return [
            'success' => false,
            'message' => "Invalid phone number format: {$mobile}"
        ];
    }

    // Build Body Parameters
    $formattedParams = [];
    foreach ($bodyParams as $pVal) {
        $formattedParams[] = [
            'type' => 'text',
            'text' => (string)$pVal
        ];
    }

    $payload = [
        'to'   => $cleanMobile,
        'type' => 'template',
        'template' => [
            'name'     => trim($templateName),
            'language' => [
                'code' => $language ?: 'en'
            ],
            'components' => [
                [
                    'type'       => 'body',
                    'parameters' => $formattedParams
                ]
            ]
        ]
    ];

    $endpoint = $cfg['api_url'] . '/messages';

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $cfg['api_key'],
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $p = db();
    $customerId = $context['customer_id'] ?? null;
    $financeId  = $context['finance_id'] ?? null;
    $emiId      = $context['emi_id'] ?? null;
    $sentBy     = $context['sent_by'] ?? 'manual';

    if ($curlErr) {
        $errMsg = 'cURL Error: ' . $curlErr;
        $p->prepare("INSERT INTO whatsapp_logs (customer_id, finance_id, emi_id, mobile, template_name, parameters_json, status, error_message, sent_by) VALUES (?, ?, ?, ?, ?, ?, 'FAILED', ?, ?)")
          ->execute([$customerId, $financeId, $emiId, $cleanMobile, $templateName, json_encode($bodyParams), $errMsg, $sentBy]);

        return [
            'success' => false,
            'message' => $errMsg
        ];
    }

    $decoded = json_decode($response, true);
    $isSuccess = ($httpCode >= 200 && $httpCode < 300) && (!isset($decoded['error']));
    $msgId = $decoded['data']['message_id'] ?? ($decoded['data']['whatsapp_message_id'] ?? ($decoded['messages'][0]['id'] ?? ''));
    $errMsg = $decoded['error']['message'] ?? ($decoded['message'] ?? ($isSuccess ? '' : "HTTP {$httpCode} Error"));

    // Log to whatsapp_logs table
    try {
        $p->prepare("
            INSERT INTO whatsapp_logs (
                customer_id, finance_id, emi_id, mobile, template_name,
                parameters_json, response_json, message_id, status, error_message, sent_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $customerId,
            $financeId,
            $emiId,
            $cleanMobile,
            $templateName,
            json_encode($bodyParams),
            $response,
            $msgId,
            $isSuccess ? 'SENT' : 'FAILED',
            $isSuccess ? null : $errMsg,
            $sentBy
        ]);
    } catch (Exception $ex) {
        error_log("whatsapp log insert error: " . $ex->getMessage());
    }

    if ($isSuccess) {
        return [
            'success'    => true,
            'message_id' => $msgId,
            'data'       => $decoded['data'] ?? $decoded,
            'message'    => 'WhatsApp message sent successfully.'
        ];
    }

    return [
        'success'   => false,
        'http_code' => $httpCode,
        'message'   => $errMsg,
        'raw'       => $response
    ];
}

/**
 * Send the official 'emi_reminder' WhatsApp Template Message for a customer or specific EMI
 *
 * Template: emi_reminder
 * Body: Dear {{1}}, this is a reminder that your EMI of ₹{{2}} is scheduled for AutoPay on {{3}}. Please ensure sufficient balance in your account for the payment.
 *
 * @param int $customerId Customer ID
 * @param int|null $emiId Optional specific EMI schedule ID
 * @param string $sentBy 'manual', 'cron_3days', etc.
 * @return array
 */
function waba_send_emi_reminder($customerId, $emiId = null, $sentBy = 'manual')
{
    ensure_whatsapp_tables();
    $p = db();

    // Fetch Customer
    $cStmt = $p->prepare("SELECT id, name, mobile, email FROM customers WHERE id = ? LIMIT 1");
    $cStmt->execute([(int)$customerId]);
    $cust = $cStmt->fetch();

    if (!$cust || empty($cust['mobile'])) {
        return [
            'success' => false,
            'message' => 'Customer mobile number not found.'
        ];
    }

    // Fetch Target Unpaid EMI
    $emi = null;
    if ($emiId) {
        $eStmt = $p->prepare("SELECT e.*, f.application_no FROM emi_schedules e JOIN finance_applications f ON f.id = e.finance_id WHERE e.id = ?");
        $eStmt->execute([(int)$emiId]);
        $emi = $eStmt->fetch();
    } else {
        $eStmt = $p->prepare("
            SELECT e.*, f.application_no 
            FROM emi_schedules e 
            JOIN finance_applications f ON f.id = e.finance_id 
            WHERE f.customer_id = ? AND e.status != 'paid' 
            ORDER BY e.due_date ASC LIMIT 1
        ");
        $eStmt->execute([(int)$customerId]);
        $emi = $eStmt->fetch();
    }

    if (!$emi) {
        return [
            'success' => false,
            'message' => 'No unpaid EMI found for this customer.'
        ];
    }

    $customerName = trim($cust['name'] ?: 'Customer');
    $emiAmount    = number_format((float)$emi['amount'], 2, '.', ',');
    $dueDate      = date('d M Y', strtotime($emi['due_date']));

    // Parameters mapped to template body: {{1}}, {{2}}, {{3}}
    $bodyParams = [
        $customerName,  // {{1}}
        $emiAmount,     // {{2}} (₹ is already in template body)
        $dueDate        // {{3}}
    ];

    $context = [
        'customer_id' => (int)$cust['id'],
        'finance_id'  => (int)$emi['finance_id'],
        'emi_id'      => (int)$emi['id'],
        'sent_by'     => $sentBy
    ];

    $templateName = trim(get_setting('waba_emi_reminder_template') ?: 'emi_reminder');

    return waba_send_template($cust['mobile'], $templateName, $bodyParams, 'en', $context);
}

/**
 * Automated Engine: Scans for EMIs due exactly 3 days from now (or within 3 days)
 * and dispatches the WhatsApp 'emi_reminder' template.
 *
 * @param int $daysBefore Default 3 days before due date
 * @return array Summary of processed, sent, and skipped reminders
 */
function waba_process_due_reminders($daysBefore = 3)
{
    ensure_whatsapp_tables();
    $p = db();

    // Target due date is CURDATE() + $daysBefore days
    $targetDueDate = date('Y-m-d', strtotime("+{$daysBefore} days"));
    $today = date('Y-m-d');

    // Find all unpaid EMIs due on the target date
    $stmt = $p->prepare("
        SELECT e.id as emi_id, e.amount, e.due_date, e.installment_no,
               f.id as finance_id, f.application_no, f.customer_id,
               c.name as customer_name, c.mobile as customer_mobile
        FROM emi_schedules e
        JOIN finance_applications f ON f.id = e.finance_id
        JOIN customers c ON c.id = f.customer_id
        WHERE e.status != 'paid'
          AND e.due_date = ?
          AND c.mobile IS NOT NULL 
          AND c.mobile != ''
        ORDER BY e.id ASC
    ");
    $stmt->execute([$targetDueDate]);
    $dueEmis = $stmt->fetchAll();

    $totalFound = count($dueEmis);
    $sentCount = 0;
    $skippedCount = 0;
    $failedCount = 0;
    $details = [];

    foreach ($dueEmis as $item) {
        $emiId = (int)$item['emi_id'];
        $custId = (int)$item['customer_id'];

        // Prevent duplicate sending: check if a reminder was already sent for this EMI within the last 24 hours
        $checkStmt = $p->prepare("
            SELECT COUNT(*) FROM whatsapp_logs 
            WHERE emi_id = ? 
              AND template_name = 'emi_reminder' 
              AND status = 'SENT'
              AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $checkStmt->execute([$emiId]);
        if ($checkStmt->fetchColumn() > 0) {
            $skippedCount++;
            $details[] = [
                'emi_id'   => $emiId,
                'customer' => $item['customer_name'],
                'mobile'   => $item['customer_mobile'],
                'status'   => 'SKIPPED (Already sent within 24h)'
            ];
            continue;
        }

        $res = waba_send_emi_reminder($custId, $emiId, 'cron_3days');

        if ($res['success']) {
            $sentCount++;
            $details[] = [
                'emi_id'     => $emiId,
                'customer'   => $item['customer_name'],
                'mobile'     => $item['customer_mobile'],
                'message_id' => $res['message_id'] ?? '',
                'status'     => 'SENT'
            ];
        } else {
            $failedCount++;
            $details[] = [
                'emi_id'   => $emiId,
                'customer' => $item['customer_name'],
                'mobile'   => $item['customer_mobile'],
                'status'   => 'FAILED',
                'error'    => $res['message'] ?? 'Unknown error'
            ];
        }
    }

    if ($sentCount > 0) {
        log_audit(
            'WhatsApp EMI 3-Day Reminders Sent',
            'Communication',
            "Automated 3-day due date WhatsApp reminders sent to {$sentCount} borrower(s) for due date {$targetDueDate}."
        );
    }

    return [
        'date'            => $today,
        'target_due_date' => $targetDueDate,
        'days_before'     => $daysBefore,
        'total_found'     => $totalFound,
        'sent_count'      => $sentCount,
        'skipped_count'   => $skippedCount,
        'failed_count'    => $failedCount,
        'details'         => $details
    ];
}
