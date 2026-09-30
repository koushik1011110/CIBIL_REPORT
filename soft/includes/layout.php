<?php require_once __DIR__.'/auth.php'; 
function start($title){
    $x = u();
    $roleName = strtoupper(str_replace('_', ' ', $x['role'] ?? 'USER'));
    $initials = strtoupper(substr($x['name'] ?? 'U', 0, 2));

    // Lightweight live counters for badges
    $badgePendingApps = 0;
    $badgeLeads = 0;
    try {
        $p = db();
        if ($x['role'] === 'superadmin') {
            $badgePendingApps = (int)$p->query("SELECT COUNT(*) FROM finance_applications WHERE status = 'pending'")->fetchColumn();
            $badgeLeads = (int)$p->query("SELECT COUNT(*) FROM website_leads WHERE status = 'new'")->fetchColumn();
        } elseif ($x['role'] === 'shop_admin' || $x['role'] === 'staff') {
            $sId = (int)($x['shop_id'] ?? 0);
            if ($sId > 0) {
                $badgePendingApps = (int)$p->query("SELECT COUNT(*) FROM finance_applications WHERE shop_id = {$sId} AND status = 'pending'")->fetchColumn();
            }
        }

        // Auto-run daily 3-day WhatsApp EMI reminder check once per day for admin/staff
        if (in_array($x['role'] ?? '', ['superadmin', 'shop_admin', 'staff'])) {
            $lastRun = get_setting('last_whatsapp_reminder_cron_date', '');
            if (empty($lastRun) || date('Y-m-d', strtotime($lastRun)) !== date('Y-m-d')) {
                require_once __DIR__ . '/whatsapp.php';
                waba_process_due_reminders(3);
                set_setting('last_whatsapp_reminder_cron_date', date('Y-m-d H:i:s'));
            }
        }
    } catch(Exception $e) {}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?=e($title)?> · GO4FIN (Go4 Finance Private Limited)</title>
    <link rel="icon" type="image/png" href="<?=url('/public/assets/images/logo.png')?>">
    
    <!-- Google Fonts & Lucide Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <link rel="stylesheet" href="<?=url('/public/assets/css/app.css?v='.filemtime(__DIR__.'/../public/assets/css/app.css'))?>">
    <style>
        /* Real Floating Dropdown Navigation Styles */
        .nav-dropdown-btn {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 9px 12px;
            border-radius: var(--radius-sm, 8px);
            background: transparent;
            border: 1px solid transparent;
            color: var(--text-muted, #94a3b8);
            font-size: 0.86rem;
            font-weight: 600;
            cursor: pointer;
            text-align: left;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            user-select: none;
            position: relative;
        }
        .nav-dropdown-btn .icon-box {
            width: 26px;
            height: 26px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.06);
            flex-shrink: 0;
            transition: all 0.2s ease;
        }
        .nav-dropdown-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.06);
            transform: translateX(3px);
        }
        .nav-dropdown-btn.active-dropdown, .nav-dropdown-btn.has-active {
            color: #ffffff !important;
            background: rgba(255, 255, 255, 0.08) !important;
            border-color: rgba(255, 255, 255, 0.16) !important;
        }
        .nav-dropdown-btn.reports-btn .icon-box { background: rgba(16, 185, 129, 0.18); color: #10b981; }
        .nav-dropdown-btn.reports-btn.active-dropdown, .nav-dropdown-btn.reports-btn.has-active {
            border-color: rgba(16, 185, 129, 0.45) !important;
            background: rgba(16, 185, 129, 0.1) !important;
        }
        .nav-dropdown-btn.cms-btn .icon-box { background: rgba(59, 130, 246, 0.18); color: #3b82f6; }
        .nav-dropdown-btn.cms-btn.active-dropdown, .nav-dropdown-btn.cms-btn.has-active {
            border-color: rgba(59, 130, 246, 0.45) !important;
            background: rgba(59, 130, 246, 0.1) !important;
        }
        body.light-theme .nav-dropdown-btn { color: #475569 !important; }
        body.light-theme .nav-dropdown-btn:hover { background: #f1f5f9 !important; color: #0f172a !important; }
        body.light-theme .nav-dropdown-btn.reports-btn.active-dropdown,
        body.light-theme .nav-dropdown-btn.reports-btn.has-active {
            background: #ecfdf5 !important;
            border-color: #a7f3d0 !important;
            color: #065f46 !important;
        }
        body.light-theme .nav-dropdown-btn.cms-btn.active-dropdown,
        body.light-theme .nav-dropdown-btn.cms-btn.has-active {
            background: #eff6ff !important;
            border-color: #bfdbfe !important;
            color: #1e40af !important;
        }

        /* The Floating Dropdown Menu (POPOVER) */
        .real-dropdown-menu {
            position: fixed !important;
            display: none;
            z-index: 999999 !important;
            min-width: 245px;
            max-width: 280px;
            max-height: calc(100vh - 24px);
            overflow-y: auto;
            background: #0f172a !important;
            border: 1px solid rgba(255, 255, 255, 0.18) !important;
            border-radius: 12px !important;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.8), 0 0 0 1px rgba(255, 255, 255, 0.08) !important;
            padding: 8px !important;
        }
        .real-dropdown-menu::-webkit-scrollbar {
            width: 4px;
        }
        .real-dropdown-menu::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 4px;
        }
        .real-dropdown-menu.show {
            display: block !important;
        }
        body.light-theme .real-dropdown-menu {
            background: #ffffff !important;
            border-color: #cbd5e1 !important;
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.18), 0 0 0 1px rgba(0, 0, 0, 0.05) !important;
        }
        .dropdown-header-tag {
            font-size: 0.68rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #94a3b8;
            padding: 6px 10px 8px 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        body.light-theme .dropdown-header-tag {
            border-bottom-color: #e2e8f0 !important;
            color: #64748b !important;
        }
        .real-dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            border-radius: 8px;
            color: #f8fafc !important;
            text-decoration: none;
            font-size: 0.84rem;
            font-weight: 600;
            transition: all 0.15s ease;
        }
        .real-dropdown-item i[data-lucide], .real-dropdown-item svg {
            width: 15px;
            height: 15px;
            opacity: 0.8;
            flex-shrink: 0;
        }
        .real-dropdown-item:hover {
            background: rgba(59, 130, 246, 0.18) !important;
            color: #ffffff !important;
            transform: translateX(4px);
        }
        .real-dropdown-item.active {
            background: rgba(59, 130, 246, 0.28) !important;
            border: 1px solid rgba(59, 130, 246, 0.4) !important;
            color: #60a5fa !important;
            font-weight: 700;
        }
        body.light-theme .real-dropdown-item {
            color: #334155 !important;
        }
        body.light-theme .real-dropdown-item:hover {
            background: #eff6ff !important;
            color: #1d4ed8 !important;
        }
        body.light-theme .real-dropdown-item.active {
            background: #e0e7ff !important;
            border-color: #c7d2fe !important;
            color: #1e40af !important;
        }
    </style>
</head>
<?php $themeMode = get_setting('theme_mode', 'light'); ?>
<body class="<?=$themeMode === 'light' ? 'light-theme' : ''?>">
    <script>
        // Immediately restore sidebar state before rendering to prevent layout shift
        if (localStorage.getItem('go4fin_sidebar_collapsed') === '1' && window.innerWidth > 900) {
            document.body.classList.add('sidebar-collapsed');
        }
    </script>

    <!-- NAVBAR -->
    <header class="navbar">
        <div style="display: flex; align-items: center; gap: 14px;">
            <button type="button" id="sidebarToggleBtn" class="sidebar-toggle-btn" onclick="toggleSidebar()" title="Toggle Sidebar" aria-label="Toggle Sidebar">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="3" y1="12" x2="21" y2="12"></line>
                    <line x1="3" y1="6" x2="21" y2="6"></line>
                    <line x1="3" y1="18" x2="21" y2="18"></line>
                </svg>
            </button>
            <a href="<?=url('/')?>" class="brand">
                <img src="<?=url('/public/assets/images/logo.png')?>" alt="Go4 Finance" style="height: 38px; width: 38px; border-radius: 50%; object-fit: cover; box-shadow: 0 2px 10px rgba(0,0,0,0.15); background: #fff;">
                <div class="brand-name">GO4<span style="color: var(--primary);">FIN</span></div>
                <span class="brand-badge"><?=$roleName?></span>
            </a>
        </div>

        <div class="nav-actions" style="display: flex; align-items: center; gap: 12px;">
            <?php if ($x['role'] === 'superadmin'): ?>
                <a href="<?=url('/admin/menu.php')?>" class="quick-nav-trigger" style="display: none; @media(min-width: 600px){display: inline-flex;}" title="Open Superadmin Menu Hub">
                    <i data-lucide="layout-grid" style="width: 14px; height: 14px; color: var(--primary);"></i>
                    <span>Menu Hub</span>
                </a>
                <button type="button" class="quick-nav-trigger" onclick="openSpotlightSearch()" title="Quick Navigation Search (Ctrl+K)">
                    <i data-lucide="search" style="width: 14px; height: 14px;"></i>
                    <span style="display: none; @media(min-width: 480px){display: inline;}">Quick Search</span>
                    <span class="quick-nav-kbd">Ctrl+K</span>
                </button>
            <?php endif; ?>

            <a href="<?=url('/profile.php')?>" class="user-profile-btn" style="text-decoration:none; cursor:pointer;" title="View & Edit Profile / Shop Logo">
                <div class="avatar"><?=$initials?></div>
                <div class="user-info">
                    <span class="user-name"><?=e($x['name'] ?? 'User')?></span>
                    <span class="user-role-title"><?=$roleName?></span>
                </div>
            </a>
        </div>
    </header>

    <div class="app-wrapper">
        <!-- SIDEBAR NAVIGATION -->
        <aside>
            <nav>
                <?php 
                $base = url($x['role']==='superadmin' ? '/admin' : ($x['role']==='shop_admin' ? '/shop' : ($x['role']==='staff' ? '/staff' : '/customer')));
                $currentScript = basename($_SERVER['PHP_SELF'] ?? '');
                $isActive = function($page) use ($currentScript) {
                    return ($currentScript === $page) ? 'active' : '';
                };
                ?>
                
                <?php if($x['role'] === 'superadmin'): ?>
                    
                    <!-- 1. CORE ERP & CONTROL -->
                    <div class="nav-category-header">
                        <span>Core ERP</span>
                        <i data-lucide="layers" style="width: 12px; height: 12px; opacity: 0.7;"></i>
                    </div>

                    <a href="<?=url('/admin/menu.php')?>" class="<?=$isActive('menu.php')?>" style="color: #60a5fa; font-weight: 700;">
                        <i data-lucide="layout-grid" style="color: #60a5fa;"></i>
                        <span>Menu Hub</span>
                        <span class="nav-badge-pill badge-info">⚡ HUB</span>
                    </a>

                    <a href="<?=url('/admin/dashboard.php')?>" class="<?=$isActive('dashboard.php')?>">
                        <i data-lucide="layout-dashboard"></i>
                        <span>Dashboard</span>
                    </a>
                    
                    <a href="<?=url('/admin/pos.php')?>" class="<?=$isActive('pos.php')?>">
                        <i data-lucide="shopping-cart"></i>
                        <span>POS Terminal</span>
                    </a>

                    <!-- 2. LOANS & OPERATIONS -->
                    <div class="nav-divider"></div>
                    <div class="nav-category-header">
                        <span>Loans & KYC</span>
                        <i data-lucide="file-text" style="width: 12px; height: 12px; opacity: 0.7;"></i>
                    </div>

                    <a href="<?=url('/admin/applications.php')?>" class="<?=$isActive('applications.php')?>">
                        <i data-lucide="file-text"></i>
                        <span>Applications</span>
                        <?php if($badgePendingApps > 0): ?>
                            <span class="nav-badge-pill badge-alert"><?=$badgePendingApps?> New</span>
                        <?php endif; ?>
                    </a>

                    <a href="<?=url('/admin/customers.php')?>" class="<?=$isActive('customers.php')?>">
                        <i data-lucide="users"></i>
                        <span>Customers</span>
                    </a>

                    <a href="<?=url('/admin/credit-check.php')?>" class="<?=$isActive('credit-check.php')?>">
                        <i data-lucide="shield-check"></i>
                        <span>Credit Check</span>
                    </a>

                    <a href="<?=url('/admin/products.php')?>" class="<?=$isActive('products.php')?>">
                        <i data-lucide="package"></i>
                        <span>Products</span>
                    </a>

                    <!-- 3. FINANCE, COLLECTIONS & GST -->
                    <div class="nav-divider"></div>
                    <div class="nav-category-header">
                        <span>Finance & GST</span>
                        <i data-lucide="wallet" style="width: 12px; height: 12px; opacity: 0.7;"></i>
                    </div>

                    <a href="<?=url('/admin/collections.php')?>" class="<?=$isActive('collections.php')?>">
                        <i data-lucide="badge-percent"></i>
                        <span>EMI Collections</span>
                    </a>

                    <a href="<?=url('/admin/documents.php')?>" class="<?=$isActive('documents.php')?>">
                        <i data-lucide="file-check"></i>
                        <span>Loan Documents</span>
                    </a>

                    <a href="<?=url('/admin/wallet.php')?>" class="<?=$isActive('wallet.php')?>">
                        <i data-lucide="credit-card"></i>
                        <span>Wallet PayU</span>
                    </a>

                    <!-- ADVANCE REPORTS & MIS DROPDOWN -->
                    <?php 
                    $isAdvReportsActive = (strpos($_SERVER['REQUEST_URI'] ?? '', 'advance-reports.php') !== false); 
                    ?>
                    <button type="button" id="advReportsNavBtn" class="nav-dropdown-btn reports-btn <?=$isAdvReportsActive ? 'has-active' : ''?>" onclick="toggleRealDropdown(event, 'advReportsDropdown')" title="Click for Advance MIS Reports Package">
                        <span style="display: flex; align-items: center; gap: 10px;">
                            <span class="icon-box" style="background: rgba(139, 92, 246, 0.18); color: #8b5cf6;"><i data-lucide="trending-up" style="width: 15px; height: 15px;"></i></span>
                            <span style="font-weight: 700;">Advance Reports</span>
                        </span>
                        <i data-lucide="chevron-right" class="dropdown-arrow-icon" style="width: 14px; height: 14px; transition: transform 0.2s;"></i>
                    </button>

                    <!-- REPORTS & GST RETURNS DROPDOWN -->
                    <?php 
                    $isReportsActive = (strpos($_SERVER['REQUEST_URI'] ?? '', 'reports.php') !== false && strpos($_SERVER['REQUEST_URI'] ?? '', 'advance-reports.php') === false); 
                    ?>
                    <button type="button" id="reportsNavBtn" class="nav-dropdown-btn reports-btn <?=$isReportsActive ? 'has-active' : ''?>" onclick="toggleRealDropdown(event, 'reportsDropdown')" title="Click for Reports & GST dropdown">
                        <span style="display: flex; align-items: center; gap: 10px;">
                            <span class="icon-box"><i data-lucide="bar-chart-3" style="width: 15px; height: 15px;"></i></span>
                            <span style="font-weight: 700;">Reports & GST</span>
                        </span>
                        <i data-lucide="chevron-right" class="dropdown-arrow-icon" style="width: 14px; height: 14px; transition: transform 0.2s;"></i>
                    </button>

                    <!-- 4. MERCHANT NETWORK -->
                    <div class="nav-divider"></div>
                    <div class="nav-category-header">
                        <span>Partner Network</span>
                        <i data-lucide="store" style="width: 12px; height: 12px; opacity: 0.7;"></i>
                    </div>

                    <a href="<?=url('/admin/shops.php')?>" class="<?=$isActive('shops.php')?>">
                        <i data-lucide="store"></i>
                        <span>Merchant Shops</span>
                    </a>

                    <a href="<?=url('/admin/users.php')?>" class="<?=$isActive('users.php')?>">
                        <i data-lucide="user-check"></i>
                        <span>User Accounts</span>
                    </a>

                    <a href="<?=url('/admin/communication.php')?>" class="<?=$isActive('communication.php')?>">
                        <i data-lucide="send"></i>
                        <span>SMS & Messaging</span>
                    </a>

                    <!-- 5. MARKETING & FRONTEND CMS -->
                    <div class="nav-divider"></div>
                    <div class="nav-category-header">
                        <span>Marketing & CMS</span>
                        <i data-lucide="globe" style="width: 12px; height: 12px; opacity: 0.7;"></i>
                    </div>

                    <a href="<?=url('/admin/website-leads.php')?>" class="<?=$isActive('website-leads.php')?>">
                        <i data-lucide="inbox"></i>
                        <span>Website Leads</span>
                        <?php if($badgeLeads > 0): ?>
                            <span class="nav-badge-pill badge-alert"><?=$badgeLeads?> Leads</span>
                        <?php endif; ?>
                    </a>

                    <!-- FRONTEND CMS DROPDOWN -->
                    <?php 
                    $isCmsActive = (strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/cms-') !== false); 
                    ?>
                    <button type="button" id="cmsNavBtn" class="nav-dropdown-btn cms-btn <?=$isCmsActive ? 'has-active' : ''?>" onclick="toggleRealDropdown(event, 'cmsDropdown')" title="Click for Website CMS dropdown">
                        <span style="display: flex; align-items: center; gap: 10px;">
                            <span class="icon-box"><i data-lucide="globe" style="width: 15px; height: 15px;"></i></span>
                            <span style="font-weight: 700;">Website CMS</span>
                        </span>
                        <i data-lucide="chevron-right" class="dropdown-arrow-icon" style="width: 14px; height: 14px; transition: transform 0.2s;"></i>
                    </button>

                    <!-- 6. SYSTEM CONFIGURATION -->
                    <div class="nav-divider"></div>
                    <div class="nav-category-header">
                        <span>System Config</span>
                        <i data-lucide="sliders" style="width: 12px; height: 12px; opacity: 0.7;"></i>
                    </div>

                    <a href="<?=url('/admin/document-templates.php')?>" class="<?=$isActive('document-templates.php')?>">
                        <i data-lucide="file-cog"></i>
                        <span>Templates & Stamp</span>
                    </a>

                    <a href="<?=url('/admin/settings.php')?>" class="<?=$isActive('settings.php')?>">
                        <i data-lucide="sliders"></i>
                        <span>System Settings</span>
                    </a>

                <?php elseif($x['role'] === 'shop_admin' || $x['role'] === 'staff'): ?>
                    
                    <!-- SHOP ADMIN & STAFF NAVIGATION -->
                    <div class="nav-category-header">
                        <span>Store Terminal</span>
                        <i data-lucide="store" style="width: 12px; height: 12px; opacity: 0.7;"></i>
                    </div>

                    <a href="<?=$base?>/dashboard.php" class="<?=$isActive('dashboard.php')?>"><i data-lucide="layout-dashboard"></i> Dashboard</a>
                    <?php 
                    $isNavPosActive = is_pos_unlocked($x['shop_id'] ?? 0, $x);
                    ?>
                    <a href="<?=$base?>/pos.php" class="<?=$isActive('pos.php')?>">
                        <i data-lucide="<?=$isNavPosActive ? 'shopping-cart' : 'lock'?>"></i>
                        <span>POS Terminal</span>
                        <?php if (!$isNavPosActive): ?>
                            <span class="nav-badge-pill" style="background: rgba(245,158,11,0.2); color: #f59e0b; border: 1px solid rgba(245,158,11,0.4); font-size: 0.65rem; padding: 2px 6px; font-weight: 700;">🔒 ₹1999</span>
                        <?php else: ?>
                            <span class="nav-badge-pill" style="background: rgba(16,185,129,0.2); color: #10b981; border: 1px solid rgba(16,185,129,0.4); font-size: 0.65rem; padding: 2px 6px; font-weight: 700;">ACTIVE</span>
                        <?php endif; ?>
                    </a>
                    
                    <div class="nav-divider"></div>
                    <div class="nav-category-header"><span>Loans & Customers</span></div>

                    <a href="<?=$base?>/applications.php" class="<?=$isActive('applications.php')?>">
                        <i data-lucide="file-text"></i>
                        <span>Applications</span>
                        <?php if($badgePendingApps > 0): ?>
                            <span class="nav-badge-pill badge-alert"><?=$badgePendingApps?> Pending</span>
                        <?php endif; ?>
                    </a>
                    <a href="<?=$base?>/customers.php" class="<?=$isActive('customers.php')?>"><i data-lucide="users"></i> Customers</a>
                    <a href="<?=$base?>/credit-check.php" class="<?=$isActive('credit-check.php')?>"><i data-lucide="shield-check"></i> Credit Check</a>
                    <a href="<?=$base?>/products.php" class="<?=$isActive('products.php')?>"><i data-lucide="package"></i> Products</a>

                    <div class="nav-divider"></div>
                    <div class="nav-category-header"><span>Finance & Billing</span></div>

                    <a href="<?=$base?>/collections.php" class="<?=$isActive('collections.php')?>"><i data-lucide="wallet"></i> Collections</a>
                    <a href="<?=$base?>/documents.php" class="<?=$isActive('documents.php')?>"><i data-lucide="file-check"></i> Documents</a>
                    <a href="<?=$base?>/wallet.php" class="<?=$isActive('wallet.php')?>"><i data-lucide="credit-card"></i> Wallet PayU</a>

                    <!-- SHOP ADVANCE REPORTS DROPDOWN -->
                    <?php 
                    $isShopAdvActive = (strpos($_SERVER['REQUEST_URI'] ?? '', 'advance-reports.php') !== false); 
                    ?>
                    <button type="button" id="shopAdvNavBtn" class="nav-dropdown-btn reports-btn <?=$isShopAdvActive ? 'has-active' : ''?>" onclick="toggleRealDropdown(event, 'shopAdvReportsDropdown')" title="Click for Advance MIS Reports Package">
                        <span style="display: flex; align-items: center; gap: 10px;">
                            <span class="icon-box" style="background: rgba(139, 92, 246, 0.18); color: #8b5cf6;"><i data-lucide="trending-up" style="width: 15px; height: 15px;"></i></span>
                            <span style="font-weight: 700;">Advance Reports</span>
                        </span>
                        <i data-lucide="chevron-right" class="dropdown-arrow-icon" style="width: 14px; height: 14px; transition: transform 0.2s;"></i>
                    </button>

                    <!-- SHOP REPORTS DROPDOWN -->
                    <?php 
                    $isShopReportsActive = (strpos($_SERVER['REQUEST_URI'] ?? '', 'reports.php') !== false && strpos($_SERVER['REQUEST_URI'] ?? '', 'advance-reports.php') === false); 
                    ?>
                    <button type="button" id="shopReportsNavBtn" class="nav-dropdown-btn reports-btn <?=$isShopReportsActive ? 'has-active' : ''?>" onclick="toggleRealDropdown(event, 'shopReportsDropdown')" title="Click for Reports & GST dropdown">
                        <span style="display: flex; align-items: center; gap: 10px;">
                            <span class="icon-box"><i data-lucide="bar-chart-3" style="width: 15px; height: 15px;"></i></span>
                            <span style="font-weight: 700;">Reports & GST</span>
                        </span>
                        <i data-lucide="chevron-right" class="dropdown-arrow-icon" style="width: 14px; height: 14px; transition: transform 0.2s;"></i>
                    </button>

                    <a href="<?=$base?>/communication.php" class="<?=$isActive('communication.php')?>"><i data-lucide="send"></i> SMS Messaging</a>

                <?php else: ?>

                    <!-- CUSTOMER BORROWER PORTAL -->
                    <div class="nav-category-header">
                        <span>My Account</span>
                        <i data-lucide="user" style="width: 12px; height: 12px; opacity: 0.7;"></i>
                    </div>

                    <a href="<?=url('/customer/finance.php')?>" class="<?=$isActive('finance.php')?>"><i data-lucide="wallet"></i> My Store Loans</a>
                    <a href="<?=url('/customer/emi-schedule.php')?>" class="<?=$isActive('emi-schedule.php')?>"><i data-lucide="calendar"></i> EMI Schedule</a>
                    <a href="<?=url('/customer/autopay.php')?>" class="<?=$isActive('autopay.php')?>"><i data-lucide="repeat"></i> Autopay & Mandate</a>
                    <a href="<?=url('/customer/payments.php')?>" class="<?=$isActive('payments.php')?>"><i data-lucide="credit-card"></i> Payment Receipts</a>
                    <a href="<?=url('/customer/documents.php')?>" class="<?=$isActive('documents.php')?>"><i data-lucide="file-text"></i> My Documents</a>
                    <a href="<?=url('/customer/credit-report.php')?>" class="<?=$isActive('credit-report.php')?>"><i data-lucide="file-spreadsheet"></i> Credit Bureau Report</a>

                <?php endif; ?>

                <div class="nav-divider" style="margin-top: 20px;"></div>
                <a href="<?=url('/logout.php')?>" style="color: var(--danger); font-weight: 700;"><i data-lucide="log-out"></i> Logout</a>
            </nav>
        </aside>

        <!-- MAIN CONTENT AREA -->
        <main>
            <header class="page-header">
                <h1><?=e($title)?></h1>
            </header>
            <section>
<?php } 

function render_end(){ 
    $x = u();
?>
            </section>
        </main>
    </div>

    <!-- SPOTLIGHT QUICK NAVIGATION MODAL (Ctrl + K) -->
    <?php if(($x['role'] ?? '') === 'superadmin'): ?>
    <div id="spotlightModal" class="spotlight-backdrop" onclick="closeSpotlightSearch(event)">
        <div class="spotlight-box" onclick="event.stopPropagation()">
            <div class="spotlight-header">
                <i data-lucide="search" style="color: var(--primary); width: 20px; height: 20px;"></i>
                <input type="text" id="spotlightInput" class="spotlight-input" placeholder="Type a page, report, or keyword (e.g. 'stamp', 'gst', 'leads')..." onkeyup="handleSpotlightSearch(event)">
                <span class="quick-nav-kbd">ESC</span>
            </div>
            <div id="spotlightResults" class="spotlight-results">
                <!-- Dynamically Rendered Results -->
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- REAL FLOATING DROPDOWN MENUS (POPOVER - NO SIDEBAR ACCORDION SLIDE) -->
    <?php 
    $currentScript = basename($_SERVER['PHP_SELF'] ?? '');
    $currentType = $_GET['type'] ?? '';
    ?>
    <?php if(($x['role'] ?? '') === 'superadmin'): ?>
    <!-- ADVANCE REPORTS & MIS DROPDOWN (SUPERADMIN) -->
    <div id="advReportsDropdown" class="real-dropdown-menu" onclick="event.stopPropagation()">
        <div class="dropdown-header-tag">
            <span>MIS / Executive Reports</span>
            <i data-lucide="trending-up" style="width: 12px; height: 12px; color: #8b5cf6;"></i>
        </div>

        <div style="font-size: 0.65rem; font-weight: 800; color: #64748b; padding: 4px 10px 2px; text-transform: uppercase; letter-spacing: 0.5px;">Portfolio & Lending</div>
        <a href="<?=url('/admin/advance-reports.php?type=portfolio_summary')?>" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='portfolio_summary')?'active':''?>">
            <i data-lucide="pie-chart"></i> <span>Portfolio Summary</span>
        </a>
        <a href="<?=url('/admin/advance-reports.php?type=total_loans')?>" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='total_loans')?'active':''?>">
            <i data-lucide="layers"></i> <span>Total Loan Report</span>
        </a>
        <a href="<?=url('/admin/advance-reports.php?type=disbursement')?>" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='disbursement')?'active':''?>">
            <i data-lucide="arrow-up-right"></i> <span>Disbursement Report</span>
        </a>
        <a href="<?=url('/admin/advance-reports.php?type=outstanding')?>" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='outstanding')?'active':''?>">
            <i data-lucide="alert-circle"></i> <span>Outstanding Report</span>
        </a>
        <a href="<?=url('/admin/advance-reports.php?type=closed_loans')?>" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='closed_loans')?'active':''?>">
            <i data-lucide="check-circle-2"></i> <span>Paid / Closed Loans</span>
        </a>

        <div style="font-size: 0.65rem; font-weight: 800; color: #64748b; padding: 6px 10px 2px; text-transform: uppercase; letter-spacing: 0.5px; border-top: 1px solid rgba(255,255,255,0.06); margin-top: 4px;">Collections & Recovery</div>
        <a href="<?=url('/admin/advance-reports.php?type=collections')?>" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='collections')?'active':''?>">
            <i data-lucide="wallet"></i> <span>Collection Report</span>
        </a>
        <a href="<?=url('/admin/advance-reports.php?type=daily_collection')?>" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='daily_collection')?'active':''?>">
            <i data-lucide="calendar-check"></i> <span>Daily Collection</span>
        </a>
        <a href="<?=url('/admin/advance-reports.php?type=monthly_collection')?>" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='monthly_collection')?'active':''?>">
            <i data-lucide="calendar"></i> <span>Monthly Collection</span>
        </a>
        <a href="<?=url('/admin/advance-reports.php?type=foreclosure')?>" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='foreclosure')?'active':''?>">
            <i data-lucide="lock"></i> <span>Foreclosure Report</span>
        </a>

        <div style="font-size: 0.65rem; font-weight: 800; color: #64748b; padding: 6px 10px 2px; text-transform: uppercase; letter-spacing: 0.5px; border-top: 1px solid rgba(255,255,255,0.06); margin-top: 4px;">Delinquency & Due Dates</div>
        <a href="<?=url('/admin/advance-reports.php?type=pending_emi')?>" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='pending_emi')?'active':''?>">
            <i data-lucide="clock"></i> <span>Pending EMI Report</span>
        </a>
        <a href="<?=url('/admin/advance-reports.php?type=overdue_loans')?>" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='overdue_loans')?'active':''?>">
            <i data-lucide="alert-triangle"></i> <span>Overdue Loan Report</span>
        </a>
        <a href="<?=url('/admin/advance-reports.php?type=due_date')?>" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='due_date')?'active':''?>">
            <i data-lucide="calendar-days"></i> <span>EMI Due-Date Report</span>
        </a>

        <div style="font-size: 0.65rem; font-weight: 800; color: #64748b; padding: 6px 10px 2px; text-transform: uppercase; letter-spacing: 0.5px; border-top: 1px solid rgba(255,255,255,0.06); margin-top: 4px;">Breakdowns & Staff</div>
        <a href="<?=url('/admin/advance-reports.php?type=branch_wise')?>" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='branch_wise')?'active':''?>">
            <i data-lucide="building-2"></i> <span>Branch-wise Report</span>
        </a>
        <a href="<?=url('/admin/advance-reports.php?type=staff_wise')?>" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='staff_wise')?'active':''?>">
            <i data-lucide="user-check"></i> <span>Staff / Agent Report</span>
        </a>
        <a href="<?=url('/admin/advance-reports.php?type=customer_wise')?>" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='customer_wise')?'active':''?>">
            <i data-lucide="users"></i> <span>Customer-wise Report</span>
        </a>
    </div>

    <div id="reportsDropdown" class="real-dropdown-menu" onclick="event.stopPropagation()">
        <div class="dropdown-header-tag">
            <span>Reports & GST Returns</span>
            <i data-lucide="bar-chart-3" style="width: 12px; height: 12px; color: #10b981;"></i>
        </div>
        <a href="<?=url('/admin/reports.php?type=pos_sales')?>" class="real-dropdown-item <?=($currentScript==='reports.php' && $currentType==='pos_sales')?'active':''?>">
            <i data-lucide="file-spreadsheet"></i> <span>POS Invoices</span>
        </a>
        <a href="<?=url('/admin/reports.php?type=gstr1')?>" class="real-dropdown-item <?=($currentScript==='reports.php' && $currentType==='gstr1')?'active':''?>">
            <i data-lucide="calculator"></i> <span>GSTR-1 Return</span>
        </a>
        <a href="<?=url('/admin/reports.php?type=collections')?>" class="real-dropdown-item <?=($currentScript==='reports.php' && $currentType==='collections')?'active':''?>">
            <i data-lucide="wallet"></i> <span>Collections</span>
        </a>
        <a href="<?=url('/admin/reports.php?type=applications')?>" class="real-dropdown-item <?=($currentScript==='reports.php' && $currentType==='applications')?'active':''?>">
            <i data-lucide="file-text"></i> <span>Loan Registry</span>
        </a>
        <a href="<?=url('/admin/reports.php?type=audit_logs')?>" class="real-dropdown-item <?=($currentScript==='reports.php' && $currentType==='audit_logs')?'active':''?>">
            <i data-lucide="shield-check"></i> <span>Audit Logs</span>
        </a>
    </div>

    <div id="cmsDropdown" class="real-dropdown-menu" onclick="event.stopPropagation()">
        <div class="dropdown-header-tag">
            <span>Website CMS Management</span>
            <i data-lucide="globe" style="width: 12px; height: 12px; color: var(--primary);"></i>
        </div>
        <a href="<?=url('/admin/cms-hero.php')?>" class="real-dropdown-item <?=$currentScript==='cms-hero.php'?'active':''?>">
            <i data-lucide="layout"></i> <span>Hero & Contact</span>
        </a>
        <a href="<?=url('/admin/cms-about.php')?>" class="real-dropdown-item <?=$currentScript==='cms-about.php'?'active':''?>">
            <i data-lucide="info"></i> <span>About Story</span>
        </a>
        <a href="<?=url('/admin/cms-directors.php')?>" class="real-dropdown-item <?=$currentScript==='cms-directors.php'?'active':''?>">
            <i data-lucide="users"></i> <span>Directors</span>
        </a>
        <a href="<?=url('/admin/cms-products.php')?>" class="real-dropdown-item <?=$currentScript==='cms-products.php'?'active':''?>">
            <i data-lucide="shopping-bag"></i> <span>Products</span>
        </a>
        <a href="<?=url('/admin/cms-features.php')?>" class="real-dropdown-item <?=$currentScript==='cms-features.php'?'active':''?>">
            <i data-lucide="sparkles"></i> <span>Features</span>
        </a>
        <a href="<?=url('/admin/cms-reviews.php')?>" class="real-dropdown-item <?=$currentScript==='cms-reviews.php'?'active':''?>">
            <i data-lucide="star"></i> <span>Reviews</span>
        </a>
    </div>
    <?php elseif(($x['role'] ?? '') === 'shop_admin' || ($x['role'] ?? '') === 'staff'): 
        $shopBase = url($x['role'] === 'shop_admin' ? '/shop' : '/staff');
    ?>
    <!-- ADVANCE REPORTS & MIS DROPDOWN (SHOP) -->
    <div id="shopAdvReportsDropdown" class="real-dropdown-menu" onclick="event.stopPropagation()">
        <div class="dropdown-header-tag">
            <span>MIS / Executive Reports</span>
            <i data-lucide="trending-up" style="width: 12px; height: 12px; color: #8b5cf6;"></i>
        </div>

        <div style="font-size: 0.65rem; font-weight: 800; color: #64748b; padding: 4px 10px 2px; text-transform: uppercase; letter-spacing: 0.5px;">Portfolio & Lending</div>
        <a href="<?=$shopBase?>/advance-reports.php?type=portfolio_summary" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='portfolio_summary')?'active':''?>">
            <i data-lucide="pie-chart"></i> <span>Portfolio Summary</span>
        </a>
        <a href="<?=$shopBase?>/advance-reports.php?type=total_loans" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='total_loans')?'active':''?>">
            <i data-lucide="layers"></i> <span>Total Loan Report</span>
        </a>
        <a href="<?=$shopBase?>/advance-reports.php?type=disbursement" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='disbursement')?'active':''?>">
            <i data-lucide="arrow-up-right"></i> <span>Disbursement Report</span>
        </a>
        <a href="<?=$shopBase?>/advance-reports.php?type=outstanding" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='outstanding')?'active':''?>">
            <i data-lucide="alert-circle"></i> <span>Outstanding Report</span>
        </a>
        <a href="<?=$shopBase?>/advance-reports.php?type=closed_loans" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='closed_loans')?'active':''?>">
            <i data-lucide="check-circle-2"></i> <span>Paid / Closed Loans</span>
        </a>

        <div style="font-size: 0.65rem; font-weight: 800; color: #64748b; padding: 6px 10px 2px; text-transform: uppercase; letter-spacing: 0.5px; border-top: 1px solid rgba(255,255,255,0.06); margin-top: 4px;">Collections & Recovery</div>
        <a href="<?=$shopBase?>/advance-reports.php?type=collections" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='collections')?'active':''?>">
            <i data-lucide="wallet"></i> <span>Collection Report</span>
        </a>
        <a href="<?=$shopBase?>/advance-reports.php?type=daily_collection" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='daily_collection')?'active':''?>">
            <i data-lucide="calendar-check"></i> <span>Daily Collection</span>
        </a>
        <a href="<?=$shopBase?>/advance-reports.php?type=monthly_collection" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='monthly_collection')?'active':''?>">
            <i data-lucide="calendar"></i> <span>Monthly Collection</span>
        </a>
        <a href="<?=$shopBase?>/advance-reports.php?type=foreclosure" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='foreclosure')?'active':''?>">
            <i data-lucide="lock"></i> <span>Foreclosure Report</span>
        </a>

        <div style="font-size: 0.65rem; font-weight: 800; color: #64748b; padding: 6px 10px 2px; text-transform: uppercase; letter-spacing: 0.5px; border-top: 1px solid rgba(255,255,255,0.06); margin-top: 4px;">Delinquency & Due Dates</div>
        <a href="<?=$shopBase?>/advance-reports.php?type=pending_emi" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='pending_emi')?'active':''?>">
            <i data-lucide="clock"></i> <span>Pending EMI Report</span>
        </a>
        <a href="<?=$shopBase?>/advance-reports.php?type=overdue_loans" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='overdue_loans')?'active':''?>">
            <i data-lucide="alert-triangle"></i> <span>Overdue Loan Report</span>
        </a>
        <a href="<?=$shopBase?>/advance-reports.php?type=due_date" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='due_date')?'active':''?>">
            <i data-lucide="calendar-days"></i> <span>EMI Due-Date Report</span>
        </a>

        <div style="font-size: 0.65rem; font-weight: 800; color: #64748b; padding: 6px 10px 2px; text-transform: uppercase; letter-spacing: 0.5px; border-top: 1px solid rgba(255,255,255,0.06); margin-top: 4px;">Breakdowns & Staff</div>
        <a href="<?=$shopBase?>/advance-reports.php?type=staff_wise" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='staff_wise')?'active':''?>">
            <i data-lucide="user-check"></i> <span>Staff / Agent Report</span>
        </a>
        <a href="<?=$shopBase?>/advance-reports.php?type=customer_wise" class="real-dropdown-item <?=($currentScript==='advance-reports.php' && $currentType==='customer_wise')?'active':''?>">
            <i data-lucide="users"></i> <span>Customer-wise Report</span>
        </a>
    </div>

    <div id="shopReportsDropdown" class="real-dropdown-menu" onclick="event.stopPropagation()">
        <div class="dropdown-header-tag">
            <span>Store Reports & GST</span>
            <i data-lucide="bar-chart-3" style="width: 12px; height: 12px; color: #10b981;"></i>
        </div>
        <a href="<?=$shopBase?>/reports.php?type=pos_sales" class="real-dropdown-item <?=($currentScript==='reports.php' && $currentType==='pos_sales')?'active':''?>">
            <i data-lucide="file-spreadsheet"></i> <span>POS Invoices</span>
        </a>
        <a href="<?=$shopBase?>/reports.php?type=gstr1" class="real-dropdown-item <?=($currentScript==='reports.php' && $currentType==='gstr1')?'active':''?>">
            <i data-lucide="calculator"></i> <span>GSTR-1 Return</span>
        </a>
        <a href="<?=$shopBase?>/reports.php?type=collections" class="real-dropdown-item <?=($currentScript==='reports.php' && $currentType==='collections')?'active':''?>">
            <i data-lucide="wallet"></i> <span>Collections</span>
        </a>
        <a href="<?=$shopBase?>/reports.php?type=applications" class="real-dropdown-item <?=($currentScript==='reports.php' && $currentType==='applications')?'active':''?>">
            <i data-lucide="file-text"></i> <span>Loan Registry</span>
        </a>
        <a href="<?=$shopBase?>/reports.php?type=audit_logs" class="real-dropdown-item <?=($currentScript==='reports.php' && $currentType==='audit_logs')?'active':''?>">
            <i data-lucide="shield-check"></i> <span>Audit Logs</span>
        </a>
    </div>
    <?php endif; ?>

    <script>
        // Spotlight Search Items Registry
        const spotlightRegistry = [
            { title: 'Superadmin Menu Hub', url: '<?=url("/admin/menu.php")?>', category: 'Core', icon: 'layout-grid', desc: 'All ERP menus & module control hub' },
            { title: 'Dashboard', url: '<?=url("/admin/dashboard.php")?>', category: 'Core', icon: 'layout-dashboard', desc: 'Overview metrics & loan statistics' },
            { title: 'POS Billing Terminal', url: '<?=url("/admin/pos.php")?>', category: 'Core', icon: 'shopping-cart', desc: 'Point of sale finance counter' },
            { title: 'Loan Applications', url: '<?=url("/admin/applications.php")?>', category: 'Loans', icon: 'file-text', desc: 'Review & approve loan applications' },
            { title: 'Customers & KYC', url: '<?=url("/admin/customers.php")?>', category: 'Loans', icon: 'users', desc: 'Borrower KYC records & Aadhaar details' },
            { title: 'Credit Bureau Check', url: '<?=url("/admin/credit-check.php")?>', category: 'Loans', icon: 'shield-check', desc: 'CRIF & CIBIL score simulator' },
            { title: 'Product Catalog', url: '<?=url("/admin/products.php")?>', category: 'Loans', icon: 'package', desc: 'Financed products & specifications' },
            { title: 'EMI Collections', url: '<?=url("/admin/collections.php")?>', category: 'Finance', icon: 'badge-percent', desc: 'Installment repayments & due tracking' },
            { title: 'Loan Documents & PDF', url: '<?=url("/admin/documents.php")?>', category: 'Finance', icon: 'file-check', desc: '11 legal document formats & PDF generator' },
            { title: 'Wallet & PayU', url: '<?=url("/admin/wallet.php")?>', category: 'Finance', icon: 'credit-card', desc: 'Merchant store balances & payment gateway' },
            { title: 'Reports & GST Returns', url: '<?=url("/admin/reports.php")?>', category: 'Finance', icon: 'bar-chart-3', desc: 'GSTR-1, POS sales invoices & analytics' },
            { title: 'Advance MIS Reports Package', url: '<?=url("/admin/advance-reports.php")?>', category: 'MIS Reports', icon: 'trending-up', desc: 'Management business analytics & 15 executive reports' },
            { title: 'Loan Portfolio Summary Report', url: '<?=url("/admin/advance-reports.php?type=portfolio_summary")?>', category: 'MIS Reports', icon: 'pie-chart', desc: 'Active, closed & overdue portfolio KPIs' },
            { title: 'Total Loan Report', url: '<?=url("/admin/advance-reports.php?type=total_loans")?>', category: 'MIS Reports', icon: 'layers', desc: 'All financed loans & tenure details' },
            { title: 'Disbursement Report', url: '<?=url("/admin/advance-reports.php?type=disbursement")?>', category: 'MIS Reports', icon: 'arrow-up-right', desc: 'Net loan disbursements & payout tracking' },
            { title: 'Collection Report', url: '<?=url("/admin/advance-reports.php?type=collections")?>', category: 'MIS Reports', icon: 'wallet', desc: 'EMI collections & payment mode breakdown' },
            { title: 'Daily Collection Report', url: '<?=url("/admin/advance-reports.php?type=daily_collection")?>', category: 'MIS Reports', icon: 'calendar-check', desc: 'Day-by-day cash & online collections' },
            { title: 'Monthly Collection Report', url: '<?=url("/admin/advance-reports.php?type=monthly_collection")?>', category: 'MIS Reports', icon: 'calendar', desc: 'Month-on-month recovery trend' },
            { title: 'Pending EMI Report', url: '<?=url("/admin/advance-reports.php?type=pending_emi")?>', category: 'MIS Reports', icon: 'clock', desc: 'Unpaid installments & upcoming dues' },
            { title: 'Overdue Loan Report (DPD)', url: '<?=url("/admin/advance-reports.php?type=overdue_loans")?>', category: 'MIS Reports', icon: 'alert-triangle', desc: 'Delinquent loans with Days Past Due' },
            { title: 'Outstanding Loan Report', url: '<?=url("/admin/advance-reports.php?type=outstanding")?>', category: 'MIS Reports', icon: 'alert-circle', desc: 'Unrecovered principal & balance exposure' },
            { title: 'Paid & Closed Loans Report', url: '<?=url("/admin/advance-reports.php?type=closed_loans")?>', category: 'MIS Reports', icon: 'check-circle-2', desc: 'Fully paid accounts & NOC readiness' },
            { title: 'Branch-wise Loan Report', url: '<?=url("/admin/advance-reports.php?type=branch_wise")?>', category: 'MIS Reports', icon: 'building-2', desc: 'Branch & store performance comparison' },
            { title: 'Staff & Agent Collection Report', url: '<?=url("/admin/advance-reports.php?type=staff_wise")?>', category: 'MIS Reports', icon: 'user-check', desc: 'Collection agent recovery totals' },
            { title: 'Customer-wise Loan Report', url: '<?=url("/admin/advance-reports.php?type=customer_wise")?>', category: 'MIS Reports', icon: 'users', desc: 'Borrower loan book & repayment history' },
            { title: 'EMI Due-Date Calendar Report', url: '<?=url("/admin/advance-reports.php?type=due_date")?>', category: 'MIS Reports', icon: 'calendar-days', desc: 'Upcoming scheduled installment calendar' },
            { title: 'Foreclosure Loan Report', url: '<?=url("/admin/advance-reports.php?type=foreclosure")?>', category: 'MIS Reports', icon: 'lock', desc: 'Early loan settlement & principal waiver' },
            { title: 'POS Sales Invoices Report', url: '<?=url("/admin/reports.php?type=pos_sales")?>', category: 'Reports', icon: 'file-spreadsheet', desc: 'Detailed POS sales invoices' },
            { title: 'GSTR-1 GST Return Table', url: '<?=url("/admin/reports.php?type=gstr1")?>', category: 'Reports', icon: 'calculator', desc: 'GST filing calculations & HSN summary' },
            { title: 'Audit & Activity Logs', url: '<?=url("/admin/reports.php?type=audit_logs")?>', category: 'Reports', icon: 'shield-check', desc: 'System security & action logs' },
            { title: 'Merchant Stores', url: '<?=url("/admin/shops.php")?>', category: 'Stores', icon: 'store', desc: 'Partner merchant retail shops' },
            { title: 'User Accounts & Roles', url: '<?=url("/admin/users.php")?>', category: 'Stores', icon: 'user-check', desc: 'Manage superadmins, managers & staff' },
            { title: 'SMS Communication', url: '<?=url("/admin/communication.php")?>', category: 'Stores', icon: 'send', desc: 'Send bulk customer notifications' },
            { title: 'Website Inbound Leads', url: '<?=url("/admin/website-leads.php")?>', category: 'Marketing', icon: 'inbox', desc: 'Inquiries from public landing site' },
            { title: 'Hero & Contact CMS', url: '<?=url("/admin/cms-hero.php")?>', category: 'CMS', icon: 'layout', desc: 'Website banner & official contact info' },
            { title: 'Company Story CMS', url: '<?=url("/admin/cms-about.php")?>', category: 'CMS', icon: 'info', desc: 'About Go4 Finance & company milestones' },
            { title: 'Board of Directors CMS', url: '<?=url("/admin/cms-directors.php")?>', category: 'CMS', icon: 'users', desc: 'Executive directors & leadership profiles' },
            { title: 'Featured Products CMS', url: '<?=url("/admin/cms-products.php")?>', category: 'CMS', icon: 'shopping-bag', desc: 'Featured devices on website' },
            { title: 'Why Choose Us CMS', url: '<?=url("/admin/cms-features.php")?>', category: 'CMS', icon: 'sparkles', desc: 'Value propositions & USPs' },
            { title: 'Customer Reviews CMS', url: '<?=url("/admin/cms-reviews.php")?>', category: 'CMS', icon: 'star', desc: 'Customer testimonials & reviews' },
            { title: 'Document Templates & Stamp', url: '<?=url("/admin/document-templates.php")?>', category: 'Settings', icon: 'file-cog', desc: 'Upload FOR GO4 FINANCE PVT LTD stamp signature & edit name' },
            { title: 'ERP System Settings', url: '<?=url("/admin/settings.php")?>', category: 'Settings', icon: 'sliders', desc: 'PayU keys, theme mode & branding config' }
        ];

        let selectedSpotlightIdx = 0;

        function openSpotlightSearch() {
            const modal = document.getElementById('spotlightModal');
            const input = document.getElementById('spotlightInput');
            if (modal && input) {
                modal.classList.add('active');
                input.value = '';
                selectedSpotlightIdx = 0;
                renderSpotlightResults('');
                setTimeout(() => input.focus(), 50);
            }
        }

        function closeSpotlightSearch(e) {
            const modal = document.getElementById('spotlightModal');
            if (modal) modal.classList.remove('active');
        }

        function handleSpotlightSearch(e) {
            if (e.key === 'Escape') {
                closeSpotlightSearch();
                return;
            }

            const items = document.querySelectorAll('.spotlight-item');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                if (items.length > 0) {
                    selectedSpotlightIdx = (selectedSpotlightIdx + 1) % items.length;
                    highlightSpotlightItem(items);
                }
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (items.length > 0) {
                    selectedSpotlightIdx = (selectedSpotlightIdx - 1 + items.length) % items.length;
                    highlightSpotlightItem(items);
                }
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                if (items[selectedSpotlightIdx]) {
                    items[selectedSpotlightIdx].click();
                }
                return;
            }

            selectedSpotlightIdx = 0;
            renderSpotlightResults(e.target.value.toLowerCase().trim());
        }

        function highlightSpotlightItem(items) {
            items.forEach((item, idx) => {
                if (idx === selectedSpotlightIdx) {
                    item.classList.add('selected');
                    item.scrollIntoView({ block: 'nearest' });
                } else {
                    item.classList.remove('selected');
                }
            });
        }

        function renderSpotlightResults(query) {
            const container = document.getElementById('spotlightResults');
            if (!container) return;

            const filtered = spotlightRegistry.filter(item => {
                if (!query) return true;
                return item.title.toLowerCase().includes(query) || 
                       item.desc.toLowerCase().includes(query) || 
                       item.category.toLowerCase().includes(query);
            });

            if (filtered.length === 0) {
                container.innerHTML = `<div style="text-align: center; padding: 24px; color: var(--text-muted); font-size: 0.9rem;">No menu items matching "<strong>${escapeHtml(query)}</strong>"</div>`;
                return;
            }

            let html = '';
            filtered.forEach((item, idx) => {
                const isSelected = (idx === selectedSpotlightIdx) ? 'selected' : '';
                html += `
                    <a href="${item.url}" class="spotlight-item ${isSelected}">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <div style="width: 32px; height: 32px; border-radius: 6px; background: rgba(59,130,246,0.15); color: var(--primary); display: flex; align-items: center; justify-content: center;">
                                <i data-lucide="${item.icon}" style="width: 16px; height: 16px;"></i>
                            </div>
                            <div>
                                <div style="font-weight: 700; font-size: 0.92rem;">${escapeHtml(item.title)}</div>
                                <div class="spotlight-item-meta">${escapeHtml(item.desc)}</div>
                            </div>
                        </div>
                        <span class="badge badge-info" style="font-size: 0.68rem;">${escapeHtml(item.category)}</span>
                    </a>
                `;
            });

            container.innerHTML = html;
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }

        function escapeHtml(str) {
            return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // Global Shortcut: Ctrl + K / Cmd + K to open Spotlight Search
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                openSpotlightSearch();
            }
        });

        // Real Floating Dropdown Popover System
        function closeAllRealDropdowns() {
            document.querySelectorAll('.real-dropdown-menu').forEach(function(menu) {
                menu.classList.remove('show');
            });
            document.querySelectorAll('.nav-dropdown-btn').forEach(function(btn) {
                btn.classList.remove('active-dropdown');
                var arrow = btn.querySelector('.dropdown-arrow-icon');
                if (arrow) arrow.style.transform = 'rotate(0deg)';
            });
        }

        function toggleRealDropdown(event, menuId) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            var trigger = event ? event.currentTarget : null;
            var menu = document.getElementById(menuId);
            if (!menu) return;

            var isAlreadyOpen = menu.classList.contains('show');
            closeAllRealDropdowns();
            if (isAlreadyOpen) return;

            if (trigger) {
                var rect = trigger.getBoundingClientRect();
                var isMobile = window.innerWidth <= 900;
                
                if (isMobile) {
                    menu.style.left = Math.max(10, rect.left) + 'px';
                    menu.style.top = (rect.bottom + 6) + 'px';
                    menu.style.bottom = 'auto';
                    menu.style.width = (rect.width) + 'px';
                } else {
                    var menuWidth = (menuId.indexOf('dvReports') !== -1) ? 275 : 245;
                    var left = rect.right + 8;
                    if (left + menuWidth > window.innerWidth) {
                        left = Math.max(10, rect.left - menuWidth);
                    }
                    menu.style.left = left + 'px';
                    menu.style.width = menuWidth + 'px';

                    // Measure natural menu height
                    menu.style.visibility = 'hidden';
                    menu.style.display = 'block';
                    var menuHeight = menu.offsetHeight || 280;
                    menu.style.display = '';
                    menu.style.visibility = '';

                    // Check space available below and above the trigger button
                    var spaceBelow = window.innerHeight - rect.top;
                    var spaceAbove = rect.bottom;

                    if (spaceBelow >= menuHeight + 16 || spaceBelow >= spaceAbove) {
                        // Plenty of room below - anchor to the top of the button
                        var top = Math.max(10, Math.min(rect.top, window.innerHeight - menuHeight - 12));
                        menu.style.top = top + 'px';
                        menu.style.bottom = 'auto';
                    } else {
                        // Button is near the bottom - anchor seamlessly to the bottom of the button!
                        var bottom = Math.max(10, window.innerHeight - rect.bottom);
                        menu.style.bottom = bottom + 'px';
                        menu.style.top = 'auto';
                    }
                }
                
                trigger.classList.add('active-dropdown');
                var arrow = trigger.querySelector('.dropdown-arrow-icon');
                if (arrow) arrow.style.transform = 'rotate(90deg)';
            }

            menu.classList.add('show');
        }

        // Dismiss dropdown on outside click
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.real-dropdown-menu') && !e.target.closest('.nav-dropdown-btn')) {
                closeAllRealDropdowns();
            }
        });

        // Dismiss dropdown on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAllRealDropdowns();
            }
        });

        // Dismiss on window resize
        window.addEventListener('resize', closeAllRealDropdowns);

        function toggleSidebar() {
            const isMobile = window.innerWidth <= 900;
            const body = document.body;
            const aside = document.querySelector('aside');
            
            if (isMobile) {
                if (aside) {
                    aside.classList.toggle('open');
                    let backdrop = document.getElementById('sidebarBackdrop');
                    if (aside.classList.contains('open')) {
                        if (!backdrop) {
                            backdrop = document.createElement('div');
                            backdrop.id = 'sidebarBackdrop';
                            backdrop.className = 'sidebar-backdrop';
                            backdrop.onclick = toggleSidebar;
                            document.body.appendChild(backdrop);
                        }
                        backdrop.classList.add('show');
                    } else if (backdrop) {
                        backdrop.classList.remove('show');
                    }
                }
            } else {
                body.classList.toggle('sidebar-collapsed');
                const isCollapsed = body.classList.contains('sidebar-collapsed');
                try {
                    localStorage.setItem('go4fin_sidebar_collapsed', isCollapsed ? '1' : '0');
                } catch(e) {}
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            // Restore and preserve aside scroll position across page views
            var asideEl = document.querySelector('aside');
            if (asideEl) {
                try {
                    var savedScroll = sessionStorage.getItem('go4fin_aside_scroll');
                    if (savedScroll !== null) {
                        asideEl.scrollTop = parseInt(savedScroll, 10);
                    }
                    asideEl.addEventListener('scroll', function() {
                        sessionStorage.setItem('go4fin_aside_scroll', asideEl.scrollTop);
                    }, { passive: true });
                } catch(e) {}
            }

            document.querySelectorAll('aside nav a').forEach(function(link) {
                link.addEventListener('click', function() {
                    if (window.innerWidth <= 900) {
                        const aside = document.querySelector('aside');
                        const backdrop = document.getElementById('sidebarBackdrop');
                        if (aside) aside.classList.remove('open');
                        if (backdrop) backdrop.classList.remove('show');
                    }
                });
            });
        });

        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        }
    </script>
</body>
</html>
<?php } 
if(!function_exists('end')){ function end(){ render_end(); } } 
?>
