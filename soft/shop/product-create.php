<?php 
require_once __DIR__.'/../includes/layout.php';

// Only Superadmin can add products to the catalog
if (u()['role'] !== 'superadmin') {
    header('Location: products.php?err=admin_only');
    exit;
}

// Redirect superadmin to the master admin product creation terminal
header('Location: ../admin/product-create.php');
exit;
