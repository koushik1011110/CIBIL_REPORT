<?php require_once __DIR__.'/auth.php'; 
function start($title){
    $x = u();
    $roleName = strtoupper(str_replace('_', ' ', $x['role'] ?? 'USER'));
    $initials = strtoupper(substr($x['name'] ?? 'U', 0, 2));
    $shopName = 'Demo Mobile Store';
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
    
    <link rel="stylesheet" href="<?=url('/public/assets/css/app.css')?>">
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
        <div style="display: flex; align-items: center; gap: 16px;">
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

        <div class="nav-actions">
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
                
                <a href="<?=$base?>/dashboard.php" class="<?=$isActive('dashboard.php')?>"><i data-lucide="layout-dashboard"></i> Dashboard</a>
                
                <?php if($x['role'] !== 'customer'): ?>
                    <a href="<?=$base?>/pos.php" class="<?=$isActive('pos.php')?>"><i data-lucide="shopping-cart"></i> POS Billing Terminal</a>
                    <a href="<?=$base?>/customers.php" class="<?=$isActive('customers.php')?>"><i data-lucide="users"></i> Customers</a>
                    <a href="<?=$base?>/credit-check.php" class="<?=$isActive('credit-check.php')?>"><i data-lucide="shield-check"></i> Credit Check</a>
                    <a href="<?=$base?>/applications.php" class="<?=$isActive('applications.php')?>"><i data-lucide="file-text"></i> Applications</a>
                    <a href="<?=$base?>/products.php" class="<?=$isActive('products.php')?>"><i data-lucide="package"></i> Products</a>
                    <a href="<?=$base?>/collections.php" class="<?=$isActive('collections.php')?>"><i data-lucide="wallet"></i> Collections</a>
                    <a href="<?=$base?>/wallet.php" class="<?=$isActive('wallet.php')?>"><i data-lucide="credit-card"></i> Wallet PayU</a>
                    <a href="<?=$base?>/communication.php" class="<?=$isActive('communication.php')?>"><i data-lucide="send"></i> Communication</a>

                    <!-- REPORTS & GST RETURNS INTERACTIVE DROPDOWN MENU -->
                    <?php $isReportsActive = strpos($_SERVER['REQUEST_URI'] ?? '', 'reports.php') !== false; ?>
                    <div class="nav-dropdown" style="margin: 4px 0;">
                        <button type="button" class="nav-dropdown-toggle" onclick="toggleReportsSubmenu()" style="width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; background: rgba(16,185,129,0.08); border: 1px solid var(--border-color); color: #10b981; font-size: 0.88rem; font-weight: 800; cursor: pointer; text-align: left; border-radius: var(--radius-sm);">
                            <span style="display: flex; align-items: center; gap: 8px;">
                                <i data-lucide="bar-chart-3"></i> Reports & GST
                            </span>
                            <i data-lucide="chevron-down" id="reportsChevron" style="width: 16px; height: 16px; transition: transform 0.25s; <?=$isReportsActive ? 'transform: rotate(180deg);' : ''?>"></i>
                        </button>
                        <div id="reportsSubmenu" style="display: <?=$isReportsActive ? 'flex' : 'none'?>; flex-direction: column; gap: 2px; padding-left: 10px; margin-top: 4px; border-left: 2px solid #10b981; margin-left: 10px;">
                            <a href="<?=$base?>/reports.php?type=pos_sales" class="<?=($isActive('reports.php') && ($_GET['type']??'')==='pos_sales')?'active':''?>" style="font-size:0.84rem; padding: 7px 10px;"><i data-lucide="file-spreadsheet"></i> POS Sales Invoices</a>
                            <a href="<?=$base?>/reports.php?type=gstr1" class="<?=($isActive('reports.php') && ($_GET['type']??'')==='gstr1')?'active':''?>" style="font-size:0.84rem; padding: 7px 10px;"><i data-lucide="calculator"></i> GST Return (GSTR-1)</a>
                            <a href="<?=$base?>/reports.php?type=collections" class="<?=($isActive('reports.php') && ($_GET['type']??'')==='collections')?'active':''?>" style="font-size:0.84rem; padding: 7px 10px;"><i data-lucide="wallet"></i> Collections Report</a>
                            <a href="<?=$base?>/reports.php?type=applications" class="<?=($isActive('reports.php') && ($_GET['type']??'')==='applications')?'active':''?>" style="font-size:0.84rem; padding: 7px 10px;"><i data-lucide="file-text"></i> Loan Applications</a>
                            <a href="<?=$base?>/reports.php?type=audit_logs" class="<?=($isActive('reports.php') && ($_GET['type']??'')==='audit_logs')?'active':''?>" style="font-size:0.84rem; padding: 7px 10px;"><i data-lucide="shield-check"></i> Audit & Activity Logs</a>
                        </div>
                    </div>
                <?php else: ?>
                    <a href="<?=url('/customer/finance.php')?>" class="<?=$isActive('finance.php')?>"><i data-lucide="wallet"></i> My Store Loans</a>
                    <a href="<?=url('/customer/emi-schedule.php')?>" class="<?=$isActive('emi-schedule.php')?>"><i data-lucide="calendar"></i> EMI Schedule</a>
                    <a href="<?=url('/customer/payments.php')?>" class="<?=$isActive('payments.php')?>"><i data-lucide="credit-card"></i> Payment Receipts</a>
                    <a href="<?=url('/customer/credit-report.php')?>" class="<?=$isActive('credit-report.php')?>"><i data-lucide="file-spreadsheet"></i> Credit Bureau Report</a>
                <?php endif; ?>
                
                <?php if($x['role'] === 'superadmin'): ?>
                    <a href="<?=url('/admin/website-leads.php')?>" class="<?=$isActive('website-leads.php')?>"><i data-lucide="inbox"></i> Website Leads</a>

                    <!-- FRONTEND CMS INTERACTIVE DROPDOWN MENU -->
                    <?php $isCmsActive = strpos($_SERVER['REQUEST_URI'] ?? '', '/admin/cms-') !== false; ?>
                    <div class="nav-dropdown" style="margin: 4px 0;">
                        <button type="button" class="nav-dropdown-toggle" onclick="toggleCmsSubmenu()" style="width: 100%; display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; background: rgba(59,130,246,0.08); border: 1px solid var(--border-color); color: var(--primary); font-size: 0.88rem; font-weight: 800; cursor: pointer; text-align: left; border-radius: var(--radius-sm);">
                            <span style="display: flex; align-items: center; gap: 8px;">
                                <i data-lucide="globe"></i> Frontend CMS
                            </span>
                            <i data-lucide="chevron-down" id="cmsChevron" style="width: 16px; height: 16px; transition: transform 0.25s; <?=$isCmsActive ? 'transform: rotate(180deg);' : ''?>"></i>
                        </button>
                        <div id="cmsSubmenu" style="display: <?=$isCmsActive ? 'flex' : 'none'?>; flex-direction: column; gap: 2px; padding-left: 10px; margin-top: 4px; border-left: 2px solid var(--primary); margin-left: 10px;">
                            <a href="<?=url('/admin/cms-hero.php')?>" class="<?=$isActive('cms-hero.php')?>" style="font-size:0.84rem; padding: 7px 10px;"><i data-lucide="layout"></i> Hero & Contact Info</a>
                            <a href="<?=url('/admin/cms-about.php')?>" class="<?=$isActive('cms-about.php')?>" style="font-size:0.84rem; padding: 7px 10px;"><i data-lucide="info"></i> About & Company Story</a>
                            <a href="<?=url('/admin/cms-directors.php')?>" class="<?=$isActive('cms-directors.php')?>" style="font-size:0.84rem; padding: 7px 10px;"><i data-lucide="users"></i> Board of Directors</a>
                            <a href="<?=url('/admin/cms-products.php')?>" class="<?=$isActive('cms-products.php')?>" style="font-size:0.84rem; padding: 7px 10px;"><i data-lucide="shopping-bag"></i> Featured Products</a>
                            <a href="<?=url('/admin/cms-features.php')?>" class="<?=$isActive('cms-features.php')?>" style="font-size:0.84rem; padding: 7px 10px;"><i data-lucide="sparkles"></i> Why Choose Us</a>
                            <a href="<?=url('/admin/cms-reviews.php')?>" class="<?=$isActive('cms-reviews.php')?>" style="font-size:0.84rem; padding: 7px 10px;"><i data-lucide="star"></i> Customer Reviews</a>
                        </div>
                    </div>

                    <a href="<?=url('/admin/shops.php')?>" class="<?=$isActive('shops.php')?>"><i data-lucide="store"></i> Shops</a>
                    <a href="<?=url('/admin/users.php')?>" class="<?=$isActive('users.php')?>"><i data-lucide="user-check"></i> Users</a>
                    <a href="<?=url('/admin/settings.php')?>" class="<?=$isActive('settings.php')?>"><i data-lucide="sliders"></i> Settings</a>
                <?php endif; ?>



                <a href="<?=url('/logout.php')?>" style="margin-top: auto; color: var(--danger);"><i data-lucide="log-out"></i> Logout</a>
            </nav>
        </aside>

        <!-- MAIN CONTENT AREA -->
        <main>
            <header class="page-header">
                <h1><?=e($title)?></h1>
            </header>
            <section>
<?php } 

function render_end(){ ?>
            </section>
        </main>
    </div>

    <script>
        function toggleCmsSubmenu() {
            const sub = document.getElementById('cmsSubmenu');
            const chev = document.getElementById('cmsChevron');
            if (sub.style.display === 'none' || !sub.style.display) {
                sub.style.display = 'flex';
                if (chev) chev.style.transform = 'rotate(180deg)';
            } else {
                sub.style.display = 'none';
                if (chev) chev.style.transform = 'rotate(0deg)';
            }
        }

        function toggleReportsSubmenu() {
            const sub = document.getElementById('reportsSubmenu');
            const chev = document.getElementById('reportsChevron');
            if (sub.style.display === 'none' || !sub.style.display) {
                sub.style.display = 'flex';
                if (chev) chev.style.transform = 'rotate(180deg)';
            } else {
                sub.style.display = 'none';
                if (chev) chev.style.transform = 'rotate(0deg)';
            }
        }

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

