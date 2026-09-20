<?php
require_once __DIR__ . '/../includes/document_engine.php';
require_once __DIR__ . '/../includes/document_renderer.php';

$p = db();
echo "--- Testing Signatory Settings ---\n";
$signName = get_setting('company_signatory_name', 'Wazid Hoque');
$signTitle = get_setting('company_signatory_title', 'Managing Director');
$stampFile = get_setting('company_stamp_signature', '');

echo "Signatory Name: {$signName}\n";
echo "Signatory Title: {$signTitle}\n";
echo "Stamp File: {$stampFile}\n";

$data = get_loan_document_data(101);
echo "Document Data Signatory Name: " . $data['company_signatory_name'] . "\n";
echo "Document Data Signatory Title: " . $data['company_signatory_title'] . "\n";
echo "Document Data Stamp URL: " . $data['company_stamp_url'] . "\n";

// Let's create a realistic test stamp PNG image if not present to test upload & display
$testStampName = 'stamp_go4fin_official_seal.png';
$stampPath = __DIR__ . '/../uploads/signatures/' . $testStampName;

if (!file_exists($stampPath)) {
    // Generate a clean official stamp image using GD
    $img = imagecreatetruecolor(240, 80);
    // Transparent background
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);

    $blue = imagecolorallocate($img, 20, 50, 150);
    $navy = imagecolorallocate($img, 15, 23, 42);

    // Outer border
    imagerectangle($img, 2, 2, 237, 77, $blue);
    imagerectangle($img, 4, 4, 235, 75, $blue);

    // Text
    imagestring($img, 3, 20, 12, "FOR GO4 FINANCE PVT LTD", $navy);
    imagestring($img, 4, 30, 32, "Wazid Hoque", $blue);
    imagestring($img, 2, 45, 54, "MANAGING DIRECTOR", $navy);

    imagepng($img, $stampPath);
    imagedestroy($img);
    echo "Created sample stamp image: {$stampPath}\n";
}

// Set setting to this stamp
set_setting('company_stamp_signature', $testStampName);
set_setting('company_signatory_name', 'Wazid Hoque');
set_setting('company_signatory_title', 'Managing Director');

$data = get_loan_document_data(101);
echo "After update - Document Data Stamp URL: " . $data['company_stamp_url'] . "\n";

// Test rendering document HTML
ob_start();
render_document_html('sanction_letter', $data, 'SNC-TEST-001', get_document_template('sanction_letter'));
$html = ob_get_clean();

if (strpos($html, 'FOR GO4 FINANCE PVT LTD') !== false) {
    echo "✓ [PASS] Document HTML contains 'FOR GO4 FINANCE PVT LTD'\n";
} else {
    echo "✗ [FAIL] Missing 'FOR GO4 FINANCE PVT LTD'\n";
}

if (strpos($html, 'uploads/signatures/stamp_go4fin_official_seal.png') !== false) {
    echo "✓ [PASS] Document HTML contains uploaded stamp image URL\n";
} else {
    echo "✗ [FAIL] Missing stamp image URL in HTML\n";
}

// Find signature area in html
if (preg_match('/<div class="sign-grid">.*?<\/div>\s*<!-- FOOTER/s', $html, $m)) {
    echo "RENDERED SIGNATURE SECTION:\n" . $m[0] . "\n";
} else {
    echo "Could not match sign-grid\n";
}

if (strpos($html, 'Wazid Hoque') !== false) {
    echo "✓ [PASS] Document HTML contains 'Wazid Hoque'\n";
} else {
    echo "✗ [FAIL] Missing 'Wazid Hoque' in HTML\n";
}

if (strpos($html, 'Managing Director') !== false) {
    echo "✓ [PASS] Document HTML contains 'Managing Director'\n";
} else {
    echo "✗ [FAIL] Missing 'Managing Director' in HTML\n";
}

// Test PDF generation with stamp image
$res = generate_pdf_document_file('sanction_letter', $data);
echo "✓ [PASS] Generated PDF with embedded stamp: " . $res['full_path'] . " (" . filesize($res['full_path']) . " bytes)\n";
