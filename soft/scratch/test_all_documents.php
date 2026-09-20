<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/document_engine.php';
require_once __DIR__ . '/../includes/document_renderer.php';

echo "========================================================\n";
echo "       GO4FIN DOCUMENT & PDF AUTOMATION TEST SUITE       \n";
echo "========================================================\n\n";

$allDocTypes = [
    'loan_application',
    'sanction_letter',
    'loan_agreement',
    'repayment_schedule',
    'disbursement_letter',
    'payment_receipt',
    'account_statement',
    'outstanding_statement',
    'foreclosure_statement',
    'noc_certificate',
    'loan_closure'
];

$passCount = 0;
$failCount = 0;

// Test 1: Generate each document for Loan 101 (Active Loan) or Loan 102 (Completed Loan)
echo "--- TEST 1: Individual Document Data & PDF Generation ---\n";

foreach ($allDocTypes as $type) {
    // For NOC & Closure, use completed Loan 102. For others, use active Loan 101.
    $fId = in_array($type, ['noc_certificate', 'loan_closure']) ? 102 : 101;
    
    try {
        $data = get_loan_document_data($fId);
        if (!$data) {
            throw new Exception("Could not load data for finance ID $fId");
        }

        $res = generate_pdf_document_file($type, $data);
        if (!file_exists($res['full_path'])) {
            throw new Exception("PDF file was not created: " . $res['full_path']);
        }

        $size = filesize($res['full_path']);
        if ($size < 500) {
            throw new Exception("PDF file too small ($size bytes)");
        }

        echo "✓ [PASS] $type: Doc #{$res['doc_no']} ({$size} bytes) -> {$res['rel_path']}\n";
        $passCount++;
    } catch (Exception $e) {
        echo "❌ [FAIL] $type: " . $e->getMessage() . "\n";
        $failCount++;
    }
}

// Test 2: Verify NOC restriction on unpaid loan (Loan 101)
echo "\n--- TEST 2: NOC & Closure Lock Enforcement on Unpaid Loan ---\n";
try {
    $data101 = get_loan_document_data(101);
    if ($data101['is_fully_paid']) {
        throw new Exception("Active loan 101 was incorrectly marked as fully paid!");
    }
    echo "✓ [PASS] Loan 101 (4 unpaid EMIs) correctly recognized as NOT fully paid (NOC/Closure locked).\n";
    $passCount++;
} catch (Exception $e) {
    echo "❌ [FAIL] NOC Lock Test: " . $e->getMessage() . "\n";
    $failCount++;
}

// Test 3: Verify NOC qualification on completed loan (Loan 102)
echo "\n--- TEST 3: NOC & Closure Unlocked on 100% Repaid Loan ---\n";
try {
    $data102 = get_loan_document_data(102);
    if (!$data102['is_fully_paid']) {
        throw new Exception("Completed loan 102 was not recognized as fully paid!");
    }
    echo "✓ [PASS] Loan 102 (100% Repaid) correctly recognized as fully paid (NOC/Closure unlocked).\n";
    $passCount++;
} catch (Exception $e) {
    echo "❌ [FAIL] NOC Unlock Test: " . $e->getMessage() . "\n";
    $failCount++;
}

// Test 4: Document History Records in DB
echo "\n--- TEST 4: Document History Registry Verification ---\n";
try {
    $p = db();
    $histCount = (int)$p->query("SELECT COUNT(*) FROM documents")->fetchColumn();
    if ($histCount < 11) {
        throw new Exception("Expected at least 11 recorded documents, found $histCount");
    }
    echo "✓ [PASS] Documents table contains $histCount registered documents with document numbers & file paths.\n";
    $passCount++;
} catch (Exception $e) {
    echo "❌ [FAIL] Document History Test: " . $e->getMessage() . "\n";
    $failCount++;
}

// Test 5: Bulk Generation & ZIP Creation
echo "\n--- TEST 5: Bulk Generation & ZIP Archive Creation ---\n";
try {
    $bulkRes = generate_bulk_documents_zip([101, 102], 'repayment_schedule', 1);
    if (!$bulkRes['success']) {
        throw new Exception("Bulk generation failed: " . ($bulkRes['message'] ?? ''));
    }

    $zipPath = __DIR__ . '/../uploads/documents/' . $bulkRes['zip_name'];
    if (!file_exists($zipPath)) {
        throw new Exception("Bulk ZIP file was not created: $zipPath");
    }

    $zipSize = filesize($zipPath);
    echo "✓ [PASS] Bulk ZIP generated: {$bulkRes['zip_name']} ({$bulkRes['count']} documents, {$zipSize} bytes).\n";
    $passCount++;
} catch (Exception $e) {
    echo "❌ [FAIL] Bulk Generation Test: " . $e->getMessage() . "\n";
    $failCount++;
}

// Test 6: Numbering collision prevention test
echo "\n--- TEST 6: Serialized Collision-Free Numbering ---\n";
try {
    $num1 = generate_document_number('repayment_schedule', 101);
    $num2 = generate_document_number('sanction_letter', 101);
    $num3 = generate_document_number('disbursement_letter', 101);
    
    if (empty($num1) || empty($num2) || $num1 === $num2) {
        throw new Exception("Numbering collided or was empty ($num1 vs $num2)");
    }
    echo "✓ [PASS] Unique Numbers generated: $num1 | $num2 | $num3\n";
    $passCount++;
} catch (Exception $e) {
    echo "❌ [FAIL] Numbering Test: " . $e->getMessage() . "\n";
    $failCount++;
}

echo "\n========================================================\n";
echo "TOTAL TESTS: " . ($passCount + $failCount) . " | PASSED: $passCount | FAILED: $failCount\n";
echo "========================================================\n";
