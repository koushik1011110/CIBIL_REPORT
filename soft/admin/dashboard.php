<?php 
require_once __DIR__.'/../includes/layout.php';
role('superadmin');

$p = db();

// Fetch System Overview Metrics
$totalShops = (int)$p->query('SELECT COUNT(*) FROM shops')->fetchColumn();
$totalCustomers = (int)$p->query('SELECT COUNT(*) FROM customers')->fetchColumn();
$totalApps = (int)$p->query('SELECT COUNT(*) FROM finance_applications')->fetchColumn();
$approvedApps = (int)$p->query('SELECT COUNT(*) FROM finance_applications WHERE status IN ("approved", "active")')->fetchColumn();
$pendingApps = (int)$p->query('SELECT COUNT(*) FROM finance_applications WHERE status = "pending"')->fetchColumn();

$totalFinanced = floatval($p->query('SELECT COALESCE(SUM(finance_amount), 0) FROM finance_applications')->fetchColumn());
$totalPayable = floatval($p->query('SELECT COALESCE(SUM(total_payable), 0) FROM finance_applications')->fetchColumn());
$totalCollected = floatval($p->query('SELECT COALESCE(SUM(amount), 0) FROM payments')->fetchColumn());
$pendingOutstanding = max(0, $totalPayable - $totalCollected);

