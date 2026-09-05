<?php
session_start();
date_default_timezone_set('Asia/Kolkata');

define('DB_HOST', 'localhost');
define('DB_NAME', 'go4fin');
define('DB_USER', 'root');
define('DB_PASS', '');

// PayU Payment Gateway Config
define('PAYU_MERCHANT_KEY', 'JLFa4D');
define('PAYU_SALT', 'BdsRuvcWukuapuJTrlAL0McodEVT2DMl');
define('PAYU_ENV', 'production'); // Change to 'production' for live payments
define('PAYU_BASE_URL', PAYU_ENV === 'production' ? 'https://secure.payu.in/_payment' : 'https://test.payu.in/_payment');

// Dynamically determine BASE_URL
$docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
$appDir = str_replace('\\', '/', realpath(__DIR__ . '/..') ?: '');

if (!empty($docRoot) && !empty($appDir) && strpos($appDir, $docRoot) === 0) {
    $baseUrl = substr($appDir, strlen($docRoot));
} else {
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $pos = strpos($script, '/soft');
    if ($pos !== false) {
        $baseUrl = substr($script, 0, $pos + 5);
    } else {
        $baseUrl = dirname($script);
    }
}

$baseUrl = '/' . ltrim(str_replace('\\', '/', $baseUrl), '/');
$baseUrl = rtrim($baseUrl, '/');
define('BASE_URL', $baseUrl);

function url($path = '')
{
    $p = '/' . ltrim($path, '/');
    return (BASE_URL === '/' || BASE_URL === '') ? $p : BASE_URL . $p;
}

function db()
{
    static $p;
    if (!$p)
        $p = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    return $p;
}

function get_setting($key, $default = '')
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            $p = db();
            $rows = $p->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
            foreach ($rows as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Exception $e) {}
    }
    return $cache[$key] ?? $default;
}

function set_setting($key, $value)
{
    $p = db();
    $stmt = $p->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute([$key, $value]);
}

