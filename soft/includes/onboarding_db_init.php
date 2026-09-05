<?php
require_once __DIR__ . '/../config/config.php';

function ensureOnboardingTable() {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $p = db();
        $sql = "CREATE TABLE IF NOT EXISTS finance_application_onboarding (
            id INT AUTO_INCREMENT PRIMARY KEY,
            finance_id INT NOT NULL UNIQUE,
            full_name VARCHAR(190) NULL,
            father_name VARCHAR(190) NULL,
            dob DATE NULL,
            gender VARCHAR(20) NULL,
            mobile VARCHAR(20) NULL,
            alternate_mobile VARCHAR(20) NULL,
            email VARCHAR(190) NULL,
            address TEXT NULL,
            city VARCHAR(100) NULL,
            state VARCHAR(100) NULL,
            pincode VARCHAR(20) NULL,
            aadhaar_no VARCHAR(20) NULL,
            qualification VARCHAR(100) NULL,
            occupation VARCHAR(100) NULL,
            monthly_income DECIMAL(12,2) DEFAULT 0,
            client_photo VARCHAR(255) NULL,
            client_signature VARCHAR(255) NULL,
            pan_front VARCHAR(255) NULL,
            pan_back VARCHAR(255) NULL,
            aadhaar_front VARCHAR(255) NULL,
            aadhaar_back VARCHAR(255) NULL,
            witness_name VARCHAR(150) NULL,
            witness_mobile VARCHAR(20) NULL,
            witness_photo VARCHAR(255) NULL,
            witness_signature VARCHAR(255) NULL,
            witness_pan_front VARCHAR(255) NULL,
            witness_pan_back VARCHAR(255) NULL,
            bank_name VARCHAR(150) NULL,
            account_holder VARCHAR(150) NULL,
            account_no VARCHAR(50) NULL,
            ifsc_code VARCHAR(30) NULL,
            account_type VARCHAR(30) NULL,
            mandate_mode VARCHAR(50) NULL,
            mandate_status VARCHAR(30) DEFAULT 'submitted',
            onboarding_status VARCHAR(30) DEFAULT 'in_progress',
            completed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (finance_id) REFERENCES finance_applications(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $p->exec($sql);

        // Ensure new columns exist if table was previously created
        try {
            $p->exec("ALTER TABLE finance_application_onboarding ADD COLUMN father_name VARCHAR(190) NULL AFTER full_name");
        } catch (Exception $ex) {}
        try {
            $p->exec("ALTER TABLE finance_application_onboarding ADD COLUMN alternate_mobile VARCHAR(20) NULL AFTER mobile");
        } catch (Exception $ex) {}
        try {
            $p->exec("ALTER TABLE finance_application_onboarding ADD COLUMN aadhaar_verified TINYINT(1) DEFAULT 0 AFTER aadhaar_no");
        } catch (Exception $ex) {}
        try {
            $p->exec("ALTER TABLE finance_application_onboarding ADD COLUMN verified_aadhaar_name VARCHAR(190) NULL AFTER aadhaar_verified");
        } catch (Exception $ex) {}
        try {
            $p->exec("ALTER TABLE finance_application_onboarding ADD COLUMN current_step INT DEFAULT 1 AFTER onboarding_status");
        } catch (Exception $ex) {}
        try {
            $p->exec("ALTER TABLE customers ADD COLUMN aadhaar_no VARCHAR(20) NULL AFTER pan");
        } catch (Exception $ex) {}
        try {
            $p->exec("ALTER TABLE customers ADD COLUMN aadhaar_verified TINYINT(1) DEFAULT 0 AFTER aadhaar_no");
        } catch (Exception $ex) {}
        try {
            $p->exec("ALTER TABLE finance_applications ADD COLUMN product_name VARCHAR(180) NULL AFTER product_id");
        } catch (Exception $ex) {}


        try {
            $p->exec("ALTER TABLE products ADD COLUMN hsn_code VARCHAR(30) DEFAULT '8517' AFTER sku");
        } catch (Exception $ex) {}
        try {
            $p->exec("ALTER TABLE products ADD COLUMN gst_rate DECIMAL(5,2) DEFAULT 18.00 AFTER selling_price");
        } catch (Exception $ex) {}
        try {
            $p->exec("ALTER TABLE shops ADD COLUMN gstin VARCHAR(30) NULL AFTER email");
        } catch (Exception $ex) {}
        try {
            $p->exec("ALTER TABLE shops ADD COLUMN address TEXT NULL AFTER gstin");
        } catch (Exception $ex) {}
        try {
            $p->exec("ALTER TABLE shops ADD COLUMN logo VARCHAR(255) NULL AFTER address");
        } catch (Exception $ex) {}
        try {
            $p->exec("ALTER TABLE customers ADD COLUMN gstin VARCHAR(30) NULL AFTER pan");
        } catch (Exception $ex) {}

        // Create pos_sales & pos_sale_items tables
        try {
            $p->exec("CREATE TABLE IF NOT EXISTS pos_sales (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invoice_no VARCHAR(50) UNIQUE,
                shop_id INT NOT NULL,
                customer_id INT NULL,
                customer_name VARCHAR(150) NOT NULL,
                customer_mobile VARCHAR(20) NOT NULL,
                customer_gstin VARCHAR(30) NULL,
                tax_type ENUM('intra_state', 'inter_state') DEFAULT 'intra_state',
                payment_method VARCHAR(30) DEFAULT 'cash',
                subtotal DECIMAL(12,2) DEFAULT 0.00,
                discount DECIMAL(12,2) DEFAULT 0.00,
                taxable_amount DECIMAL(12,2) DEFAULT 0.00,
                cgst_amount DECIMAL(12,2) DEFAULT 0.00,
                sgst_amount DECIMAL(12,2) DEFAULT 0.00,
                igst_amount DECIMAL(12,2) DEFAULT 0.00,
                total_gst DECIMAL(12,2) DEFAULT 0.00,
                grand_total DECIMAL(12,2) DEFAULT 0.00,
                notes TEXT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $ex) {}

        try {
            $p->exec("CREATE TABLE IF NOT EXISTS pos_sale_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pos_sale_id INT NOT NULL,
                product_id INT NULL,
                product_name VARCHAR(180) NOT NULL,
                hsn_code VARCHAR(30) DEFAULT '8517',
                quantity INT DEFAULT 1,
                unit_price DECIMAL(12,2) DEFAULT 0.00,
                gst_rate DECIMAL(5,2) DEFAULT 18.00,
                taxable_amount DECIMAL(12,2) DEFAULT 0.00,
                gst_amount DECIMAL(12,2) DEFAULT 0.00,
                total_amount DECIMAL(12,2) DEFAULT 0.00,
                FOREIGN KEY (pos_sale_id) REFERENCES pos_sales(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $ex) {}

        // Create website_leads table
        try {
            $p->exec("CREATE TABLE IF NOT EXISTS website_leads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(190) NOT NULL,
                mobile VARCHAR(20) NOT NULL,
                email VARCHAR(190) NULL,
                product VARCHAR(150) NOT NULL,
                price DECIMAL(12,2) DEFAULT 0,
                down_payment DECIMAL(12,2) DEFAULT 0,
                tenure INT DEFAULT 6,
                status VARCHAR(30) DEFAULT 'new',
                remarks TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $ex) {}

        // Create product_variants table
        try {
            $p->exec("CREATE TABLE IF NOT EXISTS product_variants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                product_id INT NOT NULL,
                variant_name VARCHAR(150) NOT NULL,
                sku VARCHAR(100) NULL,
                price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                stock INT DEFAULT 10,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        } catch (Exception $ex) {}

        // Create upload directory if not exists

        $uploadDir = __DIR__ . '/../uploads/onboarding';
        if (!file_exists($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }
    } catch (Exception $e) {
        // Table created or error handled silently
    }
}
ensureOnboardingTable();