// Fetch Latest Applications
$appStmt = $p->query('
    SELECT f.*, c.name as customer_name, c.mobile as customer_mobile, s.name as shop_name, p.name as product_name 
    FROM finance_applications f 
    JOIN customers c ON c.id = f.customer_id 
    LEFT JOIN shops s ON s.id = f.shop_id 
    LEFT JOIN products p ON p.id = f.product_id 
    ORDER BY f.id DESC LIMIT 5
');
$latestApps = $appStmt->fetchAll();

// Fetch Active Merchant Shops
$shopStmt = $p->query('
    SELECT s.*, 
        (SELECT COUNT(*) FROM finance_applications WHERE shop_id = s.id) as app_count,
        (SELECT COALESCE(SUM(finance_amount), 0) FROM finance_applications WHERE shop_id = s.id) as total_loan 
    FROM shops s 
    ORDER BY s.id DESC LIMIT 4
');
$topShops = $shopStmt->fetchAll();

start('Super Admin Dashboard');
?>

<!-- WELCOME BANNER & QUICK ACTIONS -->
<div class="card" style="background: linear-gradient(135deg, rgba(30, 41, 59, 0.9), rgba(15, 23, 42, 0.95)); border: 1px solid var(--border-accent); margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; padding: 24px;">
    <div>
        <span class="badge badge-info" style="margin-bottom: 6px;">GO4FIN ENTERPRISE ERP</span>
        <h2 style="font-size: 1.4rem; font-weight: 800; color: #fff; margin-top: 4px;">Go4 Finance Control Center</h2>
        <p class="muted" style="margin-top: 2px;">Master admin overview of merchant stores, loan disbursements, and system EMI collections</p>
    </div>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="credit-check.php" class="btn" style="background: var(--primary); font-size: 0.85rem;"><i data-lucide="shield-check"></i> Credit Check</a>
        <a href="shops.php" class="btn" style="background: var(--secondary); font-size: 0.85rem;"><i data-lucide="store"></i> Manage Shops</a>
        <a href="settings.php" class="btn" style="background: rgba(255,255,255,0.1); border: 1px solid var(--border-color); font-size: 0.85rem;"><i data-lucide="sliders"></i> Settings</a>
    </div>
</div>

<!-- KEY METRIC CARDS GRID -->
<div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 24px;">
    <div class="card" style="border-left: 4px solid var(--primary);">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div class="muted" style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">Active Merchant Shops</div>
                <div class="metric" style="margin-top: 4px; font-size: 1.8rem; font-weight: 800; color: #fff;"><?=$totalShops?></div>
            </div>
            <div style="background: rgba(59, 130, 246, 0.15); color: var(--primary); padding: 10px; border-radius: 10px;">
                <i data-lucide="store" style="width: 22px; height: 22px;"></i>
            </div>
        </div>
        <small class="muted" style="margin-top: 8px; display: block;">Registered Store Network</small>
    </div>

    <div class="card" style="border-left: 4px solid var(--secondary);">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div class="muted" style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">Total Customers</div>
                <div class="metric" style="margin-top: 4px; font-size: 1.8rem; font-weight: 800; color: #fff;"><?=$totalCustomers?></div>
            </div>
            <div style="background: rgba(139, 92, 246, 0.15); color: var(--secondary); padding: 10px; border-radius: 10px;">
                <i data-lucide="users" style="width: 22px; height: 22px;"></i>
            </div>
        </div>
        <small class="muted" style="margin-top: 8px; display: block;">Verified Borrower Profiles</small>
    </div>

    <div class="card" style="border-left: 4px solid var(--info);">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div class="muted" style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">Loan Applications</div>
                <div class="metric" style="margin-top: 4px; font-size: 1.8rem; font-weight: 800; color: #fff;"><?=$totalApps?></div>
            </div>
            <div style="background: rgba(6, 182, 212, 0.15); color: var(--info); padding: 10px; border-radius: 10px;">
                <i data-lucide="file-text" style="width: 22px; height: 22px;"></i>
            </div>
        </div>
        <small class="muted" style="margin-top: 8px; display: block;"><?=$approvedApps?> Approved | <?=$pendingApps?> Pending</small>
    </div>

    <div class="card" style="border-left: 4px solid var(--success);">
        <div style="display: flex; justify-content: space-between; align-items: flex-start;">
            <div>
                <div class="muted" style="font-size: 0.8rem; font-weight: 700; text-transform: uppercase;">Total Collections</div>
                <div class="metric" style="margin-top: 4px; font-size: 1.6rem; font-weight: 800; color: var(--success);"><?=money($totalCollected)?></div>
            </div>
            <div style="background: rgba(16, 185, 129, 0.15); color: var(--success); padding: 10px; border-radius: 10px;">
                <i data-lucide="wallet" style="width: 22px; height: 22px;"></i>
            </div>
        </div>
        <small class="muted" style="margin-top: 8px; display: block;">PayU Gateway & Cash Recovered</small>
    </div>
</div>

<!-- FINANCIAL DISBURSEMENT & RECOVERY CARDS -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <div class="card" style="background: rgba(15, 23, 42, 0.6);">
        <h4 style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; margin-bottom: 6px;">Total Financed Loan Amount</h4>
        <div style="font-size: 2.2rem; font-weight: 800; color: var(--primary);"><?=money($totalFinanced)?></div>
        <p class="muted" style="margin-top: 6px; font-size: 0.82rem;">Total loan principal amount issued to customers across all stores.</p>
    </div>

    <div class="card" style="background: rgba(15, 23, 42, 0.6);">
        <h4 style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; margin-bottom: 6px;">Total Market Outstanding Balance</h4>
        <div style="font-size: 2.2rem; font-weight: 800; color: var(--danger);"><?=money($pendingOutstanding)?></div>
        <p class="muted" style="margin-top: 6px; font-size: 0.82rem;">Remaining total loan amount to be collected in upcoming EMI installments.</p>
    </div>
</div>

<!-- TWO COLUMN LAYOUT: RECENT APPLICATIONS & STORES OVERVIEW -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px;">
    
    <!-- RECENT FINANCE APPLICATIONS -->
    <div class="card" style="padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.05rem; font-weight: 800; color: #fff;">Recent Finance Applications</h3>
            <a href="applications.php" style="font-size: 0.8rem; color: var(--primary); text-decoration: none; font-weight: 700;">View All →</a>
        </div>

        <div style="overflow-x: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.82rem;">
                <thead>
                    <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                        <th style="padding: 10px;">App No</th>
                        <th style="padding: 10px;">Customer</th>
                        <th style="padding: 10px;">Store</th>
                        <th style="padding: 10px;">Finance</th>
                        <th style="padding: 10px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($latestApps)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 16px;" class="muted">No finance applications found.</td></tr>
                    <?php else: ?>
                        <?php foreach($latestApps as $r): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 10px;"><strong><?=e($r['application_no'])?></strong></td>
                                <td style="padding: 10px;"><?=e($r['customer_name'])?></td>
                                <td style="padding: 10px;"><?=e($r['shop_name'] ?: 'Demo Store')?></td>
                                <td style="padding: 10px;"><strong style="color:var(--primary);"><?=money($r['finance_amount'])?></strong></td>
                                <td style="padding: 10px;">
                                    <?php if($r['status'] === 'approved' || $r['status'] === 'active'): ?>
                                        <span class="badge badge-success" style="font-size:0.7rem;">APPROVED</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning" style="font-size:0.7rem;">PENDING</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- REGISTERED SHOPS OVERVIEW -->
    <div class="card" style="padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 1.05rem; font-weight: 800; color: #fff;">Store Network Overview</h3>
            <a href="shops.php" style="font-size: 0.8rem; color: var(--secondary); text-decoration: none; font-weight: 700;">Manage Stores →</a>
        </div>

        <div style="overflow-x: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.82rem;">
                <thead>
                    <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                        <th style="padding: 10px;">Store Name</th>
                        <th style="padding: 10px;">Mobile</th>
                        <th style="padding: 10px;">Applications</th>
                        <th style="padding: 10px;">Wallet Balance</th>
                        <th style="padding: 10px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($topShops)): ?>
                        <tr><td colspan="5" style="text-align: center; padding: 16px;" class="muted">No merchant stores created yet.</td></tr>
                    <?php else: ?>
                        <?php foreach($topShops as $s): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 10px;"><strong><?=e($s['name'])?></strong></td>
                                <td style="padding: 10px;"><?=e($s['phone'] ?: '-')?></td>
                                <td style="padding: 10px;"><strong><?=$s['app_count']?> Apps</strong></td>
                                <td style="padding: 10px;"><strong style="color:var(--success);"><?=money($s['wallet_balance'])?></strong></td>
                                <td style="padding: 10px;">
                                    <span class="badge badge-<?=$s['status'] === 'active' ? 'success' : 'danger'?>" style="font-size:0.7rem;">
                                        <?=strtoupper($s['status'])?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php render_end(); ?>
