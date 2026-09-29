<?php
/**
 * Cron Job & Automated Background Runner: 3-Day Prior EMI WhatsApp Reminder
 * 
 * Automatically sends the 'emi_reminder' WhatsApp template message to customers
 * whose EMI due date is exactly 3 days from today (or upcoming within 3 days).
 *
 * CLI Usage: php cron-emi-whatsapp-reminders.php
 * HTTP Usage: GET /api/cron-emi-whatsapp-reminders.php?token=CRON_SECRET
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/whatsapp.php';

$isCli = (php_sapi_name() === 'cli');

// Basic security token check for HTTP execution
if (!$isCli) {
    $cronSecret = get_setting('cron_secret_key', 'GO4FIN_CRON_SECURE_TOKEN_2026');
    $providedToken = $_GET['token'] ?? ($_POST['token'] ?? '');
    $user = function_exists('u') ? u() : null;

    if ($providedToken !== $cronSecret && (!isset($user['role']) || !in_array($user['role'], ['superadmin', 'shop_admin', 'staff']))) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden: Invalid authorization token']);
        exit;
    }
}

// Days before due date to remind (default 3 days as requested)
$daysBefore = isset($_GET['days']) ? (int)$_GET['days'] : 3;
if ($daysBefore <= 0) $daysBefore = 3;

$result = waba_process_due_reminders($daysBefore);

// Update last run timestamp in settings
set_setting('last_whatsapp_reminder_cron_date', date('Y-m-d H:i:s'));

if ($isCli) {
    echo "========================================\n";
    echo "GO4FIN 3-DAY EMI WHATSAPP REMINDER RUNNER\n";
    echo "Date: {$result['date']} | Target Due Date: {$result['target_due_date']} (-{$result['days_before']} days)\n";
    echo "Found: {$result['total_found']} | Sent: {$result['sent_count']} | Skipped: {$result['skipped_count']} | Failed: {$result['failed_count']}\n";
    echo "========================================\n";
    print_r($result['details']);
} else {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
}
