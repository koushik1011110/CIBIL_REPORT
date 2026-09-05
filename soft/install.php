<?php require_once __DIR__.'/config/config.php';db()->exec(file_get_contents(__DIR__.'/database/schema.sql'));echo 'Schema installed. Run database/seed.php once, then delete install.php.';
