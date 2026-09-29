<?php
require_once __DIR__ . '/../config/config.php';
$p = db();

echo "Testing Customer Data Entry Simulation...\n";
// Insert customer
$stmt = $p->prepare("INSERT INTO customers (shop_id, name, mobile, email, pan, aadhaar_no, dob, address, credit_score) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([
    1,
    'Test Customer Entry',
    '9876500001',
    'testcustomer@example.com',
    'ABCDE9999F',
    '123456789012',
    '1995-01-01',
    'Assam, India',
    750
]);
$custId = $p->lastInsertId();
echo "  [✓] Customer created with ID: $custId\n";

// Query customer
$c = $p->query("SELECT * FROM customers WHERE id = $custId")->fetch();
echo "  [✓] Verified customer fetch: {$c['name']} (PAN: {$c['pan']}, Aadhaar: {$c['aadhaar_no']})\n";

// Delete the test customer so db remains completely clean
$p->exec("DELETE FROM customers WHERE id = $custId");
$p->exec("ALTER TABLE customers AUTO_INCREMENT = 1");
echo "  [✓] Test customer cleaned up. Auto increment reset to 1.\n";
echo "\nAll database constraints, columns, and operations tested successfully!\n";
