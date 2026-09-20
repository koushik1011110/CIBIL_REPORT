<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/document_engine.php';

role('superadmin', 'shop_admin', 'staff');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method. POST required.']);
    exit;
}

$docType = trim($_POST['doc_type'] ?? '');
$rawIds  = $_POST['finance_ids'] ?? [];

if (is_string($rawIds)) {
    $rawIds = explode(',', $rawIds);
}

$financeIds = array_filter(array_map('intval', (array)$rawIds));

if (empty($docType)) {
    echo json_encode(['success' => false, 'message' => 'Please select a document type to generate.']);
    exit;
}

if (empty($financeIds)) {
    echo json_encode(['success' => false, 'message' => 'Please select at least one loan application.']);
    exit;
}

$result = generate_bulk_documents_zip($financeIds, $docType, u()['id'] ?? null);
echo json_encode($result);
exit;