function log_audit($action, $module, $description, $userId = null)
{
    try {
        $p = db();
        if ($userId === null && isset($_SESSION['user']['id'])) {
            $userId = (int)$_SESSION['user']['id'];
        }
        $stmt = $p->prepare("INSERT INTO audit_logs (user_id, action, module, description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $module, $description]);
    } catch (Exception $ex) {}
}

function send_email($toEmail, $subject, $bodyHtml)
{
    $host      = get_setting('smtp_host', '');
    $port      = (int)get_setting('smtp_port', '587');
    $user      = get_setting('smtp_username', '');
    $pass      = get_setting('smtp_password', '');
    $fromEmail = get_setting('smtp_from_email', 'contact@go4fin.com');
    $fromName  = get_setting('smtp_from_name', 'GO4 Finance Private Limited');
    $enc       = get_setting('smtp_encryption', 'tls');

    if (empty($host)) {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=utf-8\r\n";
        $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
        return @mail($toEmail, $subject, $bodyHtml, $headers);
    }

    try {
        $socketHost = ($enc === 'ssl' ? 'ssl://' : '') . $host;
        $connection = @fsockopen($socketHost, $port, $errno, $errstr, 10);
        if (!$connection) {
            throw new Exception("Could not connect to SMTP host {$host}:{$port} - {$errstr}");
        }

        $getResponse = function() use ($connection) {
            $data = '';
            while ($str = fgets($connection, 512)) {
                $data .= $str;
                if (substr($str, 3, 1) === ' ') break;
            }
            return $data;
        };

        $getResponse();
        fputs($connection, "EHLO " . gethostname() . "\r\n");
        $getResponse();

        if ($enc === 'tls') {
            fputs($connection, "STARTTLS\r\n");
            $getResponse();
            stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            fputs($connection, "EHLO " . gethostname() . "\r\n");
            $getResponse();
        }

        if (!empty($user) && !empty($pass)) {
            fputs($connection, "AUTH LOGIN\r\n");
            $getResponse();
            fputs($connection, base64_encode($user) . "\r\n");
            $getResponse();
            fputs($connection, base64_encode($pass) . "\r\n");
            $getResponse();
        }

        fputs($connection, "MAIL FROM: <{$fromEmail}>\r\n");
        $getResponse();
        fputs($connection, "RCPT TO: <{$toEmail}>\r\n");
        $getResponse();
        fputs($connection, "DATA\r\n");
        $getResponse();

        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
        $headers .= "To: <{$toEmail}>\r\n";
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "Date: " . date("r") . "\r\n";

        fputs($connection, $headers . "\r\n" . $bodyHtml . "\r\n.\r\n");
        $getResponse();
        fputs($connection, "QUIT\r\n");
        fclose($connection);
        return true;

    } catch (Exception $e) {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=utf-8\r\n";
        $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
        return @mail($toEmail, $subject, $bodyHtml, $headers);
    }
}

function approve_finance_application_and_notify($financeId)
{
    try {
        $p = db();
        $id = (int)$financeId;
        if ($id <= 0) return false;

        $stmt = $p->prepare("
            SELECT f.*, c.name as customer_name, c.mobile as customer_mobile, c.email as customer_email,
                   p.name as product_name, s.name as shop_name
            FROM finance_applications f
            JOIN customers c ON c.id = f.customer_id
            LEFT JOIN products p ON p.id = f.product_id
            LEFT JOIN shops s ON s.id = f.shop_id
            WHERE f.id = ?
        ");
        $stmt->execute([$id]);
        $app = $stmt->fetch();

        if (!$app) return false;

        // Update application status to approved
        $p->prepare("UPDATE finance_applications SET status = 'approved' WHERE id = ?")->execute([$id]);

        $shopId = (int)($app['shop_id'] ?? 1);
        $custName = $app['customer_name'];
        $custMobile = trim($app['customer_mobile'] ?? '');
        $custEmail = trim($app['customer_email'] ?? '');
        
        $loginEmail = !empty($custEmail) ? $custEmail : (!empty($custMobile) ? $custMobile . '@customer.local' : 'customer_' . $app['customer_id'] . '@customer.local');

        $plainPassword = !empty($custMobile) ? $custMobile : '123456';
        $hashedPassword = password_hash($plainPassword, PASSWORD_BCRYPT);

        // Check or create customer user account
        $uStmt = $p->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $uStmt->execute([$loginEmail]);
        $existingUser = $uStmt->fetch();

        if (!$existingUser) {
            $insUser = $p->prepare("INSERT INTO users (shop_id, name, email, password, role, status) VALUES (?, ?, ?, ?, 'customer', 'active')");
            $insUser->execute([$shopId, $custName, $loginEmail, $hashedPassword]);
        }

        // Dispatch Email Credentials
        if (!empty($custEmail) && strpos($custEmail, '@customer.local') === false) {
            $subject = "🎉 Your Store Loan Application Approved — Login Credentials (GO4FIN)";
            $loginUrl = url('/login.php');
            $productName = $app['product_name'] ?: 'Store Product';
            $appNo = $app['application_no'];

            $bodyHtml = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; border: 1px solid #e2e8f0; border-radius: 12px; background: #ffffff;'>
                <div style='text-align: center; margin-bottom: 20px;'>
                    <h2 style='color: #2563eb; margin: 0; font-size: 20px; font-weight: 800;'>GO4 FINANCE PRIVATE LIMITED</h2>
                    <p style='color: #64748b; font-size: 13px; margin-top: 4px;'>Certified Consumer Credit & Store Financing</p>
                </div>

                <div style='background: #e6f4ea; border: 1px solid #ceead6; color: #137333; padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: bold; font-size: 15px; text-align: center;'>
                    🎉 Congratulations! Your Loan Application Has Been Approved!
                </div>

                <p style='color: #334155; font-size: 14px; line-height: 1.6;'>Dear <strong>" . htmlspecialchars($custName) . "</strong>,</p>
                <p style='color: #334155; font-size: 14px; line-height: 1.6;'>We are pleased to inform you that your store financing loan application <strong>#" . htmlspecialchars($appNo) . "</strong> for <strong>" . htmlspecialchars($productName) . "</strong> has been approved successfully!</p>

                <div style='background: #f8fafc; border: 1px solid #cbd5e1; padding: 18px; border-radius: 10px; margin: 20px 0;'>
                    <h4 style='margin: 0 0 12px 0; color: #0f172a; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;'>🔑 Your Customer Portal Login Credentials</h4>
                    <p style='margin: 6px 0; font-size: 13px;'><strong>Login Portal:</strong> <a href='" . $loginUrl . "' style='color: #2563eb; font-weight: bold;'>" . $loginUrl . "</a></p>
                    <p style='margin: 6px 0; font-size: 13px;'><strong>Username / Email:</strong> <span style='font-family: monospace; font-weight: bold; color: #0f172a;'>" . htmlspecialchars($loginEmail) . "</span></p>
                    <p style='margin: 6px 0; font-size: 13px;'><strong>Default Password:</strong> <span style='font-family: monospace; font-weight: bold; color: #059669;'>" . htmlspecialchars($plainPassword) . "</span></p>
                </div>

                <h4 style='color: #0f172a; font-size: 14px; margin-top: 20px;'>📱 Customer Mobile Portal Features:</h4>
                <ul style='color: #475569; font-size: 13px; line-height: 1.8; padding-left: 20px;'>
                    <li><strong>Active Loan Status:</strong> Track real-time loan status & balances.</li>
                    <li><strong>Upcoming EMI Schedules:</strong> Check monthly installment due dates & amounts.</li>
                    <li><strong>Online EMI Repayments:</strong> Pay installments online via UPI, QR, or NetBanking.</li>
                    <li><strong>Download Documents:</strong> Instant access to Digital Loan Agreement & NOC Certificates.</li>
                </ul>

                <div style='text-align: center; margin-top: 25px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 11px; color: #94a3b8;'>
                    GO4 Finance Private Limited | Support Phone: +91 60005 47615 | Email: contact@go4fin.com
                </div>
            </div>";

            send_email($custEmail, $subject, $bodyHtml);
        }

        log_audit(
            'Loan Approval & Credentials Sent',
            'Loans',
            "Approved Application {$app['application_no']} and sent customer login credentials to {$loginEmail}"
        );

        return true;

    } catch (Exception $ex) {
        return false;
    }
}



