<?php
try {
    $host = 'localhost';
    $user = 'root';
    $pass = '';
    $dbname = 'go4fin';

    $pdo = new PDO("mysql:host=$host", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    echo "1. Database '$dbname' created/verified successfully.\n";

    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    $schemaFile = __DIR__ . '/schema.sql';
    if (file_exists($schemaFile)) {
        $sqlContent = file_get_contents($schemaFile);
        $statements = array_filter(array_map('trim', explode(';', $sqlContent)));
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                try {
                    $db->exec($stmt);
                } catch (Exception $ex) {
                    // Ignore table already exists
                }
            }
        }
        echo "2. Base schema tables created.\n";
    }

    require_once __DIR__ . '/seed.php';
    echo "\n3. Seed data populated.\n";

    require_once __DIR__ . '/../includes/onboarding_db_init.php';
    echo "4. Onboarding table & folders initialized successfully.\n";

} catch (Exception $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
}
