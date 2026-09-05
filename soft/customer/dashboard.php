<?php 
require_once __DIR__.'/../includes/layout.php';
role('customer');

$p = db();
$user = u();

// Robust Customer Lookup by Email, Mobile, extracted Mobile, or Name
$userEmail = $user['email'] ?? '';
$userName = $user['name'] ?? '';
$mobileFromEmail = str_replace('@customer.local', '', $userEmail);

$s = $p->prepare('
    SELECT * FROM customers 
    WHERE (email != "" AND email = ?) 
       OR (mobile != "" AND (mobile = ? OR mobile = ?)) 
       OR name = ? 
    LIMIT 1
');
$s->execute([$userEmail, $userEmail, $mobileFromEmail, $userName]);
$c = $s->fetch() ?: [];
$customerId = (int)($c['id'] ?? 0);

// Fetch All Applications & Target Selected Application
$allApps = [];
$selectedAppId = isset($_GET['app_id']) ? (int)$_GET['app_id'] : 0;
$f = null;
$totalFinanced = 0;
$totalPayable = 0;
$totalPaid = 0;
$emiPaid = 0;
$pendingBalance = 0;
$nextEmi = null;
$paidPercentage = 0;
$paidEmisCount = 0;
$totalEmisCount = 0;

if ($customerId > 0) {
    $aStmt = $p->prepare('SELECT * FROM finance_applications WHERE customer_id=? ORDER BY id DESC');
    $aStmt->execute([$customerId]);
    $allApps = $aStmt->fetchAll();

    if ($selectedAppId > 0) {
        foreach ($allApps as $appItem) {
            if ((int)$appItem['id'] === $selectedAppId) {
                $f = $appItem;
                break;
            }
        }
    }

    if (!$f && !empty($allApps)) {
        // Prioritize loan with generated EMI schedules or active status
        foreach ($allApps as $appItem) {
            $hasEmis = (int)$p->query("SELECT COUNT(*) FROM emi_schedules WHERE finance_id = " . (int)$appItem['id'])->fetchColumn();
            if ($hasEmis > 0) {
                $f = $appItem;
                break;
            }
        }
        if (!$f) {
            $f = $allApps[0];
        }
    }

    if ($f) {
        $totalFinanced = floatval($f['finance_amount']);
        $totalPayable = floatval($f['total_payable']);
        $downPayment = floatval($f['down_payment'] ?? 0);

        // Calculate total EMI paid from emi_schedules
        $pStmt = $p->prepare("SELECT COALESCE(SUM(paid_amount), 0) FROM emi_schedules WHERE finance_id = ? AND status = 'paid'");
        $pStmt->execute([$f['id']]);
        $emiPaid = floatval($pStmt->fetchColumn());

        // Count cleared and total EMIs
        $paidCountStmt = $p->prepare("SELECT COUNT(*) FROM emi_schedules WHERE finance_id = ? AND status = 'paid'");
        $paidCountStmt->execute([$f['id']]);
        $paidEmisCount = (int)$paidCountStmt->fetchColumn();

        $totalEmisStmt = $p->prepare("SELECT COUNT(*) FROM emi_schedules WHERE finance_id = ?");
        $totalEmisStmt->execute([$f['id']]);
        $totalEmisCount = (int)$totalEmisStmt->fetchColumn();

        // Total Amount Paid = Down Payment + EMI Paid
        $totalPaid = $downPayment + $emiPaid;

        // Calculate pending EMI balance from emi_schedules
        $uStmt = $p->prepare("SELECT COALESCE(SUM(amount), 0) FROM emi_schedules WHERE finance_id = ? AND status != 'paid'");
        $uStmt->execute([$f['id']]);
        $pendingBalance = floatval($uStmt->fetchColumn());

        if ($totalPayable > 0) {
            $paidPercentage = min(100, max(0, round(($emiPaid / $totalPayable) * 100, 1)));
        } else {
            $paidPercentage = 0;
        }

        // Fetch Next EMI Due Date
        $eStmt = $p->prepare('SELECT * FROM emi_schedules WHERE finance_id=? AND status != "paid" ORDER BY installment_no ASC LIMIT 1');
        $eStmt->execute([$f['id']]);
        $nextEmi = $eStmt->fetch() ?: null;
    }
}

// Color logic according to exact user instructions:
// < 25%: Orange / Red
// 25% - 74.9%: Yellow
// 75% - 99.9%: Blue
// 100%: Green

$barColor = '#f97316'; // Orange / Red below 25%
$badgeClass = 'badge-danger';
$statusLabel = 'Below 25% Cleared';

if ($paidPercentage >= 100) {
    $barColor = '#10b981'; // Green
    $badgeClass = 'badge-success';
    $statusLabel = '100% Fully Paid & Cleared';
} elseif ($paidPercentage >= 75) {
    $barColor = '#3b82f6'; // Blue
    $badgeClass = 'badge-info';
    $statusLabel = '75%+ Progress (Blue Tier)';
} elseif ($paidPercentage >= 25) {
    $barColor = '#eab308'; // Yellow
    $badgeClass = 'badge-warning';
    $statusLabel = '25%+ Progress (Yellow Tier)';
} else {
    $barColor = '#f97316'; // Orange / Red
    $badgeClass = 'badge-danger';
    $statusLabel = 'Below 25% Cleared (Orange/Red)';
}

start('Customer Dashboard');
?>

<div style="margin-bottom: 24px;">
    <h2 style="font-size: 1.4rem; font-weight: 800; color: #fff;">Welcome back, <?=e(!empty($c['name']) ? $c['name'] : ($user['name'] ?? 'Customer'))?>! 👋</h2>
    <p class="muted" style="margin-top: 4px;">Track your active store loans, EMI schedule, and payment history</p>
</div>

<?php if (count($allApps) > 1): ?>
    <div class="card" style="margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; padding: 14px 20px; background: rgba(30,41,59,0.7); border: 1px solid var(--border-color);">
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-weight: 700; color: #60a5fa; font-size: 0.95rem;">📱 Switch Loan Application:</span>
            <span style="font-size: 0.85rem; color: #94a3b8;">Select which loan to inspect on your dashboard</span>
        </div>
        <div>
            <select onchange="window.location.href='?app_id=' + this.value" style="background: #0f172a; color: #fff; border: 1px solid #3b82f6; padding: 8px 16px; border-radius: 8px; font-size: 0.9rem; font-weight: 700;">
                <?php foreach ($allApps as $aItem): ?>
                    <option value="<?=$aItem['id']?>" <?=$f && $f['id'] == $aItem['id'] ? 'selected' : ''?>>
                        <?=e($aItem['application_no'])?> (<?=e($aItem['product_name'] ?: 'Product')?>) — [<?=strtoupper($aItem['status'])?>]
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
<?php endif; ?>

<!-- METRIC OVERVIEW CARDS -->
<div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 24px;">
    <div class="card">
        <div class="muted">Credit Score</div>
        <div class="metric" style="color: var(--success);"><?=!empty($c['credit_score']) ? $c['credit_score'] : '—'?></div>
        <small class="muted">Bureau Assessment Rating</small>
    </div>

    <div class="card">
        <div class="muted">Total Financed Loan</div>
        <div class="metric"><?=money($totalFinanced)?></div>
        <small class="muted"><?=e($f['application_no'] ?? 'No active loan')?></small>
    </div>

    <div class="card">
        <div class="muted">Cleared Total EMI</div>
        <div class="metric" style="color: var(--success);"><?=money($emiPaid)?></div>
        <small class="muted"><?=$paidEmisCount?> of <?=$totalEmisCount?> Installments Cleared</small>
    </div>

    <div class="card">
        <div class="muted">Pending Outstanding</div>
        <div class="metric" style="color: <?=$pendingBalance > 0 ? 'var(--danger)' : 'var(--success)'?>;"><?=money($pendingBalance)?></div>
        <small class="muted"><?=$pendingBalance > 0 ? ($totalEmisCount - $paidEmisCount) . ' Months Remaining' : 'All Dues Cleared ✓'?></small>
    </div>
</div>

<!-- DYNAMIC LOAN REPAYMENT PROGRESS LINE CARD -->
<?php if ($f): ?>
    <div class="card" style="margin-bottom: 24px; padding: 24px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
            <div>
                <h3 style="font-size: 1.15rem; font-weight: 800; color: #fff;">📊 EMI Repayment Progress</h3>
                <p class="muted" style="margin-top: 2px;">Track your cleared monthly EMI percentage against total EMI payable amount</p>
            </div>
            <div style="display: flex; align-items: center; gap: 10px;">
                <span class="badge <?=$badgeClass?>" style="font-size: 0.85rem; padding: 6px 14px;"><?=$statusLabel?></span>
                <span style="font-size: 1.4rem; font-weight: 800; color: <?=$barColor?>;"><?=$paidPercentage?>%</span>
            </div>
        </div>

        <!-- DYNAMIC PROGRESS TRACK LINE -->
        <div style="background: rgba(15, 23, 42, 0.8); border: 1px solid var(--border-color); height: 22px; border-radius: 12px; overflow: hidden; position: relative; padding: 3px;">
            <div style="height: 100%; width: <?=$paidPercentage?>%; background: <?=$barColor?>; border-radius: 8px; transition: width 0.8s ease-in-out; box-shadow: 0 0 12px <?=$barColor?>;"></div>
        </div>

        <!-- DETAILS SUMMARY FOOTER -->
        <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; flex-wrap: wrap; gap: 12px; font-size: 0.85rem;">
            <div>Total EMI Payable: <strong style="color: #fff;"><?=money($totalPayable)?></strong></div>
            <div>Cleared Total EMI: <strong style="color: var(--success);"><?=money($emiPaid)?></strong> <span style="font-size:0.75rem; color:var(--text-muted);">(Down: <?=money($downPayment)?>)</span></div>
            <div>Remaining EMI Balance: <strong style="color: <?=$pendingBalance > 0 ? 'var(--danger)' : 'var(--success)'?>;"><?=money($pendingBalance)?></strong></div>
            <div>Grand Total Paid: <strong style="color: var(--success);"><?=money($totalPaid)?></strong></div>
        </div>
    </div>
<?php endif; ?>

<?php
$totalEmis = 0;
$unpaidEmis = 0;
if ($f) {
    $tStmt = $p->prepare('SELECT COUNT(*) FROM emi_schedules WHERE finance_id=?');
    $tStmt->execute([$f['id']]);
    $totalEmis = (int)$tStmt->fetchColumn();

    $uStmt = $p->prepare('SELECT COUNT(*) FROM emi_schedules WHERE finance_id=? AND status != "paid"');
    $uStmt->execute([$f['id']]);
    $unpaidEmis = (int)$uStmt->fetchColumn();
}
$isLoanFullyPaid = ($totalEmis > 0 && $unpaidEmis === 0 && in_array($f['status'] ?? '', ['approved', 'active', 'completed']));
?>

<!-- NEXT EMI DUE ALERT CARD OR CONGRATULATIONS NOC BANNER -->
<?php if ($f && $isLoanFullyPaid): ?>
    <div class="card" style="background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(59, 130, 246, 0.15)); border: 1px solid var(--success); margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; padding: 22px;">
        <div>
            <span class="badge badge-success" style="font-size: 0.82rem;">🎉 CONGRATULATIONS! LOAN FULLY REPAID</span>
            <h3 style="font-size: 1.25rem; font-weight: 800; color: #fff; margin-top: 6px;">Your Loan App <?=e($f['application_no'])?> is 100% Closed & Settled!</h3>
            <p class="muted" style="margin-top: 4px; font-size: 0.85rem;">All dues cleared. Your official No Objection Certificate (NOC) is ready to download.</p>
        </div>
        <div>
            <a href="<?=url('/loan-noc.php?id=' . $f['id'])?>" target="_blank" class="btn" style="background: linear-gradient(135deg, #10b981, #059669); color: #fff; font-weight: 800; font-size: 0.95rem; padding: 12px 22px; border-radius: 10px;">
                🎓 Download NOC Certificate (PDF)
            </a>
        </div>
    </div>
<?php elseif ($nextEmi && $f): ?>
    <div class="card" style="background: linear-gradient(135deg, rgba(30, 41, 59, 0.9), rgba(15, 23, 42, 0.95)); border: 1px solid var(--primary); margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
            <span class="badge badge-warning">UPCOMING EMI DUE</span>
            <h3 style="font-size: 1.2rem; font-weight: 800; color: #fff; margin-top: 6px;">Next EMI Installment #<?=$nextEmi['installment_no']?>: <?=money($nextEmi['amount'])?></h3>
            <p class="muted" style="margin-top: 4px;">Due Date: <strong style="color: #60a5fa;"><?=date('d M Y', strtotime($nextEmi['due_date']))?></strong></p>
        </div>
        <div>
            <a href="<?=url('/api/pay-installment.php?finance_id=' . $f['id'] . '&emi_id=' . $nextEmi['id'])?>" class="btn" style="background: linear-gradient(135deg, var(--primary), #1d4ed8); font-size: 0.95rem; padding: 12px 20px;">
                💳 Pay EMI Online Now via PayU
            </a>
        </div>
    </div>
<?php endif; ?>

<?php render_end(); ?>
