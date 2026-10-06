<?php 
require_once __DIR__.'/../includes/layout.php';
role('superadmin');

$p = db();
$filter = trim($_GET['filter'] ?? 'all');

$whereClause = '';
$params = [];
if ($filter === 'pending_approval') {
    $whereClause = "WHERE f.status = 'pending_approval'";
} elseif ($filter === 'kyc_completed') {
    $whereClause = "WHERE f.status = 'kyc_completed'";
} elseif ($filter === 'approved') {
    $whereClause = "WHERE f.status IN ('approved', 'active')";
} elseif ($filter === 'rejected') {
    $whereClause = "WHERE f.status = 'rejected'";
} elseif ($filter === 'pending') {
    $whereClause = "WHERE f.status = 'pending'";
}

$sql = "
    SELECT f.*, c.name as customer_name, c.mobile as customer_mobile, c.aadhaar_verified as cust_aadhaar_verified,
           ob.aadhaar_verified as ob_aadhaar_verified, COALESCE(f.product_name, p.name) as product_name, s.name as shop_name,
           (SELECT COUNT(*) FROM emi_schedules e WHERE e.finance_id = f.id) as total_emi_count,
           (SELECT COUNT(*) FROM emi_schedules e WHERE e.finance_id = f.id AND e.status != 'paid') as unpaid_count,
           (SELECT COALESCE(SUM(amount), 0) FROM payments py WHERE py.finance_id = f.id AND (py.emi_id IS NULL OR py.remarks LIKE '%Down Payment%')) as down_payment_paid
    FROM finance_applications f 
    JOIN customers c ON c.id = f.customer_id 
    LEFT JOIN finance_application_onboarding ob ON ob.finance_id = f.id
    LEFT JOIN products p ON p.id = f.product_id 
    LEFT JOIN shops s ON s.id = f.shop_id 
    {$whereClause}
    ORDER BY (CASE WHEN f.status = 'pending_approval' THEN 0 WHEN f.status = 'kyc_completed' THEN 1 ELSE 2 END), f.id DESC
";

$stmt = $p->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Get counts for filter pills
$counts = [
    'all' => (int)$p->query("SELECT COUNT(*) FROM finance_applications")->fetchColumn(),
    'pending_approval' => (int)$p->query("SELECT COUNT(*) FROM finance_applications WHERE status = 'pending_approval'")->fetchColumn(),
    'kyc_completed' => (int)$p->query("SELECT COUNT(*) FROM finance_applications WHERE status = 'kyc_completed'")->fetchColumn(),
    'approved' => (int)$p->query("SELECT COUNT(*) FROM finance_applications WHERE status IN ('approved', 'active')")->fetchColumn(),
    'rejected' => (int)$p->query("SELECT COUNT(*) FROM finance_applications WHERE status = 'rejected'")->fetchColumn(),
];

start('All Finance Applications (Admin)');
?>

<?php if (isset($_GET['msg'])): ?>
    <?php if ($_GET['msg'] === 'app_approved'): ?>
        <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
            <strong>🎉 Loan Application Approved!</strong> Application #<?=e($_GET['app_no'] ?? '')?> has been sanctioned & approved by Superadmin. Customer credentials have been dispatched.
        </div>
    <?php elseif ($_GET['msg'] === 'app_rejected'): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
            <strong>✓ Loan Application Rejected:</strong> Application #<?=e($_GET['app_no'] ?? '')?> has been marked as rejected.
        </div>
    <?php elseif ($_GET['msg'] === 'downpayment_recorded'): ?>
        <div style="background: rgba(245, 158, 11, 0.15); border: 1px solid #f59e0b; color: #f59e0b; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
            <strong>✓ Down Payment Recorded!</strong> The application is now ready in the Superadmin approval queue.
        </div>
    <?php elseif ($_GET['msg'] === 'paid_success' || $_GET['msg'] === 'success'): ?>
        <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
            <strong>✓ Success!</strong> Payment recorded successfully.
        </div>
    <?php elseif ($_GET['msg'] === 'kyc_done'): ?>
        <div style="background: rgba(59, 130, 246, 0.15); border: 1px solid var(--primary); color: var(--primary); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
            <strong>✓ Onboarding & KYC Completed!</strong> Application is ready for Down Payment or Direct Superadmin Approval.
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- HEADER & ACTION -->
<div class="card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h3 style="font-size: 1.15rem; font-weight: 800;">Global Finance Applications & Superadmin Approval Hub</h3>
        <p class="muted" style="margin-top: 4px;">Review merchant loan requests, verify KYC data, and sanction loan approvals</p>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="credit-check.php" class="btn"><i data-lucide="plus-circle"></i> + New Application</a>
    </div>
