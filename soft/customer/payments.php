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

// Fetch Payments History
$payments = [];
if ($customerId > 0) {
    $q = $p->prepare('
        SELECT p.*, f.application_no 
        FROM payments p 
        LEFT JOIN finance_applications f ON f.id = p.finance_id 
        WHERE p.customer_id = ? OR f.customer_id = ? 
        ORDER BY p.id DESC
    ');
    $q->execute([$customerId, $customerId]);
    $payments = $q->fetchAll();
}

start('My Payment History');
?>

<div class="card" style="margin-bottom: 24px;">
    <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff;">My Loan Payment Transactions</h3>
    <p class="muted" style="margin-top: 4px;">Record of all online PayU gateway and manual cash payments made towards your loan</p>
</div>

<div class="card" style="padding: 0; overflow-x: auto;">
    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <thead>
            <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                <th style="padding: 12px;">#</th>
                <th style="padding: 12px;">Payment Date</th>
                <th style="padding: 12px;">Application No</th>
                <th style="padding: 12px;">Reference / Txn ID</th>
                <th style="padding: 12px;">Payment Method</th>
                <th style="padding: 12px;">Remarks</th>
                <th style="padding: 12px;">Amount Paid</th>
                <th style="padding: 12px;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($payments)): ?>
                <tr><td colspan="8" style="text-align: center; padding: 20px;">No payment receipts recorded yet.</td></tr>
            <?php else: ?>
                <?php foreach($payments as $idx => $r): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 12px;"><?=$idx + 1?></td>
                        <td style="padding: 12px;"><?=date('d M Y, h:i A', strtotime($r['paid_at']))?></td>
                        <td style="padding: 12px;"><strong><?=e($r['application_no'] ?: '-')?></strong></td>
                        <td style="padding: 12px;"><code><?=e($r['reference_no'] ?: '-')?></code></td>
                        <td style="padding: 12px;"><span class="badge badge-info"><?=e($r['payment_method'])?></span></td>
                        <td style="padding: 12px;"><?=e($r['remarks'] ?: 'EMI Payment')?></td>
                        <td style="padding: 12px;"><strong style="color:var(--success);"><?=money($r['amount'])?></strong></td>
                        <td style="padding: 12px;"><span class="badge badge-success">SUCCESS</span></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php render_end(); ?>
