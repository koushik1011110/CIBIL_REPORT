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

// Fetch All Applications & Target Application with EMI Schedules
$allApps = [];
$selectedAppId = isset($_GET['app_id']) ? (int)$_GET['app_id'] : (isset($_GET['finance_id']) ? (int)$_GET['finance_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0));
$f = null;
$emis = [];

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
        // Priority to loan that has generated EMI schedules
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
        $eStmt = $p->prepare('SELECT * FROM emi_schedules WHERE finance_id=? ORDER BY installment_no ASC');
        $eStmt->execute([$f['id']]);
        $emis = $eStmt->fetchAll();
    }
}

start('EMI Repayment Schedule');
?>

<?php if (count($allApps) > 1): ?>
    <div class="card" style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; padding: 14px 20px; background: rgba(30,41,59,0.7); border: 1px solid var(--border-color);">
        <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-weight: 700; color: #60a5fa; font-size: 0.9rem;">📱 Switch Loan Application:</span>
        </div>
        <div>
            <select onchange="window.location.href='?app_id=' + this.value" style="background: #0f172a; color: #fff; border: 1px solid #3b82f6; padding: 8px 14px; border-radius: 8px; font-size: 0.85rem;">
                <?php foreach ($allApps as $aItem): ?>
                    <option value="<?=$aItem['id']?>" <?=$f && $f['id'] == $aItem['id'] ? 'selected' : ''?>>
                        <?=e($aItem['application_no'])?> (<?=e($aItem['product_name'] ?: 'Loan')?>) — [<?=strtoupper($aItem['status'])?>]
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
<?php endif; ?>

<?php if (isset($_GET['msg']) && ($_GET['msg'] === 'success' || $_GET['msg'] === 'paid_success')): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
        <strong>✓ Payment Successful!</strong> EMI Installment updated to PAID. Thank you for your payment.
    </div>
<?php endif; ?>

<?php
$unpaidSum = 0;
$unpaidCount = 0;
foreach ($emis as $eItem) {
    if ($eItem['status'] !== 'paid') {
        $unpaidSum += floatval($eItem['amount']);
        $unpaidCount++;
    }
}
?>

<div class="card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff;">EMI Repayment Schedule</h3>
        <p class="muted" style="margin-top: 4px;">Loan App: <strong><?=e($f['application_no'] ?? 'N/A')?></strong> | Monthly EMI: <strong style="color:var(--primary);"><?=money($f['emi'] ?? 0)?></strong></p>
    </div>
    <div>
        <span class="badge badge-info" style="font-size: 0.85rem; padding: 8px 14px;">Total Tenure: <?=e($f['tenure'] ?? 0)?> Months</span>
    </div>
</div>

<?php if ($unpaidCount > 0 && $f): ?>
    <div class="card" style="margin-bottom: 20px; background: linear-gradient(135deg, rgba(16,185,129,0.15), rgba(59,130,246,0.1)); border: 1px solid rgba(16,185,129,0.4); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; padding: 18px 22px;">
        <div>
            <h4 style="font-size: 1.05rem; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 6px;">
                <span>🔥 Pay Full Outstanding Loan Balance (Foreclosure)</span>
            </h4>
            <p class="muted" style="font-size: 0.82rem; margin-top: 4px; color: #94a3b8;">
                Pay total remaining balance of <strong style="color: #10b981; font-size: 1rem;"><?=money($unpaidSum)?></strong> (<?=$unpaidCount?> pending month<?=$unpaidCount>1?'s':''?>) to close your loan immediately & get your instant NOC Certificate!
            </p>
        </div>
        <a href="<?=url('/api/pay-installment.php?finance_id=' . $f['id'] . '&emi_id=full')?>" class="btn" style="background: linear-gradient(135deg, #10b981, #059669); color: #fff; font-weight: 800; padding: 10px 22px; font-size: 0.9rem; border-radius: 10px;">
            ⚡ Pay Full <?=money($unpaidSum)?> & Close Loan
        </a>
    </div>
<?php endif; ?>

<div class="card" style="padding: 0; overflow-x: auto;">
    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <thead>
            <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                <th style="padding: 12px;">Installment #</th>
                <th style="padding: 12px;">Due Date</th>
                <th style="padding: 12px;">Principal</th>
                <th style="padding: 12px;">Interest</th>
                <th style="padding: 12px;">EMI Amount</th>
                <th style="padding: 12px;">Paid Amount</th>
                <th style="padding: 12px;">Status</th>
                <th style="padding: 12px;">Online Payment Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($emis)): ?>
                <tr><td colspan="8" style="text-align: center; padding: 20px;">No EMI schedules found for your loan.</td></tr>
            <?php else: ?>
                <?php foreach($emis as $eItem): ?>
                    <?php
                    $isPaid = ($eItem['status'] === 'paid');
                    $isOverdue = (!$isPaid && strtotime($eItem['due_date']) < strtotime(date('Y-m-d')));
                    ?>
                    <tr style="border-bottom: 1px solid var(--border-color); background: <?=$isOverdue ? 'rgba(239, 68, 68, 0.05)' : 'transparent'?>">
                        <td style="padding: 12px;"><strong>Installment #<?=$eItem['installment_no']?></strong></td>
                        <td style="padding: 12px;"><strong style="color: <?=$isOverdue ? 'var(--danger)' : '#fff'?>;"><?=date('d M Y', strtotime($eItem['due_date']))?></strong></td>
                        <td style="padding: 12px;"><?=money($eItem['principal'])?></td>
                        <td style="padding: 12px;"><?=money($eItem['interest'])?></td>
                        <td style="padding: 12px;"><strong style="color:var(--primary);"><?=money($eItem['amount'])?></strong></td>
                        <td style="padding: 12px;"><?=money($eItem['paid_amount'])?></td>
                        <td style="padding: 12px;">
                            <?php if ($isPaid): ?>
                                <span class="badge badge-success">PAID ON <?=date('d M Y', strtotime($eItem['paid_at'] ?: date('Y-m-d')))?></span>
                            <?php elseif ($isOverdue): ?>
                                <span class="badge badge-danger">OVERDUE</span>
                            <?php else: ?>
                                <span class="badge badge-warning">UPCOMING DUE</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px;">
                            <?php if (!$isPaid && $f): ?>
                                <a href="<?=url('/api/pay-installment.php?finance_id=' . $f['id'] . '&emi_id=' . $eItem['id'])?>" class="btn" style="padding: 6px 12px; font-size: 0.8rem; background: linear-gradient(135deg, var(--primary), #1d4ed8);">
                                    💳 Pay Online via PayU
                                </a>
                            <?php else: ?>
                                <span style="color: var(--success); font-weight: 700; font-size: 0.8rem;">✓ Cleared</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php render_end(); ?>
