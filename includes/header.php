<?php
require_once __DIR__ . '/../soft/includes/auth.php';
$themeMode = get_setting('theme_mode', 'light');
$activePage = $pageTitle ?? 'Home';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?=e($activePage)?> · GO4FIN (Go4 Finance Private Limited)</title>
    <link rel="icon" type="image/png" href="assets/images/logo.png">
    
    <!-- Google Fonts & FontAwesome -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="<?=$themeMode === 'light' ? 'light-theme' : ''?>">

    <!-- NAVBAR -->
    <nav class="navbar">
        <div class="nav-container">
            <a href="index.php" class="brand-link">
                <img src="assets/images/logo.png" alt="Go4 Finance" style="height: 44px; width: 44px; border-radius: 50%; object-fit: cover; box-shadow: 0 4px 12px rgba(0,0,0,0.15); background: #fff;">
                <div class="brand-title-box">
                    <span class="brand-title-main">GO4<span>FIN</span></span>
                    <span class="brand-title-sub">Go4 Finance Pvt. Ltd.</span>
                </div>
            </a>

            <ul class="nav-menu" id="navMenu">
                <li><a href="index.php" class="nav-link <?=$activePage==='Home'?'active':''?>">Home</a></li>
                <li><a href="services.php" class="nav-link <?=$activePage==='Services'?'active':''?>">Products & Services</a></li>
                <li><a href="calculator.php" class="nav-link <?=$activePage==='EMI Calculator'?'active':''?>">EMI Calculator</a></li>
                <li><a href="about.php" class="nav-link <?=$activePage==='About Us'?'active':''?>">About Us</a></li>
                <li><a href="contact.php" class="nav-link <?=$activePage==='Contact'?'active':''?>">Contact</a></li>
                <li><a href="apply.php" class="nav-link <?=$activePage==='Apply'?'active':''?>">Apply Installment</a></li>
            </ul>

            <div class="nav-actions">
                <a href="soft/login.php" class="btn-cta btn-outline"><i class="fa-solid fa-right-to-bracket"></i> <span>Portal Login</span></a>
                <a href="apply.php" class="btn-cta btn-primary"><i class="fa-solid fa-paper-plane"></i> <span>Apply Now</span></a>
                
                <button class="hamburger-btn" id="hamburgerBtn" aria-label="Toggle Navigation">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>
        </div>
    </nav>
