<?php
require_once __DIR__ . '/../config/config.php';

function ensureDirectorsTable() {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $p = db();
        $sql = "CREATE TABLE IF NOT EXISTS directors (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL,
            designation VARCHAR(150) NOT NULL DEFAULT 'Director',
            photo VARCHAR(255) NULL,
            bio TEXT NULL,
            message TEXT NULL,
            din_no VARCHAR(50) NULL,
            phone VARCHAR(50) NULL,
            email VARCHAR(190) NULL,
            linkedin VARCHAR(255) NULL,
            status ENUM('active', 'inactive') DEFAULT 'active',
            sort_order INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $p->exec($sql);

        // Ensure directors upload directory exists
        $uploadDir = __DIR__ . '/../uploads/directors';
        if (!file_exists($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        // Seed initial directors if empty
        $count = (int)$p->query("SELECT COUNT(*) FROM directors")->fetchColumn();
        if ($count === 0) {
            $stmt = $p->prepare("INSERT INTO directors (name, designation, bio, message, phone, sort_order, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([
                'Wazid Hoque',
                'Founder & Managing Director',
                'Visionary entrepreneur dedicated to bridging retail finance and making consumer electronics easily affordable to millions.',
                'At GO4FIN, our purpose is simple: Empower every Indian household to own essential modern electronics without financial stress or bureaucratic delays.',
                '+91 60005 47615',
                1
            ]);
            $stmt->execute([
                'Hamida Khatun',
                'Director & Co-Founder',
                'Spearheading strategic expansions, corporate partnerships, and retail store merchant network development across Eastern India.',
                'Building sustainable trust with partner merchants and customer families is the bedrock of our sustainable business growth.',
                '+91 91012 59396',
                2
            ]);
            $stmt->execute([
                'Wahida Begum',
                'Executive Director (Operations)',
                'Leading seamless counter approvals, digital KYC compliance, and customer relationship management.',
                'Technology enables speed, but personal empathy delivers true financial inclusion. That is our operational commitment every single day.',
                '+91 99579 41657',
                3
            ]);
        }
    } catch (Exception $e) {
        error_log("Directors Table Init Error: " . $e->getMessage());
    }
}
