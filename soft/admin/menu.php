<?php
require_once __DIR__ . '/../includes/layout.php';
role('superadmin');

$p = db();

// Fetch summary metrics for badges
$totalShops = (int)$p->query('SELECT COUNT(*) FROM shops')->fetchColumn();
$totalCustomers = (int)$p->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$totalApps = (int)$p->query('SELECT COUNT(*) FROM finance_applications')->fetchColumn();
$pendingApps = (int)$p->query('SELECT COUNT(*) FROM finance_applications WHERE status = "pending"')->fetchColumn();
$totalUsers = (int)$p->query('SELECT COUNT(*) FROM users')->fetchColumn();
$totalTemplates = (int)$p->query('SELECT COUNT(*) FROM document_templates')->fetchColumn();
$totalLeads = 0;
try {
    $totalLeads = (int)$p->query('SELECT COUNT(*) FROM website_leads')->fetchColumn();
} catch (Exception $e) {}

start('Superadmin Control Hub & Menu Directory');
?>

<div style="max-width: 1200px; margin: 0 auto;">

    <!-- TOP HEADER & SEARCH BANNER -->
    <div class="card" style="background: linear-gradient(135deg, rgba(30, 41, 59, 0.95), rgba(15, 23, 42, 0.98)); border: 1px solid var(--border-accent); margin-bottom: 28px; padding: 26px; border-radius: 14px; position: relative; overflow: hidden;">
        <div style="position: absolute; right: -20px; bottom: -20px; opacity: 0.05; font-size: 10rem; pointer-events: none;">
            <i data-lucide="layout-grid"></i>
        </div>

        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
            <div>
                <span class="badge badge-info" style="margin-bottom: 6px;"><i data-lucide="sparkles" style="width: 13px; height: 13px;"></i> MASTER NAVIGATION HUB</span>
                <h2 style="font-size: 1.5rem; font-weight: 800; color: #fff; margin-top: 4px;">Superadmin Menu Directory</h2>
                <p class="muted" style="margin-top: 2px; font-size: 0.9rem;">Quick structured access to all 27+ ERP control modules, financial reports, merchant networks, and CMS managers</p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="<?=url('/admin/dashboard.php')?>" class="btn" style="background: rgba(255,255,255,0.08); border: 1px solid var(--border-color); font-size: 0.85rem;"><i data-lucide="layout-dashboard"></i> Main Dashboard</a>
                <a href="<?=url('/admin/settings.php')?>" class="btn btn-primary" style="font-size: 0.85rem;"><i data-lucide="sliders"></i> Global Settings</a>
            </div>
        </div>

        <!-- LIVE SEARCH INPUT -->
        <div style="position: relative; max-width: 600px;">
            <i data-lucide="search" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); width: 18px; height: 18px;"></i>
            <input type="text" id="menuSearchInput" onkeyup="filterMenuCards()" placeholder="Search any page, report, or tool (e.g. 'stamp', 'gst', 'leads', 'pos', 'applications')..." style="width: 100%; padding: 12px 14px 12px 42px; font-size: 0.95rem; border-radius: 10px; background: rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.2); color: #fff; outline: none; transition: border-color 0.2s;" onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.2)'">
        </div>
    </div>

    <!-- QUICK STATS PILLS -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 14px; margin-bottom: 28px;">
        <div class="card" style="padding: 14px 18px; display: flex; align-items: center; gap: 14px; border-left: 3px solid var(--primary);">
            <div style="background: rgba(59,130,246,0.15); color: var(--primary); width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center;"><i data-lucide="file-text"></i></div>
            <div>
                <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Applications</div>
                <div style="font-size: 1.25rem; font-weight: 800;"><?=$totalApps?> <span style="font-size: 0.75rem; color: #f59e0b; font-weight: 700;">(<?=$pendingApps?> pending)</span></div>
            </div>
        </div>

        <div class="card" style="padding: 14px 18px; display: flex; align-items: center; gap: 14px; border-left: 3px solid #10b981;">
            <div style="background: rgba(16,185,129,0.15); color: #10b981; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center;"><i data-lucide="store"></i></div>
            <div>
                <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Merchant Shops</div>
                <div style="font-size: 1.25rem; font-weight: 800;"><?=$totalShops?> Stores</div>
            </div>
        </div>

        <div class="card" style="padding: 14px 18px; display: flex; align-items: center; gap: 14px; border-left: 3px solid #8b5cf6;">
            <div style="background: rgba(139,92,246,0.15); color: #8b5cf6; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center;"><i data-lucide="users"></i></div>
            <div>
                <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Customers</div>
                <div style="font-size: 1.25rem; font-weight: 800;"><?=$totalCustomers?> Registered</div>
            </div>
        </div>

        <div class="card" style="padding: 14px 18px; display: flex; align-items: center; gap: 14px; border-left: 3px solid #ec4899;">
            <div style="background: rgba(236,72,153,0.15); color: #ec4899; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center;"><i data-lucide="inbox"></i></div>
            <div>
                <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Website Leads</div>
                <div style="font-size: 1.25rem; font-weight: 800;"><?=$totalLeads?> Leads</div>
            </div>
        </div>

        <div class="card" style="padding: 14px 18px; display: flex; align-items: center; gap: 14px; border-left: 3px solid #f59e0b;">
            <div style="background: rgba(245,158,11,0.15); color: #f59e0b; width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center;"><i data-lucide="file-cog"></i></div>
            <div>
                <div style="font-size: 0.72rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Doc Templates</div>
                <div style="font-size: 1.25rem; font-weight: 800;"><?=$totalTemplates?> Configured</div>
            </div>
        </div>
    </div>

    <!-- STRUCTURED CATEGORY SECTIONS -->

    <!-- 1. CORE ERP & OPERATIONS -->
    <div class="menu-category-block" style="margin-bottom: 32px;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 2px solid rgba(59,130,246,0.3);">
            <div style="background: rgba(59,130,246,0.18); color: var(--primary); padding: 6px 10px; border-radius: 6px; font-size: 0.9rem; font-weight: 800; display: flex; align-items: center; gap: 6px;">
                <i data-lucide="layout-grid" style="width: 16px; height: 16px;"></i> 1. CORE ERP & OPERATIONS
            </div>
            <span class="muted" style="font-size: 0.8rem;">Daily loan management, POS counter checkout, applicant KYC, and inventory</span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;">
            
            <a href="<?=url('/admin/dashboard.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid var(--primary); display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(59,130,246,0.15); color: var(--primary); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="layout-dashboard"></i></div>
                        <span class="badge badge-info" style="font-size: 0.7rem;">Primary</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Dashboard</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Master dashboard with real-time financial metrics, recent loan apps, and merchant store stats.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 4px;">Open Dashboard →</div>
            </a>

            <a href="<?=url('/admin/pos.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #10b981; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(16,185,129,0.15); color: #10b981; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="shopping-cart"></i></div>
                        <span class="badge badge-success" style="font-size: 0.7rem;">Retail POS</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">POS Billing Terminal</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Instant product sale & finance billing counter with automated down payment calculation & thermal receipt.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #10b981; display: flex; align-items: center; gap: 4px;">Launch POS →</div>
            </a>

            <a href="<?=url('/admin/applications.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #f59e0b; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(245,158,11,0.15); color: #f59e0b; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="file-text"></i></div>
                        <?php if($pendingApps > 0): ?>
                            <span class="badge badge-warning" style="font-size: 0.7rem;"><?=$pendingApps?> Pending</span>
                        <?php endif; ?>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Loan Applications</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Review, approve, reject, and inspect consumer durable loan requests submitted by merchant stores.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #f59e0b; display: flex; align-items: center; gap: 4px;">View Applications →</div>
            </a>

            <a href="<?=url('/admin/customers.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #8b5cf6; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(139,92,246,0.15); color: #8b5cf6; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="users"></i></div>
                        <span class="badge badge-info" style="font-size: 0.7rem;"><?=$totalCustomers?> Profiles</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Customers & KYC</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Comprehensive borrower database, Aadhaar/PAN status, contact records, and loan histories.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #8b5cf6; display: flex; align-items: center; gap: 4px;">Manage Customers →</div>
            </a>

            <a href="<?=url('/admin/credit-check.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #06b6d4; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(6,182,212,0.15); color: #06b6d4; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="shield-check"></i></div>
                        <span class="badge badge-info" style="font-size: 0.7rem;">CRIF / CIBIL</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Credit Bureau Check</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Instant CIBIL / CRIF score simulator, credit eligibility scoring, and risk assessment engine.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #06b6d4; display: flex; align-items: center; gap: 4px;">Run Credit Check →</div>
            </a>

            <a href="<?=url('/admin/products.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #64748b; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(100,116,139,0.15); color: #64748b; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="package"></i></div>
                        <span class="badge badge-info" style="font-size: 0.7rem;">Inventory</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Product Catalog</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Financed consumer durable products, brand specifications, pricing models, and stock status.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #64748b; display: flex; align-items: center; gap: 4px;">Manage Products →</div>
            </a>

        </div>
    </div>

    <!-- 2. FINANCE, COLLECTIONS & COMPLIANCE -->
    <div class="menu-category-block" style="margin-bottom: 32px;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 2px solid rgba(16,185,129,0.3);">
            <div style="background: rgba(16,185,129,0.18); color: #10b981; padding: 6px 10px; border-radius: 6px; font-size: 0.9rem; font-weight: 800; display: flex; align-items: center; gap: 6px;">
                <i data-lucide="wallet" style="width: 16px; height: 16px;"></i> 2. FINANCE, COLLECTIONS & COMPLIANCE
            </div>
            <span class="muted" style="font-size: 0.8rem;">EMI tracking, automated PDF documents, PayU merchant wallet, and GST return statements</span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;">
            
            <a href="<?=url('/admin/collections.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #10b981; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(16,185,129,0.15); color: #10b981; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="badge-percent"></i></div>
                        <span class="badge badge-success" style="font-size: 0.7rem;">Collections</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">EMI Collections</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Daily/monthly EMI installment collections, overdue tracking, payment recording, and instant receipt generation.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #10b981; display: flex; align-items: center; gap: 4px;">Open Collections →</div>
            </a>

            <a href="<?=url('/admin/documents.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid var(--primary); display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(59,130,246,0.15); color: var(--primary); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="file-check"></i></div>
                        <span class="badge badge-info" style="font-size: 0.7rem;">11 Documents</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Loan Documents & PDF</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Generate, print, and bulk download all 11 loan documents (Sanctions, Agreements, Schedules, Receipts, NOCs, Statements).</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 4px;">Open Documents Module →</div>
            </a>

            <a href="<?=url('/admin/wallet.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #8b5cf6; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(139,92,246,0.15); color: #8b5cf6; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="credit-card"></i></div>
                        <span class="badge badge-info" style="font-size: 0.7rem;">PayU Gateway</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Wallet & Payments</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Store partner wallet balances, PayU online checkout recharges, payout ledgers, and transaction audits.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #8b5cf6; display: flex; align-items: center; gap: 4px;">Open Wallet →</div>
            </a>

            <a href="<?=url('/admin/advance-reports.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #8b5cf6; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(139,92,246,0.15); color: #8b5cf6; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="trending-up"></i></div>
                        <span class="badge badge-info" style="font-size: 0.7rem; background: rgba(139,92,246,0.2); color: #8b5cf6;">15 MIS Reports</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Advance Reports & MIS</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Executive business intelligence package: 15 reports, portfolio analytics, branch/staff breakdowns, Excel & PDF exports.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #8b5cf6; display: flex; align-items: center; gap: 4px;">Launch MIS Package →</div>
            </a>

            <a href="<?=url('/admin/reports.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #10b981; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(16,185,129,0.15); color: #10b981; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="bar-chart-3"></i></div>
                        <span class="badge badge-success" style="font-size: 0.7rem;">Reports & GST</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Reports & GST Returns</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">POS sales invoices, GSTR-1 government return filing tables, loan portfolio reports, and audit logs.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #10b981; display: flex; align-items: center; gap: 4px;">View Reports →</div>
            </a>

        </div>
    </div>

    <!-- 3. STORE & PARTNER NETWORK -->
    <div class="menu-category-block" style="margin-bottom: 32px;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 2px solid rgba(139,92,246,0.3);">
            <div style="background: rgba(139,92,246,0.18); color: #8b5cf6; padding: 6px 10px; border-radius: 6px; font-size: 0.9rem; font-weight: 800; display: flex; align-items: center; gap: 6px;">
                <i data-lucide="store" style="width: 16px; height: 16px;"></i> 3. STORE & PARTNER NETWORK
            </div>
            <span class="muted" style="font-size: 0.8rem;">Merchant store accounts, user roles & permissions, and customer SMS communications</span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;">
            
            <a href="<?=url('/admin/shops.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #8b5cf6; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(139,92,246,0.15); color: #8b5cf6; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="store"></i></div>
                        <span class="badge badge-info" style="font-size: 0.7rem;"><?=$totalShops?> Stores</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Merchant Stores</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Add and configure partner retail shops, assign admin logins, wallet credits, GSTIN, and store logos.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #8b5cf6; display: flex; align-items: center; gap: 4px;">Manage Stores →</div>
            </a>

            <a href="<?=url('/admin/users.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid var(--primary); display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(59,130,246,0.15); color: var(--primary); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="user-check"></i></div>
                        <span class="badge badge-info" style="font-size: 0.7rem;"><?=$totalUsers?> Users</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">User Accounts & Roles</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Manage system users, superadmins, shop managers, counter staff, and customer accounts.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 4px;">Manage Users →</div>
            </a>

            <a href="<?=url('/admin/communication.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #06b6d4; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(6,182,212,0.15); color: #06b6d4; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="send"></i></div>
                        <span class="badge badge-info" style="font-size: 0.7rem;">SMS Gateway</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">SMS & Communication</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Send bulk payment reminders, loan approval alerts, KYC notifications, and custom SMS to borrowers.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #06b6d4; display: flex; align-items: center; gap: 4px;">Open Messaging →</div>
            </a>

        </div>
    </div>

    <!-- 4. MARKETING & FRONTEND CMS -->
    <div class="menu-category-block" style="margin-bottom: 32px;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 2px solid rgba(236,72,153,0.3);">
            <div style="background: rgba(236,72,153,0.18); color: #ec4899; padding: 6px 10px; border-radius: 6px; font-size: 0.9rem; font-weight: 800; display: flex; align-items: center; gap: 6px;">
                <i data-lucide="globe" style="width: 16px; height: 16px;"></i> 4. MARKETING & FRONTEND CMS
            </div>
            <span class="muted" style="font-size: 0.8rem;">Manage public landing website, customer application leads, and company branding content</span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;">
            
            <a href="<?=url('/admin/website-leads.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #ec4899; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(236,72,153,0.15); color: #ec4899; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="inbox"></i></div>
                        <span class="badge badge-danger" style="font-size: 0.7rem;"><?=$totalLeads?> Leads</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Website Leads</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Customer loan inquiry leads from public website. Convert lead directly to customer with 1-click.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #ec4899; display: flex; align-items: center; gap: 4px;">View Inbound Leads →</div>
            </a>

            <a href="<?=url('/admin/cms-hero.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid var(--primary); display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(59,130,246,0.15); color: var(--primary); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="layout"></i></div>
                        <span class="badge badge-info" style="font-size: 0.7rem;">Website</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Hero & Contact CMS</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Edit homepage banner headlines, sub-taglines, official phone, email, TAN, and corporate address.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 4px;">Edit Hero Section →</div>
            </a>

            <a href="<?=url('/admin/cms-about.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #8b5cf6; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(139,92,246,0.15); color: #8b5cf6; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="info"></i></div>
                        <span class="badge badge-info" style="font-size: 0.7rem;">About</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Company Story CMS</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Customize company background, mission statement, financial milestones, and vision descriptions.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #8b5cf6; display: flex; align-items: center; gap: 4px;">Edit About Story →</div>
            </a>

            <a href="<?=url('/admin/cms-directors.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #f59e0b; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(245,158,11,0.15); color: #f59e0b; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="user-check"></i></div>
                        <span class="badge badge-warning" style="font-size: 0.7rem;">Leadership</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Board of Directors</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Manage executive director profiles, photos, titles, and legal corporate governance disclosures.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #f59e0b; display: flex; align-items: center; gap: 4px;">Manage Directors →</div>
            </a>

            <a href="<?=url('/admin/cms-products.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #10b981; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(16,185,129,0.15); color: #10b981; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="shopping-bag"></i></div>
                        <span class="badge badge-success" style="font-size: 0.7rem;">Showcase</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Featured Products</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Showcase top financed phones, appliances, and electronics on public website landing page.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #10b981; display: flex; align-items: center; gap: 4px;">Edit Products →</div>
            </a>

            <a href="<?=url('/admin/cms-features.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #06b6d4; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(6,182,212,0.15); color: #06b6d4; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="sparkles"></i></div>
                        <span class="badge badge-info" style="font-size: 0.7rem;">USPs</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Why Choose Us</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Highlight zero interest offers, fast 5-minute counter approvals, and easy EMI features.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #06b6d4; display: flex; align-items: center; gap: 4px;">Edit Features →</div>
            </a>

            <a href="<?=url('/admin/cms-reviews.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #f59e0b; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(245,158,11,0.15); color: #f59e0b; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="star"></i></div>
                        <span class="badge badge-warning" style="font-size: 0.7rem;">Testimonials</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Customer Reviews</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Manage customer feedback, 5-star ratings, testimonials, and verified buyer reviews.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #f59e0b; display: flex; align-items: center; gap: 4px;">Manage Reviews →</div>
            </a>

        </div>
    </div>

    <!-- 5. SYSTEM CONFIGURATION & BRANDING -->
    <div class="menu-category-block" style="margin-bottom: 32px;">
        <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 14px; padding-bottom: 8px; border-bottom: 2px solid rgba(245,158,11,0.3);">
            <div style="background: rgba(245,158,11,0.18); color: #f59e0b; padding: 6px 10px; border-radius: 6px; font-size: 0.9rem; font-weight: 800; display: flex; align-items: center; gap: 6px;">
                <i data-lucide="sliders" style="width: 16px; height: 16px;"></i> 5. SYSTEM CONFIGURATION & BRANDING
            </div>
            <span class="muted" style="font-size: 0.8rem;">Official stamp signatures, template placeholders, payment gateway keys, and theme settings</span>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;">
            
            <a href="<?=url('/admin/document-templates.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid #f59e0b; display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(245,158,11,0.15); color: #f59e0b; width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="file-cog"></i></div>
                        <span class="badge badge-warning" style="font-size: 0.7rem;">Stamp & Templates</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">Document Templates & Stamp</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Upload official company stamp signature for "FOR GO4 FINANCE PVT LTD", edit signatory name, and customize all 11 legal document formats.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: #f59e0b; display: flex; align-items: center; gap: 4px;">Configure Templates & Stamp →</div>
            </a>

            <a href="<?=url('/admin/settings.php')?>" class="card menu-card" style="text-decoration: none; color: inherit; padding: 20px; transition: all 0.2s ease; border-top: 3px solid var(--primary); display: flex; flex-direction: column; justify-content: space-between;">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                        <div style="background: rgba(59,130,246,0.15); color: var(--primary); width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center;"><i data-lucide="sliders"></i></div>
                        <span class="badge badge-info" style="font-size: 0.7rem;">Global</span>
                    </div>
                    <h3 style="font-size: 1.05rem; font-weight: 800; margin-bottom: 6px; color: var(--text-main);">ERP System Settings</h3>
                    <p class="muted" style="font-size: 0.83rem; line-height: 1.4;">Configure PayU gateway keys, SMS API credentials, default interest rates, light/dark themes, and company branding.</p>
                </div>
                <div style="margin-top: 14px; font-size: 0.82rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 4px;">Open Settings →</div>
            </a>

        </div>
    </div>

</div>

<script>
function filterMenuCards() {
    const q = document.getElementById('menuSearchInput').value.toLowerCase().trim();
    const cards = document.querySelectorAll('.menu-card');
    const sections = document.querySelectorAll('.menu-category-block');

    cards.forEach(c => {
        const text = c.innerText.toLowerCase();
        if (text.includes(q)) {
            c.style.display = 'flex';
        } else {
            c.style.display = 'none';
        }
    });

    // Hide section headers if all child cards are hidden
    sections.forEach(s => {
        const visibleCards = s.querySelectorAll('.menu-card[style*="display: flex"]');
        if (q && visibleCards.length === 0) {
            s.style.display = 'none';
        } else {
            s.style.display = 'block';
        }
    });
}
</script>

<style>
.menu-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0,0,0,0.25);
    border-color: var(--primary) !important;
}
</style>

<?php render_end(); ?>
