-- ==============================================================================
-- GO4FIN COMPLETE DATABASE SCHEMA (MySQL 8.0 / MariaDB 10.4+)
-- Certified Consumer Credit & Retail Financing ERP
-- ==============================================================================

CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) UNIQUE NOT NULL,
    `setting_value` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `shops` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150),
    `phone` VARCHAR(30),
    `email` VARCHAR(190),
    `gstin` VARCHAR(30) NULL,
    `address` TEXT NULL,
    `logo` VARCHAR(255) NULL,
    `status` ENUM('active','inactive') DEFAULT 'active',
    `wallet_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `pos_active` TINYINT(1) NOT NULL DEFAULT 0,
    `pos_price` DECIMAL(10,2) NOT NULL DEFAULT 1999.00,
    `pos_activated_at` DATETIME NULL,
    `pos_order_id` VARCHAR(100) NULL,
    `pos_payment_ref` VARCHAR(100) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `shop_id` INT NULL,
    `name` VARCHAR(150),
    `email` VARCHAR(190) UNIQUE,
    `password` VARCHAR(255),
    `role` ENUM('superadmin','shop_admin','staff','customer'),
    `status` ENUM('active','inactive') DEFAULT 'active',
    `wallet_balance` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `customers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `shop_id` INT,
    `name` VARCHAR(150),
    `mobile` VARCHAR(20),
    `email` VARCHAR(190),
    `pan` VARCHAR(20),
    `gstin` VARCHAR(30) NULL,
    `aadhaar_no` VARCHAR(20) NULL,
    `aadhaar_verified` TINYINT(1) DEFAULT 0,
    `dob` DATE,
    `address` TEXT,
    `credit_score` INT NULL,
    `credit_report_json` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `products` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `shop_id` INT,
    `name` VARCHAR(180),
    `brand` VARCHAR(100),
    `model` VARCHAR(100),
    `sku` VARCHAR(80),
    `hsn_code` VARCHAR(30) DEFAULT '8517',
    `category` VARCHAR(80),
    `selling_price` DECIMAL(12,2),
    `gst_rate` DECIMAL(5,2) DEFAULT 18.00,
    `stock` INT DEFAULT 0,
    `status` ENUM('active','inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `product_variants` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `product_id` INT NOT NULL,
    `variant_name` VARCHAR(150) NOT NULL,
    `sku` VARCHAR(100) NULL,
    `price` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `stock` INT DEFAULT 10,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `finance_rules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `shop_id` INT,
    `min_score` INT,
    `max_score` INT,
    `interest_rate` DECIMAL(7,3),
    `max_finance` DECIMAL(12,2),
    `max_tenure` INT,
    `down_payment_percent` DECIMAL(7,3),
    `processing_fee` DECIMAL(10,2),
    FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `credit_checks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT,
    `provider` VARCHAR(80),
    `reference_no` VARCHAR(120),
    `score` INT,
    `request_json` LONGTEXT,
    `response_json` LONGTEXT,
    `consent` TINYINT(1),
    `checked_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
    FOREIGN KEY (`checked_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `finance_applications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `application_no` VARCHAR(50) UNIQUE,
    `shop_id` INT,
    `customer_id` INT,
    `product_id` INT NULL,
    `product_name` VARCHAR(180) NULL,
    `product_price` DECIMAL(12,2),
    `down_payment` DECIMAL(12,2),
    `finance_amount` DECIMAL(12,2),
    `interest_rate` DECIMAL(7,3),
    `tenure` INT,
    `emi` DECIMAL(12,2),
    `total_interest` DECIMAL(12,2),
    `processing_fee` DECIMAL(10,2),
    `total_payable` DECIMAL(12,2),
    `status` VARCHAR(30) DEFAULT 'pending',
    `created_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
    FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `finance_application_onboarding` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `finance_id` INT NOT NULL UNIQUE,
    `full_name` VARCHAR(190) NULL,
    `father_name` VARCHAR(190) NULL,
    `dob` DATE NULL,
    `gender` VARCHAR(20) NULL,
    `mobile` VARCHAR(20) NULL,
    `alternate_mobile` VARCHAR(20) NULL,
    `email` VARCHAR(190) NULL,
    `address` TEXT NULL,
    `city` VARCHAR(100) NULL,
    `state` VARCHAR(100) NULL,
    `pincode` VARCHAR(20) NULL,
    `aadhaar_no` VARCHAR(20) NULL,
    `aadhaar_verified` TINYINT(1) DEFAULT 0,
    `verified_aadhaar_name` VARCHAR(190) NULL,
    `qualification` VARCHAR(100) NULL,
    `occupation` VARCHAR(100) NULL,
    `monthly_income` DECIMAL(12,2) DEFAULT 0,
    `client_photo` VARCHAR(255) NULL,
    `client_signature` VARCHAR(255) NULL,
    `pan_front` VARCHAR(255) NULL,
    `pan_back` VARCHAR(255) NULL,
    `aadhaar_front` VARCHAR(255) NULL,
    `aadhaar_back` VARCHAR(255) NULL,
    `witness_name` VARCHAR(150) NULL,
    `witness_mobile` VARCHAR(20) NULL,
    `witness_photo` VARCHAR(255) NULL,
    `witness_signature` VARCHAR(255) NULL,
    `witness_pan_front` VARCHAR(255) NULL,
    `witness_pan_back` VARCHAR(255) NULL,
    `bank_name` VARCHAR(150) NULL,
    `account_holder` VARCHAR(150) NULL,
    `account_no` VARCHAR(50) NULL,
    `ifsc_code` VARCHAR(30) NULL,
    `account_type` VARCHAR(30) NULL,
    `mandate_mode` VARCHAR(50) NULL,
    `mandate_status` VARCHAR(30) DEFAULT 'submitted',
    `onboarding_status` VARCHAR(30) DEFAULT 'in_progress',
    `current_step` INT DEFAULT 1,
    `completed_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`finance_id`) REFERENCES `finance_applications`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `emi_schedules` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `finance_id` INT,
    `installment_no` INT,
    `due_date` DATE,
    `principal` DECIMAL(12,2),
    `interest` DECIMAL(12,2),
    `amount` DECIMAL(12,2),
    `paid_amount` DECIMAL(12,2) DEFAULT 0,
    `status` VARCHAR(30) DEFAULT 'upcoming',
    `paid_at` DATETIME NULL,
    FOREIGN KEY (`finance_id`) REFERENCES `finance_applications`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `finance_id` INT,
    `emi_id` INT NULL,
    `customer_id` INT,
    `amount` DECIMAL(12,2),
    `payment_method` VARCHAR(40),
    `reference_no` VARCHAR(100),
    `remarks` TEXT,
    `paid_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `recorded_by` INT,
    FOREIGN KEY (`finance_id`) REFERENCES `finance_applications`(`id`),
    FOREIGN KEY (`emi_id`) REFERENCES `emi_schedules`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `documents` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `document_no` VARCHAR(80) NULL,
    `customer_id` INT NULL,
    `finance_id` INT NULL,
    `type` VARCHAR(60) NULL,
    `title` VARCHAR(150) NULL,
    `payment_id` INT NULL,
    `generated_by` INT NULL,
    `file_path` VARCHAR(255) NULL,
    `file_size` INT DEFAULT 0,
    `metadata` LONGTEXT NULL,
    `status` VARCHAR(30) DEFAULT 'uploaded',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`),
    FOREIGN KEY (`finance_id`) REFERENCES `finance_applications`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `document_templates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `doc_type` VARCHAR(60) UNIQUE NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `description` VARCHAR(255) NULL,
    `template_content` LONGTEXT NULL,
    `header_text` VARCHAR(255) NULL,
    `footer_text` VARCHAR(255) NULL,
    `authorized_signatory_name` VARCHAR(150) NULL,
    `authorized_signatory_title` VARCHAR(150) NULL,
    `authorized_signature_image` VARCHAR(255) NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT,
    `title` VARCHAR(180),
    `message` TEXT,
    `is_read` TINYINT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NULL,
    `action` VARCHAR(120),
    `module` VARCHAR(80),
    `description` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `wallet_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `shop_id` INT NULL,
    `txnid` VARCHAR(100) UNIQUE NOT NULL,
    `payu_mihpayid` VARCHAR(100) NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `type` ENUM('credit','debit') DEFAULT 'credit',
    `status` ENUM('pending','success','failed') DEFAULT 'pending',
    `payment_gateway` VARCHAR(50) DEFAULT 'PayU',
    `payment_mode` VARCHAR(50) NULL,
    `hash` VARCHAR(255) NULL,
    `remarks` VARCHAR(255) NULL,
    `response_json` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `gateway_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` VARCHAR(100) UNIQUE NOT NULL,
    `cf_order_id` VARCHAR(100) NULL,
    `finance_id` INT NULL,
    `emi_id` INT NULL,
    `shop_id` INT NULL,
    `customer_id` INT NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `gateway` VARCHAR(30) DEFAULT 'cashfree',
    `order_type` VARCHAR(50) DEFAULT 'EMI',
    `payment_session_id` VARCHAR(255) NULL,
    `status` VARCHAR(30) DEFAULT 'PENDING',
    `payment_mode` VARCHAR(50) NULL,
    `reference_no` VARCHAR(100) NULL,
    `response_json` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `loan_mandates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `mandate_id` VARCHAR(100) NOT NULL UNIQUE,
    `cf_subscription_id` VARCHAR(100) NULL,
    `subscription_session_id` VARCHAR(255) NULL,
    `finance_id` INT NOT NULL,
    `customer_id` INT NOT NULL,
    `plan_name` VARCHAR(150) NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `max_amount` DECIMAL(12,2) NOT NULL,
    `interval_type` VARCHAR(20) DEFAULT 'MONTH',
    `intervals` INT DEFAULT 1,
    `max_cycles` INT DEFAULT 12,
    `auth_mode` VARCHAR(60) DEFAULT 'UPI / e-NACH / Card',
    `is_revocable` TINYINT(1) DEFAULT 0,
    `non_revocable` TINYINT(1) DEFAULT 1,
    `status` VARCHAR(30) DEFAULT 'PENDING',
    `cancellation_reason` TEXT NULL,
    `cancelled_by` VARCHAR(50) NULL,
    `cancelled_at` DATETIME NULL,
    `last_debit_date` DATETIME NULL,
    `next_debit_date` DATETIME NULL,
    `response_json` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_lm_fin` (`finance_id`),
    INDEX `idx_lm_cust` (`customer_id`),
    INDEX `idx_lm_stat` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `mandate_debits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `mandate_id` VARCHAR(100) NOT NULL,
    `finance_id` INT NOT NULL,
    `emi_id` INT NULL,
    `cf_payment_id` VARCHAR(100) NULL,
    `amount` DECIMAL(12,2) NOT NULL,
    `status` VARCHAR(30) DEFAULT 'PENDING',
    `failure_reason` TEXT NULL,
    `scheduled_date` DATE NULL,
    `debited_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_md_man` (`mandate_id`),
    INDEX `idx_md_fin` (`finance_id`),
    INDEX `idx_md_emi` (`emi_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `whatsapp_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT NULL,
    `finance_id` INT NULL,
    `emi_id` INT NULL,
    `mobile` VARCHAR(25) NOT NULL,
    `template_name` VARCHAR(100) NOT NULL,
    `parameters_json` TEXT NULL,
    `response_json` LONGTEXT NULL,
    `message_id` VARCHAR(100) NULL,
    `status` VARCHAR(30) DEFAULT 'SENT',
    `error_message` TEXT NULL,
    `sent_by` VARCHAR(50) DEFAULT 'system',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_wl_cust` (`customer_id`),
    INDEX `idx_wl_fin` (`finance_id`),
    INDEX `idx_wl_emi` (`emi_id`),
    INDEX `idx_wl_stat` (`status`),
    INDEX `idx_wl_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `pos_sales` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `invoice_no` VARCHAR(50) UNIQUE,
    `shop_id` INT NOT NULL,
    `customer_id` INT NULL,
    `customer_name` VARCHAR(150) NOT NULL,
    `customer_mobile` VARCHAR(20) NOT NULL,
    `customer_gstin` VARCHAR(30) NULL,
    `tax_type` ENUM('intra_state', 'inter_state') DEFAULT 'intra_state',
    `payment_method` VARCHAR(30) DEFAULT 'cash',
    `subtotal` DECIMAL(12,2) DEFAULT 0.00,
    `discount` DECIMAL(12,2) DEFAULT 0.00,
    `taxable_amount` DECIMAL(12,2) DEFAULT 0.00,
    `cgst_amount` DECIMAL(12,2) DEFAULT 0.00,
    `sgst_amount` DECIMAL(12,2) DEFAULT 0.00,
    `igst_amount` DECIMAL(12,2) DEFAULT 0.00,
    `total_gst` DECIMAL(12,2) DEFAULT 0.00,
    `grand_total` DECIMAL(12,2) DEFAULT 0.00,
    `notes` TEXT NULL,
    `created_by` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `pos_sale_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `pos_sale_id` INT NOT NULL,
    `product_id` INT NULL,
    `product_name` VARCHAR(180) NOT NULL,
    `hsn_code` VARCHAR(30) DEFAULT '8517',
    `quantity` INT DEFAULT 1,
    `unit_price` DECIMAL(12,2) DEFAULT 0.00,
    `gst_rate` DECIMAL(5,2) DEFAULT 18.00,
    `taxable_amount` DECIMAL(12,2) DEFAULT 0.00,
    `gst_amount` DECIMAL(12,2) DEFAULT 0.00,
    `total_amount` DECIMAL(12,2) DEFAULT 0.00,
    FOREIGN KEY (`pos_sale_id`) REFERENCES `pos_sales`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `website_leads` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(190) NOT NULL,
    `mobile` VARCHAR(20) NOT NULL,
    `email` VARCHAR(190) NULL,
    `product` VARCHAR(150) NOT NULL,
    `price` DECIMAL(12,2) DEFAULT 0,
    `down_payment` DECIMAL(12,2) DEFAULT 0,
    `tenure` INT DEFAULT 6,
    `status` VARCHAR(30) DEFAULT 'new',
    `remarks` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `directors` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `designation` VARCHAR(150) NOT NULL DEFAULT 'Director',
    `photo` VARCHAR(255) NULL,
    `bio` TEXT NULL,
    `message` TEXT NULL,
    `din_no` VARCHAR(50) NULL,
    `phone` VARCHAR(50) NULL,
    `email` VARCHAR(190) NULL,
    `linkedin` VARCHAR(255) NULL,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `sort_order` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `reviews` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `customer_name` VARCHAR(150) NOT NULL,
    `customer_role` VARCHAR(150) NULL,
    `rating` DECIMAL(2,1) DEFAULT 5.0,
    `review_text` TEXT NOT NULL,
    `product_name` VARCHAR(150) NULL,
    `customer_avatar` VARCHAR(255) NULL,
    `is_featured` TINYINT(1) DEFAULT 1,
    `status` ENUM('active','inactive') DEFAULT 'active',
    `sort_order` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