</div>

<!-- FILTER TABS -->
<div style="display: flex; gap: 8px; margin-bottom: 18px; overflow-x: auto; padding-bottom: 6px;">
    <a href="applications.php?filter=all" class="btn" style="padding: 7px 14px; font-size: 0.8rem; background: <?=$filter==='all'?'var(--primary)':'rgba(255,255,255,0.06)'?>; border: 1px solid var(--border-color); color: #fff;">
        All Loans (<?=$counts['all']?>)
    </a>
    <a href="applications.php?filter=pending_approval" class="btn" style="padding: 7px 14px; font-size: 0.8rem; background: <?=$filter==='pending_approval'?'#f59e0b':'rgba(245, 158, 11, 0.15)'?>; border: 1px solid #f59e0b; color: <?=$filter==='pending_approval'?'#000':'#f59e0b'?>; font-weight: 800;">
        ⚡ Awaiting Superadmin Approval (<?=$counts['pending_approval']?>)
    </a>
    <a href="applications.php?filter=kyc_completed" class="btn" style="padding: 7px 14px; font-size: 0.8rem; background: <?=$filter==='kyc_completed'?'var(--primary)':'rgba(59,130,246,0.1)'?>; border: 1px solid var(--border-color); color: #fff;">
        KYC Done / Down Payment Due (<?=$counts['kyc_completed']?>)
    </a>
    <a href="applications.php?filter=approved" class="btn" style="padding: 7px 14px; font-size: 0.8rem; background: <?=$filter==='approved'?'var(--success)':'rgba(16,185,129,0.1)'?>; border: 1px solid var(--border-color); color: #fff;">
        Approved / Active (<?=$counts['approved']?>)
    </a>
    <a href="applications.php?filter=rejected" class="btn" style="padding: 7px 14px; font-size: 0.8rem; background: <?=$filter==='rejected'?'var(--danger)':'rgba(239,68,68,0.1)'?>; border: 1px solid var(--border-color); color: #fff;">
        Rejected (<?=$counts['rejected']?>)
    </a>
</div>

