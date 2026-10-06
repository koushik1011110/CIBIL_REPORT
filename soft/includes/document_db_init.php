<?php
require_once __DIR__ . '/../config/config.php';

function ensureDocumentTables() {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $p = db();

        // 1. Ensure uploads/documents & uploads/signatures directories exist
        $docUploadDir = __DIR__ . '/../uploads/documents';
        if (!file_exists($docUploadDir)) {
            @mkdir($docUploadDir, 0777, true);
        }
        $sigUploadDir = __DIR__ . '/../uploads/signatures';
        if (!file_exists($sigUploadDir)) {
            @mkdir($sigUploadDir, 0777, true);
        }

        // 2. Ensure documents base table exists
        $p->exec("CREATE TABLE IF NOT EXISTS documents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_id INT NULL,
            finance_id INT NULL,
            type VARCHAR(60) NULL,
            file_path VARCHAR(255) NULL,
            status VARCHAR(30) DEFAULT 'generated',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Helper to check and add columns
        $existingCols = [];
        $colStmt = $p->query("SHOW COLUMNS FROM documents");
        while ($row = $colStmt->fetch(PDO::FETCH_ASSOC)) {
            $existingCols[strtolower($row['Field'])] = true;
        }

        if (!isset($existingCols['document_no'])) {
            $p->exec("ALTER TABLE documents ADD COLUMN document_no VARCHAR(80) NULL AFTER id");
        }
        if (!isset($existingCols['title'])) {
            $p->exec("ALTER TABLE documents ADD COLUMN title VARCHAR(150) NULL AFTER type");
        }
        if (!isset($existingCols['payment_id'])) {
            $p->exec("ALTER TABLE documents ADD COLUMN payment_id INT NULL AFTER finance_id");
        }
        if (!isset($existingCols['generated_by'])) {
            $p->exec("ALTER TABLE documents ADD COLUMN generated_by INT NULL AFTER payment_id");
        }
        if (!isset($existingCols['file_size'])) {
            $p->exec("ALTER TABLE documents ADD COLUMN file_size INT DEFAULT 0 AFTER file_path");
        }
        if (!isset($existingCols['metadata'])) {
            $p->exec("ALTER TABLE documents ADD COLUMN metadata LONGTEXT NULL AFTER file_size");
        }

        // Safe index additions
        try { $p->exec("ALTER TABLE documents ADD INDEX idx_doc_no (document_no)"); } catch(Exception $ex) {}
        try { $p->exec("ALTER TABLE documents ADD INDEX idx_doc_type (type)"); } catch(Exception $ex) {}
        try { $p->exec("ALTER TABLE documents ADD INDEX idx_doc_finance (finance_id)"); } catch(Exception $ex) {}
        try { $p->exec("ALTER TABLE documents ADD INDEX idx_doc_customer (customer_id)"); } catch(Exception $ex) {}

        // 3. Create document_templates table
        $p->exec("CREATE TABLE IF NOT EXISTS document_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            doc_type VARCHAR(60) UNIQUE NOT NULL,
            title VARCHAR(150) NOT NULL,
            description VARCHAR(255) NULL,
            template_content LONGTEXT NULL,
            header_text VARCHAR(255) NULL,
            footer_text VARCHAR(255) NULL,
            authorized_signatory_name VARCHAR(150) NULL,
            authorized_signatory_title VARCHAR(150) NULL,
            authorized_signature_image VARCHAR(255) NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        // Ensure authorized_signature_image column exists if table was created previously
        $tplCols = [];
        $tColStmt = $p->query("SHOW COLUMNS FROM document_templates");
        while ($tr = $tColStmt->fetch(PDO::FETCH_ASSOC)) {
            $tplCols[strtolower($tr['Field'])] = true;
        }
        if (!isset($tplCols['authorized_signature_image'])) {
            try {
                $p->exec("ALTER TABLE document_templates ADD COLUMN authorized_signature_image VARCHAR(255) NULL AFTER authorized_signatory_title");
            } catch(Exception $ex) {}
        }

        // Ensure default signatory settings exist
        if (empty(get_setting('company_signatory_name', ''))) {
            set_setting('company_signatory_name', 'Wazid Hoque');
        }
        if (empty(get_setting('company_signatory_title', ''))) {
            set_setting('company_signatory_title', 'Managing Director');
        }

        // 4. Seed default templates if empty or missing types
        $seedTemplates = [
            [
                'doc_type' => 'loan_application',
                'title' => 'Loan Application Form',
                'description' => 'Comprehensive customer financing application form with applicant KYC, product specs, guarantor, and legal declarations.',
                'header_text' => 'GO4 FINANCE PRIVATE LIMITED · CONSUMER CREDIT FINANCING APPLICATION',
                'footer_text' => 'This application is subject to credit verification and approval by GO4 Finance Private Limited.',
                'sign_name' => 'Wazid Hoque',
                'sign_title' => 'Managing Director',
                'content' => 'I/We hereby declare that all the information and KYC particulars furnished above are true, correct, and complete to the best of my/our knowledge and belief. I/We authorize GO4 Finance Private Limited and its partner store to verify the documents, conduct field inspection, pull credit bureau reports from CRIF/CIBIL, and process this financing application under applicable lending laws.'
            ],
            [
                'doc_type' => 'sanction_letter',
                'title' => 'Loan Sanction Letter',
                'description' => 'Official credit approval letter outlining sanctioned amount, tenure, EMI, and terms of credit.',
                'header_text' => 'GO4 FINANCE PRIVATE LIMITED · CREDIT APPROVAL & SANCTION LETTER',
                'footer_text' => 'This sanction is valid for 30 days from date of issue and is subject to counter sign and down payment clearance.',
                'sign_name' => 'Wazid Hoque',
                'sign_title' => 'Authorized Credit Officer',
                'content' => 'Dear {{customer_name}},\n\nWe are pleased to inform you that your application for consumer durable store financing has been approved by GO4 Finance Private Limited. The credit facility is sanctioned for the purchase of {{product_name}} subject to the terms and repayment conditions specified in this sanction letter. Repayment will commence on {{first_emi_date}} and continue for {{tenure}} consecutive monthly installments.'
            ],
            [
                'doc_type' => 'loan_agreement',
                'title' => 'Loan Agreement / Finance Agreement',
                'description' => 'Legal contract between borrower, retailer, and financier hypothecating product until full repayment.',
                'header_text' => 'GO4 FINANCE PRIVATE LIMITED · CONSUMER DURABLE STORE FINANCING AGREEMENT',
                'footer_text' => 'Computer Generated Legal Contract & e-Signed Loan Agreement · GO4 Finance Private Limited',
                'sign_name' => 'Wazid Hoque',
                'sign_title' => 'Authorized Signatory & Seal',
                'content' => '1. Non-Cash Lending Model: The borrower confirms that this credit is extended solely for the purchase of the specified consumer product from the partner retail store.\n2. Repayment Obligation: Borrower agrees to pay each monthly installment of {{emi_amount}} on or before the due date.\n3. Product Hypothecation: The financed product remains hypothecated to GO4 Finance Private Limited until all EMIs are cleared 100%.'
            ],
            [
                'doc_type' => 'repayment_schedule',
                'title' => 'EMI Repayment Schedule',
                'description' => 'Detailed installment repayment breakdown showing installment amounts, due dates, and payment status.',
                'header_text' => 'GO4 FINANCE PRIVATE LIMITED · MONTHLY INSTALLMENT AMORTIZATION SCHEDULE',
                'footer_text' => 'Payments made after due date are subject to applicable late penalty charges.',
                'sign_name' => 'Wahida Begum',
                'sign_title' => 'Director (Operations)',
                'content' => 'The amortization table below details the scheduled monthly repayment obligations for Loan Application #{{loan_number}}. Each installment consists of scheduled monthly repayments calculated for the {{tenure}}-month tenure. Please ensure timely payments via UPI, AutoPay, or at retail counter on or before each specified due date.'
            ],
            [
                'doc_type' => 'disbursement_letter',
                'title' => 'Disbursement Letter / Statement',
                'description' => 'Confirmation of credit execution, merchant payout settlement, down payment clearance, and loan activation.',
                'header_text' => 'GO4 FINANCE PRIVATE LIMITED · LOAN DISBURSEMENT ADVICE & STATEMENT',
                'footer_text' => 'Disbursement processed under partner retail financing arrangement.',
                'sign_name' => 'Wazid Hoque',
                'sign_title' => 'Managing Director',
                'content' => 'This is to confirm that financing facility under Application #{{loan_number}} has been successfully disbursed to partner store {{shop_name}} on behalf of the borrower {{customer_name}} for the purchase of {{product_name}}. Down payment has been received and verified. The loan account is now fully active.'
            ],
            [
                'doc_type' => 'payment_receipt',
                'title' => 'Payment / EMI Receipt',
                'description' => 'Official money receipt for down payment, monthly EMI installments, or settlement collections.',
                'header_text' => 'GO4 FINANCE PRIVATE LIMITED · OFFICIAL PAYMENT ACKNOWLEDGEMENT RECEIPT',
                'footer_text' => 'Receipt generated digitally. Valid without physical signature if verified with Transaction Reference.',
                'sign_name' => 'Cashier / Store Executive',
                'sign_title' => 'Authorized Cashier',
                'content' => 'Received with thanks from {{customer_name}} the sum specified towards Loan Application #{{loan_number}}. The amount has been credited to the customer account.'
            ],
            [
                'doc_type' => 'account_statement',
                'title' => 'Customer Account Statement (SOA)',
                'description' => 'Comprehensive chronological statement of loan account debits, credits, installments, and running balance.',
                'header_text' => 'GO4 FINANCE PRIVATE LIMITED · COMPREHENSIVE LOAN STATEMENT OF ACCOUNT (SOA)',
                'footer_text' => 'If you notice any discrepancy in this statement, please contact contact@go4fin.com within 7 days.',
                'sign_name' => 'Wahida Begum',
                'sign_title' => 'Accounts & Operations Head',
                'content' => 'Chronological statement of account for Loan Account #{{loan_number}} covering all financial transactions, charges, and repayments from origination to present date.'
            ],
            [
                'doc_type' => 'outstanding_statement',
                'title' => 'Outstanding Statement',
                'description' => 'Current snapshot of pending installments, overdue amounts, cleared payments, and balance dues.',
                'header_text' => 'GO4 FINANCE PRIVATE LIMITED · STATEMENT OF OUTSTANDING DUES & BALANCES',
                'footer_text' => 'Outstanding balance calculated as per system records as on statement generation date.',
                'sign_name' => 'Wazid Hoque',
                'sign_title' => 'Credit & Collections Head',
                'content' => 'This statement summarizes the outstanding financial liabilities for {{customer_name}} under Loan Application #{{loan_number}} as of {{document_date}}. All upcoming and overdue dues are itemized below.'
            ],
            [
                'doc_type' => 'foreclosure_statement',
                'title' => 'Foreclosure Statement',
                'description' => 'Pre-closure settlement calculation statement showing net payable to achieve 100% debt-free closure.',
                'header_text' => 'GO4 FINANCE PRIVATE LIMITED · LOAN FORECLOSURE & PRE-CLOSURE SETTLEMENT STATEMENT',
                'footer_text' => 'Foreclosure settlement quote is valid up to the specified validity date.',
                'sign_name' => 'Wazid Hoque',
                'sign_title' => 'Managing Director',
                'content' => 'Upon receipt of borrower pre-closure request, this Foreclosure Statement outlines the total net settlement amount required to achieve 100% full closure and discharge of Loan Account #{{loan_number}}. Upon receipt of this settlement amount, all hypothecation on the financed product will be extinguished and an official NOC issued.'
            ],
            [
                'doc_type' => 'noc_certificate',
                'title' => 'NOC / No-Due Certificate',
                'description' => 'Official certificate certifying 100% EMI repayment, zero balance, and release of product hypothecation.',
                'header_text' => 'GO4 FINANCE PRIVATE LIMITED · NO OBJECTION CERTIFICATE (NOC)',
                'footer_text' => 'This No Objection Certificate is digitally generated and legally binding · Registered in Assam, India',
                'sign_name' => 'Wazid Hoque',
                'sign_title' => 'Authorized Signatory',
                'content' => 'This is to certify that {{customer_name}} (PAN: {{customer_pan}}, Mobile: {{customer_mobile}}) has successfully repaid all financial dues and installments towards Finance Application No {{loan_number}} for the purchase of {{product_name}} from retail partner {{shop_name}}.\n\nAs of {{document_date}}, there are ZERO OUTSTANDING DUES (₹0.00) remaining against this financing account. GO4 Finance Private Limited hereby releases and discharges all hypothecation, lien, and charges on the financed product.'
            ],
            [
                'doc_type' => 'loan_closure',
                'title' => 'Loan Closure Certificate',
                'description' => 'Formal certificate certifying permanent closure of the loan account with all obligations satisfied.',
                'header_text' => 'GO4 FINANCE PRIVATE LIMITED · OFFICIAL CERTIFICATE OF LOAN CLOSURE',
                'footer_text' => 'The loan account is permanently closed in GO4 Finance Private Limited records.',
                'sign_name' => 'Wazid Hoque',
                'sign_title' => 'Managing Director',
                'content' => 'This is to formally certify that Loan Account #{{loan_number}} in the name of {{customer_name}} has reached successful maturity and complete repayment. All repayment obligations and charges have been satisfied in full. The loan account stands officially CLOSED in our registry and credit reporting files.'
            ]
        ];

        $insStmt = $p->prepare("INSERT INTO document_templates (doc_type, title, description, template_content, header_text, footer_text, authorized_signatory_name, authorized_signatory_title) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?) 
            ON DUPLICATE KEY UPDATE 
                title=VALUES(title), 
                description=VALUES(description),
                header_text=COALESCE(header_text, VALUES(header_text)),
                footer_text=COALESCE(footer_text, VALUES(footer_text)),
                authorized_signatory_name=COALESCE(authorized_signatory_name, VALUES(authorized_signatory_name)),
                authorized_signatory_title=COALESCE(authorized_signatory_title, VALUES(authorized_signatory_title))");

        foreach ($seedTemplates as $t) {
            $insStmt->execute([
                $t['doc_type'],
                $t['title'],
                $t['description'],
                $t['content'],
                $t['header_text'],
                $t['footer_text'],
                $t['sign_name'],
                $t['sign_title']
            ]);
        }

    } catch (Exception $e) {
        error_log("Document Tables Init Error: " . $e->getMessage());
    }
}

// Automatically ensure tables on load
ensureDocumentTables();
