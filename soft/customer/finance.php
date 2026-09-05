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

// Fetch All Finance Applications for Customer
$apps = [];
if ($customerId > 0) {
    $q = $p->prepare('
        SELECT f.*, p.name as product_name 
        FROM finance_applications f 
        LEFT JOIN products p ON p.id = f.product_id 
        WHERE f.customer_id = ? 
        ORDER BY f.id DESC
    ');
    $q->execute([$customerId]);
    $apps = $q->fetchAll();
}

start('My Store Finance Loans');
?>

<div class="card" style="margin-bottom: 24px;">
    <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff;">My Store Loans & Finance Summary</h3>
    <p class="muted" style="margin-top: 4px;">Overview of your product financing, interest rates, and loan statuses</p>
</div>

<div class="card" style="padding: 0; overflow-x: auto;">
    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <thead>
            <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                <th style="padding: 12px;">App No</th>
                <th style="padding: 12px;">Product</th>
                <th style="padding: 12px;">Price / Down Payment</th>
                <th style="padding: 12px;">Financed Loan</th>
                <th style="padding: 12px;">Monthly EMI</th>
                <th style="padding: 12px;">Total Payable</th>
                <th style="padding: 12px;">Paid / Outstanding</th>
                <th style="padding: 12px;">Status</th>
                <th style="padding: 12px;">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($apps)): ?>
                <tr><td colspan="9" style="text-align: center; padding: 20px;">No finance applications found for your account.</td></tr>
            <?php else: ?>
                <?php foreach($apps as $r): ?>
                    <?php
                    // Calculate EMI Paid & EMI Dues from emi_schedules
                    $emiPaidStmt = $p->prepare("SELECT COALESCE(SUM(paid_amount), 0) FROM emi_schedules WHERE finance_id = ? AND status = 'paid'");
                    $emiPaidStmt->execute([$r['id']]);
                    $emiPaid = floatval($emiPaidStmt->fetchColumn());

                    $emiDueStmt = $p->prepare("SELECT COALESCE(SUM(amount), 0) FROM emi_schedules WHERE finance_id = ? AND status != 'paid'");
                    $emiDueStmt->execute([$r['id']]);
                    $emiDue = floatval($emiDueStmt->fetchColumn());

                    $totalStmt = $p->prepare("SELECT COUNT(*) FROM emi_schedules WHERE finance_id = ?");
                    $totalStmt->execute([$r['id']]);
                    $totalEmis = (int)$totalStmt->fetchColumn();

                    $unpaidStmt = $p->prepare("SELECT COUNT(*) FROM emi_schedules WHERE finance_id = ? AND status != 'paid'");
                    $unpaidStmt->execute([$r['id']]);
                    $unpaidEmis = (int)$unpaidStmt->fetchColumn();

                    $downPayment = floatval($r['down_payment'] ?? 0);
                    $totalPaid = $downPayment + $emiPaid;
                    $isLoanFullyPaid = ($totalEmis > 0 && $unpaidEmis === 0 && in_array($r['status'], ['approved', 'active', 'completed']));
                    ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 12px;"><strong><?=e($r['application_no'])?></strong><br><span style="font-size:0.75rem; color:var(--text-muted);"><?=date('d M Y', strtotime($r['created_at']))?></span></td>
                        <td style="padding: 12px;"><?=e($r['product_name'] ?: 'Mobile Product')?></td>
                        <td style="padding: 12px;"><?=money($r['product_price'])?><br><span style="font-size:0.75rem; color:var(--text-muted);">Down: <?=money($r['down_payment'])?></span></td>
                        <td style="padding: 12px;"><strong style="color:var(--primary);"><?=money($r['finance_amount'])?></strong></td>
                        <td style="padding: 12px;"><strong><?=money($r['emi'])?>/mo</strong><br><span style="font-size:0.75rem; color:var(--text-muted);"><?=e($r['tenure'])?> Mos @ <?=e($r['interest_rate'])?>%</span></td>
                        <td style="padding: 12px;"><?=money($r['total_payable'])?></td>
                        <td style="padding: 12px;">
                            <span style="color: var(--success); font-weight: 700;"><?=money($totalPaid)?> Paid</span><br>
                            <span style="font-size:0.72rem; color:var(--text-muted);">(Down: <?=money($downPayment)?> | EMI: <?=money($emiPaid)?>)</span><br>
                            <span style="color: var(--danger); font-size: 0.78rem; font-weight: 700;"><?=money($emiDue)?> Dues (<?=$unpaidEmis?> Mos)</span>
                        </td>
                        <td style="padding: 12px;">
                            <?php if ($isLoanFullyPaid): ?>
                                <span class="badge badge-success">CLOSED / 100% PAID</span>
                            <?php elseif ($r['status'] === 'approved' || $r['status'] === 'active'): ?>
                                <span class="badge badge-success">ACTIVE / APPROVED</span>
                            <?php else: ?>
                                <span class="badge badge-warning">PENDING (APPROVAL IN PROGRESS)</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px;">
                            <div style="display: flex; gap: 6px; flex-direction: column;">
                                <a class="btn" style="padding: 4px 8px; font-size: 0.75rem; background: var(--primary);" href="<?=url('/customer/emi-schedule.php?app_id='.$r['id'])?>">View EMI Schedule</a>
                                <a class="btn" style="padding: 4px 8px; font-size: 0.75rem; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff;" href="<?=url('/loan-agreement.php?id='.$r['id'])?>" target="_blank">📜 Loan Agreement</a>
                                <?php if ($isLoanFullyPaid): ?>
                                    <a class="btn" style="padding: 6px 10px; font-size: 0.78rem; background: linear-gradient(135deg, #059669, #10b981); color: #fff; font-weight: 800;" href="<?=url('/loan-noc.php?id='.$r['id'])?>" target="_blank">🎓 Download NOC Certificate</a>
                                <?php else: ?>
                                    <span class="badge badge-warning" style="font-size: 0.7rem; text-align: center;" title="NOC Certificate unlocks automatically after paying all dues">🔒 NOC Locked</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php render_end(); ?>