<div class="card" style="padding: 0; overflow-x: auto;">
    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <thead>
            <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                <th style="padding: 12px;">App No</th>
                <th style="padding: 12px;">Shop</th>
                <th style="padding: 12px;">Customer</th>
                <th style="padding: 12px;">Product</th>
                <th style="padding: 12px;">Price / Down Payment</th>
                <th style="padding: 12px;">Financed Amount</th>
                <th style="padding: 12px;">Tenure & EMI</th>
                <th style="padding: 12px;">Status</th>
                <th style="padding: 12px;">Superadmin Actions / Approval</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($rows)): ?>
                <tr><td colspan="9" style="text-align: center; padding: 30px; color: var(--text-muted);">No finance applications found matching the selected filter.</td></tr>
            <?php else: ?>
                <?php foreach($rows as $r): ?>
                    <tr style="border-bottom: 1px solid var(--border-color); <?=$r['status']==='pending_approval'?'background: rgba(245, 158, 11, 0.04);':''?>">
                        <td style="padding: 12px;">
                            <strong><?=e($r['application_no'])?></strong><br>
                            <span style="font-size:0.75rem; color:var(--text-muted);"><?=date('d M Y, h:i A', strtotime($r['created_at']))?></span>
                        </td>
                        <td style="padding: 12px;">
                            <strong><?=e($r['shop_name'] ?: 'Demo Store')?></strong>
                        </td>
                        <td style="padding: 12px;">
                            <strong><?=e($r['customer_name'])?></strong><br>
                            <span style="font-size:0.75rem; color:var(--text-muted);"><?=e($r['customer_mobile'])?></span>
                            <?php if (!empty($r['ob_aadhaar_verified'])): ?>
                                <br><span style="display:inline-flex; align-items:center; gap:4px; font-size:0.72rem; color:#137333; background:#e6f4ea; border:1px solid #ceead6; padding:2px 8px; border-radius:12px; font-weight:800; margin-top:4px;">🛡️ Verified by Aadhaar</span>
                            <?php endif; ?>
                        </td>

                        <td style="padding: 12px;">
                            <strong><?=e($r['product_name'] ?: 'Mobile Product')?></strong>
                            <?php if (!empty($r['imei_number'])): ?>
                                <br><span style="display:inline-flex; align-items:center; gap:4px; font-size:0.72rem; color:#0284c7; background:rgba(2,132,199,0.1); border:1px solid rgba(2,132,199,0.25); padding:2px 7px; border-radius:6px; font-weight:700; margin-top:4px; font-family:monospace;">
                                    📱 IMEI: <?=e($r['imei_number'])?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px;">
                            <?=money($r['product_price'])?><br>
                            <span style="font-size:0.75rem; color:var(--text-muted);">Down: <?=money($r['down_payment'])?></span>
                            <?php if (floatval($r['down_payment']) > 0): ?>
                                <?php if (floatval($r['down_payment_paid'] ?? 0) >= floatval($r['down_payment'])): ?>
                                    <br><span style="font-size:0.7rem; color: var(--success); font-weight: 800;">✓ Down Payment Paid</span>
                                <?php else: ?>
                                    <br><span style="font-size:0.7rem; color: #f59e0b; font-weight: 700;">⚠ Down Payment Due</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px;">
                            <strong style="color:var(--primary);"><?=money($r['finance_amount'])?></strong>
                            <?php 
                            $addons = [];
                            if (floatval($r['processing_fee'] ?? 0) > 0) $addons[] = 'PF: ' . money($r['processing_fee']);
                            if (floatval($r['insurance_fee'] ?? 0) > 0) $addons[] = 'Ins: ' . money($r['insurance_fee']);
                            if (!empty($addons)):
                            ?>
                                <br><span style="font-size:0.7rem; color:var(--text-muted);">(<?=implode(', ', $addons)?>)</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px;"><strong><?=money($r['emi'])?>/mo</strong><br><span style="font-size:0.75rem; color:var(--text-muted);"><?=e($r['tenure'])?> Months</span></td>
                        <td style="padding: 12px;">
                            <?php if ($r['status'] === 'approved' || $r['status'] === 'active'): ?>
                                <span class="badge badge-success">APPROVED / ACTIVE</span>
                            <?php elseif ($r['status'] === 'pending_approval'): ?>
                                <span class="badge" style="background: rgba(245, 158, 11, 0.2); color: #f59e0b; border: 1px solid #f59e0b; font-weight: 800; padding: 4px 8px; border-radius: 6px;">⚡ AWAITING YOUR APPROVAL</span>
                            <?php elseif ($r['status'] === 'kyc_completed'): ?>
                                <span class="badge badge-primary">KYC DONE (PENDING DOWN PAYMENT)</span>
                            <?php elseif ($r['status'] === 'rejected'): ?>
                                <span class="badge" style="background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.4);">REJECTED</span>
                            <?php else: ?>
                                <span class="badge badge-warning">PENDING ONBOARDING</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px;">
                            <?php if ($r['status'] === 'pending_approval'): ?>
                                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                                    <form action="<?=url('/api/admin-approve-application.php')?>" method="POST" style="display:inline;">
                                        <input type="hidden" name="finance_id" value="<?=$r['id']?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn" style="padding: 8px 14px; font-size: 0.8rem; background: linear-gradient(135deg, #10b981, #059669); color: #fff; font-weight: 800; box-shadow: 0 2px 8px rgba(16,185,129,0.3);" onclick="return confirm('Approve & Sanction Loan Application #<?=e($r['application_no'])?> for <?=e($r['customer_name'])?>?\n\nThis will officially approve the loan and dispatch customer credentials.')">
                                            ✅ Approve & Sanction Loan
                                        </button>
                                    </form>
                                    <button type="button" class="btn" style="padding: 8px 12px; font-size: 0.78rem; background: rgba(239, 68, 68, 0.15); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.4);" onclick="handleRejectClick(<?=$r['id']?>, '<?=e($r['application_no'])?>')">
                                        ❌ Reject
                                    </button>
                                    <a href="<?=url('/application-process.php?id='.$r['id'])?>" class="btn" style="padding: 8px 10px; font-size: 0.78rem; background: rgba(59,130,246,0.12); color: var(--primary); border: 1px solid var(--border-color);">
                                        👁️ View KYC Data
                                    </a>
                                </div>
                            <?php elseif ($r['status'] === 'kyc_completed'): ?>
                                <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                                    <form action="<?=url('/api/manual-pay-emi.php')?>" method="POST" style="display:inline;">
                                        <input type="hidden" name="finance_id" value="<?=$r['id']?>">
                                        <input type="hidden" name="amount" value="<?=$r['down_payment']?>">
                                        <input type="hidden" name="is_downpayment" value="1">
                                        <button type="submit" class="btn" style="padding: 7px 12px; font-size: 0.78rem; background: linear-gradient(135deg, #f59e0b, #d97706); color:#fff; font-weight: 700;" onclick="return confirm('Record Down Payment of <?=money($r['down_payment'])?> cash for this application?')">
                                            💵 Record Down Payment
                                        </button>
                                    </form>
                                    <form action="<?=url('/api/admin-approve-application.php')?>" method="POST" style="display:inline;">
                                        <input type="hidden" name="finance_id" value="<?=$r['id']?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn" style="padding: 7px 12px; font-size: 0.78rem; background: linear-gradient(135deg, #10b981, #059669); color:#fff; font-weight:700;" onclick="return confirm('Directly Approve & Sanction loan application #<?=e($r['application_no'])?>?')">
                                            ⚡ Direct Approve
                                        </button>
                                    </form>
                                    <a href="<?=url('/application-process.php?id='.$r['id'])?>" class="btn" style="padding: 7px 10px; font-size: 0.78rem; background: rgba(59,130,246,0.12); color: var(--primary); border: 1px solid var(--border-color);">
                                        👁️ View KYC Data
                                    </a>
                                </div>
                            <?php elseif ($r['status'] === 'pending'): ?>
                                <a href="<?=url('/application-process.php?id='.$r['id'])?>" class="btn" style="padding: 8px 14px; font-size: 0.8rem; background: var(--primary);">
                                    🚀 Complete 4-Step KYC →
                                </a>
                            <?php elseif ($r['status'] === 'rejected'): ?>
                                <div style="display: flex; gap: 6px; align-items: center;">
                                    <span style="font-size: 0.78rem; color: #ef4444; font-weight: 700;">❌ Rejected</span>
                                    <form action="<?=url('/api/admin-approve-application.php')?>" method="POST" style="display:inline;">
                                        <input type="hidden" name="finance_id" value="<?=$r['id']?>">
                                        <input type="hidden" name="action" value="approve">
                                        <button type="submit" class="btn" style="padding: 4px 10px; font-size: 0.74rem; background: rgba(16,185,129,0.15); color: #10b981; border: 1px solid #10b981;" onclick="return confirm('Re-approve this previously rejected application?')">
                                            ↺ Re-approve
                                        </button>
                                    </form>
                                    <a href="<?=url('/application-process.php?id='.$r['id'])?>" class="btn" style="padding: 4px 8px; font-size: 0.74rem; background: rgba(255,255,255,0.06); color: var(--text-muted); border: 1px solid var(--border-color);">
                                        👁️ KYC
                                    </a>
                                </div>
                            <?php else: 
                                 $totalEmiCount = intval($r['total_emi_count'] ?? 0);
                                 $unpaidCount = intval($r['unpaid_count'] ?? 0);
                                 $isNocUnlocked = ($totalEmiCount > 0 && $unpaidCount === 0 && in_array($r['status'], ['approved', 'active', 'completed']));
                             ?>
                                 <div style="display: flex; gap: 6px; align-items: center; flex-wrap: wrap;">
                                     <span style="font-size: 0.78rem; color: var(--success); font-weight: 800; display: block; width: 100%;"><?=$isNocUnlocked ? '✓ Closed / 100% Paid' : '✓ Active / Approved'?></span>
                                      <a href="<?=url('/loan-agreement.php?id='.$r['id'])?>" target="_blank" class="btn" style="padding: 5px 10px; font-size: 0.75rem; background: linear-gradient(135deg, #3b82f6, #1d4ed8); color: #fff;">
                                          📜 Loan Agreement
                                      </a>
                                      <a href="<?=url('/admin/documents.php?finance_id='.$r['id'])?>" class="btn" style="padding: 5px 10px; font-size: 0.75rem; background: #0f172a; color: #38bdf8; border: 1px solid #0284c7;" title="View & Generate all 11 Loan Documents">
                                          📄 Documents
                                      </a>
                                      <?php if ($isNocUnlocked): ?>
                                          <a href="<?=url('/loan-noc.php?id='.$r['id'])?>" target="_blank" class="btn" style="padding: 5px 10px; font-size: 0.75rem; background: linear-gradient(135deg, #059669, #10b981); color: #fff;">
                                              🎓 NOC Certificate
                                          </a>
                                      <?php else: ?>
                                          <span class="badge badge-warning" style="font-size: 0.72rem;" title="NOC Certificate unlocks automatically after paying all dues">🔒 NOC Locked</span>
                                      <?php endif; ?>
                                     <a href="<?=url('/application-process.php?id='.$r['id'])?>" class="btn" style="padding: 5px 8px; font-size: 0.75rem; background: rgba(255,255,255,0.06); color: var(--text-muted); border: 1px solid var(--border-color);">
                                         👁️ View KYC
                                     </a>
                                 </div>
                             <?php endif; ?>
                        </td>

                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function handleRejectClick(financeId, appNo) {
    const reason = prompt('Please enter the reason for rejecting Loan Application #' + appNo + ':', 'KYC / document discrepancy or credit eligibility criteria not met.');
    if (reason !== null && reason.trim() !== '') {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '<?=url('/api/admin-approve-application.php')?>';

        const fId = document.createElement('input');
        fId.type = 'hidden';
        fId.name = 'finance_id';
        fId.value = financeId;
        form.appendChild(fId);

        const act = document.createElement('input');
        act.type = 'hidden';
        act.name = 'action';
        act.value = 'reject';
        form.appendChild(act);

        const rsn = document.createElement('input');
        rsn.type = 'hidden';
        rsn.name = 'rejection_reason';
        rsn.value = reason.trim();
        form.appendChild(rsn);

        document.body.appendChild(form);
        form.submit();
    }
}
</script>

<?php render_end(); ?>

