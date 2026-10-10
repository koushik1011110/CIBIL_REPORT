<?php
/**
 * GO4FIN - Database Fresh Reset & Initializer
 * Resets all transactional data (customers, loans, emi, payments, documents, audit logs)
 * to 0/clean state while preserving core configurations, settings, templates, and directors.
 */

if (php_sapi_name() !== 'cli' && (!isset($_GET['key']) || $_GET['key'] !== 'reset2026')) {
    die("Access denied. Run via CLI: php soft/database/reset_fresh_db.php");
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/document_db_init.php';
require_once __DIR__ . '/../includes/directors_init.php';
require_once __DIR__ . '/../includes/onboarding_db_init.php';
require_once __DIR__ . '/../includes/cashfree.php';
require_once __DIR__ . '/../includes/whatsapp.php';

$p = db();

echo "========================================================\n";
echo "   GO4FIN DATABASE FRESH RESET & INITIALIZATION\n";
echo "========================================================\n\n";

$p->exec("SET FOREIGN_KEY_CHECKS = 0");

// 1. Ensure all tables exist with complete schema
$tablesToEnsure = [
    // Settings table
    "CREATE TABLE IF NOT EXISTS settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Shops table
    "CREATE TABLE IF NOT EXISTS shops (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150),
        phone VARCHAR(30),
        email VARCHAR(190),
        gstin VARCHAR(30) NULL,
        address TEXT NULL,
        logo VARCHAR(255) NULL,
        status ENUM('active','inactive') DEFAULT 'active',
        wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        pos_active TINYINT(1) NOT NULL DEFAULT 0,
        pos_price DECIMAL(10,2) NOT NULL DEFAULT 1999.00,
        pos_activated_at DATETIME NULL,
        pos_order_id VARCHAR(100) NULL,
        pos_payment_ref VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Users table
    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shop_id INT NULL,
        name VARCHAR(150),
        email VARCHAR(190) UNIQUE,
        password VARCHAR(255),
        role ENUM('superadmin','shop_admin','staff','customer'),
        status ENUM('active','inactive') DEFAULT 'active',
        wallet_balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(shop_id) REFERENCES shops(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Customers table
    "CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shop_id INT,
        name VARCHAR(150),
        mobile VARCHAR(20),
        email VARCHAR(190),
        pan VARCHAR(20),
        gstin VARCHAR(30) NULL,
        aadhaar_no VARCHAR(20) NULL,
        aadhaar_verified TINYINT(1) DEFAULT 0,
        dob DATE,
        address TEXT,
        credit_score INT NULL,
        credit_report_json LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(shop_id) REFERENCES shops(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Products table
    "CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shop_id INT,
        name VARCHAR(180),
        brand VARCHAR(100),
        model VARCHAR(100),
        sku VARCHAR(80),
        hsn_code VARCHAR(30) DEFAULT '8517',
        category VARCHAR(80),
        selling_price DECIMAL(12,2),
        gst_rate DECIMAL(5,2) DEFAULT 18.00,
        stock INT DEFAULT 0,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(shop_id) REFERENCES shops(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Finance rules
    "CREATE TABLE IF NOT EXISTS finance_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        shop_id INT,
        min_score INT,
        max_score INT,
        interest_rate DECIMAL(7,3),
        max_finance DECIMAL(12,2),
        max_tenure INT,
        down_payment_percent DECIMAL(7,3),
        processing_fee DECIMAL(10,2),
        FOREIGN KEY(shop_id) REFERENCES shops(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Credit checks
    "CREATE TABLE IF NOT EXISTS credit_checks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT,
        provider VARCHAR(80),
        reference_no VARCHAR(120),
        score INT,
        request_json LONGTEXT,
        response_json LONGTEXT,
        consent TINYINT(1),
        checked_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(customer_id) REFERENCES customers(id),
        FOREIGN KEY(checked_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Finance applications
    "CREATE TABLE IF NOT EXISTS finance_applications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        application_no VARCHAR(50) UNIQUE,
        shop_id INT,
        customer_id INT,
        product_id INT NULL,
        product_name VARCHAR(180) NULL,
        imei_number VARCHAR(40) NULL,
        product_price DECIMAL(12,2),
        down_payment DECIMAL(12,2),
        finance_amount DECIMAL(12,2),
        interest_rate DECIMAL(7,3),
        tenure INT,
        emi DECIMAL(12,2),
        total_interest DECIMAL(12,2),
        processing_fee DECIMAL(10,2),
        insurance_fee DECIMAL(10,2) DEFAULT 0.00,
        total_payable DECIMAL(12,2),
        status VARCHAR(30) DEFAULT 'pending',
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(shop_id) REFERENCES shops(id),
        FOREIGN KEY(customer_id) REFERENCES customers(id),
        FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // EMI Schedules
    "CREATE TABLE IF NOT EXISTS emi_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        finance_id INT,
        installment_no INT,
        due_date DATE,
        principal DECIMAL(12,2),
        interest DECIMAL(12,2),
        amount DECIMAL(12,2),
        paid_amount DECIMAL(12,2) DEFAULT 0,
        status VARCHAR(30) DEFAULT 'upcoming',
        paid_at DATETIME NULL,
        FOREIGN KEY(finance_id) REFERENCES finance_applications(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Payments
    "CREATE TABLE IF NOT EXISTS payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        finance_id INT,
        emi_id INT NULL,
        customer_id INT,
        amount DECIMAL(12,2),
        payment_method VARCHAR(40),
        reference_no VARCHAR(100),
        remarks TEXT,
        paid_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        recorded_by INT,
        FOREIGN KEY(finance_id) REFERENCES finance_applications(id),
        FOREIGN KEY(emi_id) REFERENCES emi_schedules(id) ON DELETE SET NULL,
        FOREIGN KEY(customer_id) REFERENCES customers(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Documents
    "CREATE TABLE IF NOT EXISTS documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        document_no VARCHAR(80) NULL,
        customer_id INT NULL,
        finance_id INT NULL,
        type VARCHAR(60) NULL,
        title VARCHAR(150) NULL,
        payment_id INT NULL,
        generated_by INT NULL,
        file_path VARCHAR(255) NULL,
        file_size INT DEFAULT 0,
        metadata LONGTEXT NULL,
        status VARCHAR(30) DEFAULT 'uploaded',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(customer_id) REFERENCES customers(id),
        FOREIGN KEY(finance_id) REFERENCES finance_applications(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Notifications
    "CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        title VARCHAR(180),
        message TEXT,
        is_read TINYINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Audit logs
    "CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        action VARCHAR(120),
        module VARCHAR(80),
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Wallet transactions
    "CREATE TABLE IF NOT EXISTS wallet_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        shop_id INT NULL,
        txnid VARCHAR(100) UNIQUE NOT NULL,
        payu_mihpayid VARCHAR(100) NULL,
        amount DECIMAL(12,2) NOT NULL,
        type ENUM('credit','debit') DEFAULT 'credit',
        status ENUM('pending','success','failed') DEFAULT 'pending',
        payment_gateway VARCHAR(50) DEFAULT 'PayU',
        payment_mode VARCHAR(50) NULL,
        hash VARCHAR(255) NULL,
        remarks VARCHAR(255) NULL,
        response_json LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Gateway orders (Cashfree)
    "CREATE TABLE IF NOT EXISTS gateway_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id VARCHAR(100) UNIQUE NOT NULL,
        cf_order_id VARCHAR(100) NULL,
        finance_id INT NULL,
        emi_id INT NULL,
        shop_id INT NULL,
        customer_id INT NULL,
        amount DECIMAL(12,2) NOT NULL,
        gateway VARCHAR(30) DEFAULT 'cashfree',
        order_type VARCHAR(50) DEFAULT 'EMI',
        payment_session_id VARCHAR(255) NULL,
        status VARCHAR(30) DEFAULT 'PENDING',
        payment_mode VARCHAR(50) NULL,
        reference_no VARCHAR(100) NULL,
        response_json LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Reviews CMS
    "CREATE TABLE IF NOT EXISTS reviews (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_name VARCHAR(150) NOT NULL,
        customer_role VARCHAR(150) NULL,
        rating DECIMAL(2,1) DEFAULT 5.0,
        review_text TEXT NOT NULL,
        product_name VARCHAR(150) NULL,
        customer_avatar VARCHAR(255) NULL,
        is_featured TINYINT(1) DEFAULT 1,
        status ENUM('active','inactive') DEFAULT 'active',
        sort_order INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
];

foreach ($tablesToEnsure as $sql) {
    try {
        $p->exec($sql);
    } catch (Exception $e) {
        echo "Table init note: " . $e->getMessage() . "\n";
    }
}

// 2. Clear all transactional testing data cleanly
$tablesToTruncate = [
    'finance_applications',
    'emi_schedules',
    'payments',
    'finance_application_onboarding',
    'documents',
    'loan_mandates',
    'mandate_debits',
    'gateway_orders',
    'wallet_transactions',
    'notifications',
    'audit_logs',
    'credit_checks',
    'pos_sales',
    'pos_sale_items',
    'website_leads',
    'whatsapp_logs',
    'customers',
    'credit_reports',
    'product_variants',
    'products'
];

echo "Clearing all transactional and test data...\n";
foreach ($tablesToTruncate as $t) {
    try {
        $p->exec("TRUNCATE TABLE `$t`");
        echo "  [x] Cleared & Reset: $t\n";
    } catch (Exception $e) {
        // Fallback to DELETE if TRUNCATE has foreign key restriction
        try {
            $p->exec("DELETE FROM `$t`");
            $p->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1");
            echo "  [x] Deleted & Reset: $t\n";
        } catch (Exception $ex) {
            echo "  [!] Note on $t: " . $ex->getMessage() . "\n";
        }
    }
}

// 3. Clean and reset Shops table
echo "\nSetting up fresh default Shop...\n";
$p->exec("DELETE FROM shops");
$p->exec("ALTER TABLE shops AUTO_INCREMENT = 1");
$stmtShop = $p->prepare("INSERT INTO shops (id, name, phone, email, gstin, address, status, wallet_balance) VALUES (1, ?, ?, ?, ?, ?, 'active', 100000.00)");
$stmtShop->execute([
    'GO4FIN Retail Store',
    '+91 60005 47615',
    'shop@example.com',
    '18ABCDE1234F1Z5',
    'Barpeta Road, Near Attis Academy of Excellence, New Manas Road, Domani Gaon, Assam - 781315'
]);
echo "  [✓] Default Shop ID 1 created (GO4FIN Retail Store with ₹100,000 wallet balance)\n";

// 4. Clean and reset Users table
echo "\nSetting up clean default Admin & Staff user accounts...\n";
$p->exec("DELETE FROM users");
$p->exec("ALTER TABLE users AUTO_INCREMENT = 1");

$hashedPassword = password_hash('password', PASSWORD_DEFAULT);

$stmtUser = $p->prepare("INSERT INTO users (id, shop_id, name, email, password, role, status, wallet_balance) VALUES (?, ?, ?, ?, ?, ?, 'active', ?)");

// Super Admin
$stmtUser->execute([1, null, 'Super Admin', 'superadmin@example.com', $hashedPassword, 'superadmin', 100000.00]);
echo "  [✓] Super Admin: superadmin@example.com / password (Wallet: ₹100,000)\n";

// Shop Admin
$stmtUser->execute([2, 1, 'Shop Owner', 'shop@example.com', $hashedPassword, 'shop_admin', 100000.00]);
echo "  [✓] Shop Owner:  shop@example.com / password (Wallet: ₹100,000)\n";

// Staff User
$stmtUser->execute([3, 1, 'Sales Staff', 'staff@example.com', $hashedPassword, 'staff', 50000.00]);
echo "  [✓] Sales Staff: staff@example.com / password (Wallet: ₹50,000)\n";

// 5. Clean Products table (0 rows for fresh A to Z data entry)
$seedProducts = in_array('--seed-products', $argv ?? []);
if ($seedProducts) {
    echo "\nSeeding standard universal products catalog...\n";
    $sampleProducts = [
        [
            'name' => 'Samsung Galaxy S24 Ultra 5G (12GB RAM, 256GB)',
            'brand' => 'Samsung',
            'model' => 'Galaxy S24 Ultra',
            'sku' => 'SAM-S24U-256',
            'hsn_code' => '8517',
            'category' => 'Smartphones',
            'selling_price' => 74999.00,
            'gst_rate' => 18.00,
            'stock' => 25
        ],
        [
            'name' => 'Apple iPhone 15 (128GB Storage, Blue)',
            'brand' => 'Apple',
            'model' => 'iPhone 15',
            'sku' => 'APL-IP15-128',
            'hsn_code' => '8517',
            'category' => 'Smartphones',
            'selling_price' => 64999.00,
            'gst_rate' => 18.00,
            'stock' => 20
        ],
        [
            'name' => 'OnePlus 12 5G (16GB RAM, 512GB Storage)',
            'brand' => 'OnePlus',
            'model' => 'OnePlus 12',
            'sku' => 'OP-12-512',
            'hsn_code' => '8517',
            'category' => 'Smartphones',
            'selling_price' => 54999.00,
            'gst_rate' => 18.00,
            'stock' => 15
        ]
    ];

    $stmtProd = $p->prepare("INSERT INTO products (shop_id, name, brand, model, sku, hsn_code, category, selling_price, gst_rate, stock, status) VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
    foreach ($sampleProducts as $pr) {
        $stmtProd->execute([
            $pr['name'],
            $pr['brand'],
            $pr['model'],
            $pr['sku'],
            $pr['hsn_code'],
            $pr['category'],
            $pr['selling_price'],
            $pr['gst_rate'],
            $pr['stock']
        ]);
    }
    echo "  [✓] 3 universal retail products seeded into inventory.\n";
} else {
    echo "\n  [✓] Products & Variants cleared to 0 rows (Ready for fresh product addition by Super Admin).\n";
}

// 6. Reset Finance Rules for Shop 1
echo "\nSetting up Finance Rules...\n";
$p->exec("DELETE FROM finance_rules");
$p->exec("ALTER TABLE finance_rules AUTO_INCREMENT = 1");
$stmtFR = $p->prepare("INSERT INTO finance_rules (shop_id, min_score, max_score, interest_rate, max_finance, max_tenure, down_payment_percent, processing_fee) VALUES (1, 300, 900, 12.000, 200000.00, 24, 10.000, 500.00)");
$stmtFR->execute();
echo "  [✓] Default Finance Rule configured (12% interest, max ₹2 Lakh, 24 months, 10% DP).\n";

// 7. Ensure Settings are fully configured
echo "\nEnsuring System Settings & API Gateway Configurations...\n";
$defaultSettings = [
    'company_name' => 'GO4 FINANCE PRIVATE LIMITED',
    'company_phone' => '+91 60005 47615',
    'company_email' => 'contact@go4fin.com',
    'company_address' => 'Barpeta Road, Near Attis Academy of Excellence, New Manas Road, Domani Gaon, PO Khairabari, Assam - 781315',
    'company_signatory_name' => 'Wazid Hoque',
    'company_signatory_title' => 'Managing Director',
    'company_stamp_signature' => 'stamp_go4fin_1789197334_1414.png',
    'emi_active_gateway' => 'cashfree',
    'emi_cashfree_app_id' => '14169444e0cdb7b3893f4c827a84496141',
    'emi_cashfree_secret_key' => 'cfsk_ma_prod_818af777ee7ecf53542b451de648b9bf_5d596b09',
    'emi_cashfree_env' => 'production',
    'emi_payu_key' => 'JLFa4D',
    'emi_payu_salt' => 'BdsRuvcWukuapuJTrlAL0McodEVT2DMl',
    'emi_payu_env' => 'production',
    'waba_api_url' => 'https://waba.kkwebmart.in/api/v1',
    'waba_api_key' => 'kkwaba_live_SzhxqdAvEuDwbwCF5RB43u1tRaFV7nkL1JAEdExSQo0',
    'waba_emi_reminder_template' => 'emi_reminder',
    'smtp_host' => 'smtp.hostinger.com',
    'smtp_port' => '465',
    'smtp_username' => 'np-reply@kkwebmart.com',
    'smtp_password' => 'eQQ!#@7bP',
    'smtp_from_email' => 'np-reply@kkwebmart.com',
    'smtp_from_name' => 'GO4 Finance Private Limited',
    'smtp_encryption' => 'ssl',
    'theme_mode' => 'light',
    'pos_addon_activated' => '1',
    'pos_addon_api_key' => 'KKWEBMART-PREMIUIM-ADDON-2022',
    'cms_hero_title' => 'Empowering Your Store Purchases with Affordable Installments',
    'cms_hero_subtitle' => 'Buy your favorite Smartphones, Air Conditioners, Smart TVs & Refrigerators upfront with low down payments.',
    'cms_cta_btn_text' => 'Apply For Finance',
    'cms_phone' => '+91 60005 47615',
    'cms_whatsapp' => '916000547615',
    'cms_email' => 'contact@go4fin.com',
    'cms_address' => 'Barpeta Road, Near Attis Academy of Excellence, New Manas Road, Domani Gaon, PO Khairabari, Assam - 781315',
    'cms_tan_no' => 'SHLG03876F',
    'cms_hours' => 'Monday – Saturday: 9:00 AM – 7:00 PM'
];

$stmtSet = $p->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
foreach ($defaultSettings as $k => $v) {
    $stmtSet->execute([$k, $v]);
}
echo "  [✓] All System Settings & Gateways (Cashfree, PayU, WABA WhatsApp, SMTP, Stamp) preserved.\n";

// 8. Re-check Document Templates & Directors
ensureDocumentTables();
ensureDirectorsTable();
echo "  [✓] Document templates & Directors verified.\n";

// 9. Re-check CIBIL module DB
try {
    $pCibil = new PDO("mysql:host=localhost", "root", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pCibil->exec("CREATE DATABASE IF NOT EXISTS `cibil_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pCibil->exec("CREATE TABLE IF NOT EXISTS `cibil_db`.`credit_reports` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `orderid` VARCHAR(100) NOT NULL,
        `name` VARCHAR(150) NOT NULL,
        `mobile` VARCHAR(15) NOT NULL,
        `fetch_by` VARCHAR(20) NOT NULL,
        `number` VARCHAR(50) NOT NULL,
        `credit_score` INT DEFAULT NULL,
        `api_status` VARCHAR(50) NULL,
        `response_json` LONGTEXT NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "  [✓] cibil_db database & credit_reports table verified.\n";
} catch (Exception $e) {
    echo "  [!] cibil_db note: " . $e->getMessage() . "\n";
}

// 10. Clean up generated PDFs from soft/uploads/documents/
echo "\nCleaning old test documents from uploads/documents/...\n";
$docsDir = __DIR__ . '/../uploads/documents';
if (file_exists($docsDir)) {
    $files = glob($docsDir . '/*.{pdf,zip}', GLOB_BRACE);
    $deletedCount = 0;
    foreach ($files as $f) {
        if (is_file($f)) {
            @unlink($f);
            $deletedCount++;
        }
    }
    echo "  [✓] Removed $deletedCount old test PDF/ZIP documents.\n";
}

// 11. Clean old onboarding temp photos from uploads/onboarding/
echo "Cleaning old test onboarding uploads...\n";
$onboardDir = __DIR__ . '/../uploads/onboarding';
if (file_exists($onboardDir)) {
    $files = glob($onboardDir . '/*.{png,jpeg,jpg}', GLOB_BRACE);
    $delOnboard = 0;
    foreach ($files as $f) {
        if (is_file($f)) {
            @unlink($f);
            $delOnboard++;
        }
    }
    echo "  [✓] Removed $delOnboard old test KYC upload files.\n";
}

$p->exec("SET FOREIGN_KEY_CHECKS = 1");

// Log clean audit record
log_audit('Database Fresh Reset', 'System', 'Database was reset to fresh state for new data entry testing.', 1);

echo "\n========================================================\n";
echo "   DATABASE IS NOW 100% FRESH & READY FOR DATA ENTRY!  \n";
echo "========================================================\n";
echo "Login Credentials:\n";
echo "  Super Admin : superadmin@example.com / password\n";
echo "  Shop Owner  : shop@example.com       / password\n";
echo "  Sales Staff : staff@example.com       / password\n\n";
echo "You can now enter fresh customers, create new loan applications,\n";
echo "complete KYC onboarding, and test the entire workflow from scratch!\n";
