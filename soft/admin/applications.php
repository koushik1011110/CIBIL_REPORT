<?php 
require_once __DIR__.'/../includes/layout.php';
role('superadmin');

$p = db();
$q = $p->query('
    SELECT f.*, c.name as customer_name, c.mobile as customer_mobile, c.aadhaar_verified as cust_aadhaar_verified,
           ob.aadhaar_verified as ob_aadhaar_verified, p.name as product_name, s.name as shop_name,
           (SELECT COUNT(*) FROM emi_schedules e WHERE e.finance_id = f.id) as total_emi_count,
           (SELECT COUNT(*) FROM emi_schedules e WHERE e.finance_id = f.id AND e.status != "paid") as unpaid_count
    FROM finance_applications f 
    JOIN customers c ON c.id = f.customer_id 
    LEFT JOIN finance_application_onboarding ob ON ob.finance_id = f.id
    LEFT JOIN products p ON p.id = f.product_id 
    LEFT JOIN shops s ON s.id = f.shop_id 
    ORDER BY f.id DESC
');
$rows = $q->fetchAll();


start('All Finance Applications (Admin)');
?>

<?php if (isset($_GET['msg'])): ?>
    <?php if ($_GET['msg'] === 'paid_success' || $_GET['msg'] === 'success'): ?>
        <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
            <strong>✓ Success!</strong> Payment recorded & Finance Application status updated to Approved/Active.
        </div>
    <?php elseif ($_GET['msg'] === 'kyc_done'): ?>
        <div style="background: rgba(59, 130, 246, 0.15); border: 1px solid var(--primary); color: var(--primary); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
            <strong>✓ Onboarding & KYC Completed!</strong> Application is now ready for Down Payment clearance.
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h3 style="font-size: 1.1rem; font-weight: 800;">Global Finance Applications</h3>
        <p class="muted" style="margin-top: 4px;">Super admin oversight of all store loan applications</p>
    </div>
    <a href="credit-check.php" class="btn"><i data-lucide="plus-circle"></i> + New Application</a>
</div>

<div class="card" style="padding: 0; overflow-x: auto;">
    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <thead>
            <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                <th style="padding: 12px;">App No</th>
                <th style="padding: 12px;">Shop</th>
                <th style="padding: 12px;">Customer</th>
                <th style="padding: 12px;">Product</th>
                <th style="padding: 12px;">Financed Amount</th>
                <th style="padding: 12px;">Tenure & EMI</th>
                <th style="padding: 12px;">Status</th>
                <th style="padding: 12px;">Actions / Approval</th>
            </tr>
        </thead>
        <tbody>
            <?php if(empty($rows)): ?>
                <tr><td colspan="8" style="text-align: center; padding: 20px;">No finance applications found.</td></tr>
            <?php else: ?>
                <?php foreach($rows as $r): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 12px;"><strong><?=e($r['application_no'])?></strong><br><span style="font-size:0.75rem; color:var(--text-muted);"><?=date('d M Y, h:i A', strtotime($r['created_at']))?></span></td>
                        <td style="padding: 12px;"><strong><?=e($r['shop_name'] ?: 'Demo Store')?></strong></td>
                        <td style="padding: 12px;">
                            <strong><?=e($r['customer_name'])?></strong><br>
                            <span style="font-size:0.75rem; color:var(--text-muted);"><?=e($r['customer_mobile'])?></span>
                            <?php if (!empty($r['ob_aadhaar_verified'])): ?>
                                <br><span style="display:inline-flex; align-items:center; gap:4px; font-size:0.72rem; color:#137333; background:#e6f4ea; border:1px solid #ceead6; padding:2px 8px; border-radius:12px; font-weight:800; margin-top:4px;">🛡️ Verified by Aadhaar</span>
                            <?php endif; ?>
                        </td>

                        <td style="padding: 12px;"><?=e($r['product_name'] ?: 'Mobile Product')?></td>
                        <td style="padding: 12px;"><strong style="color:var(--primary);"><?=money($r['finance_amount'])?></strong></td>
                        <td style="padding: 12px;"><strong><?=money($r['emi'])?>/mo</strong><br><span style="font-size:0.75rem; color:var(--text-muted);"><?=e($r['tenure'])?> Months @ <?=e($r['interest_rate'])?>%</span></td>
                        <td style="padding: 12px;">
                            <?php if ($r['status'] === 'approved' || $r['status'] === 'active'): ?>
                                <span class="badge badge-success">APPROVED / ACTIVE</span>
                            <?php elseif ($r['status'] === 'kyc_completed'): ?>
                                <span class="badge badge-primary">KYC DONE (PENDING DOWN PAYMENT)</span>
                            <?php else: ?>
                                <span class="badge badge-warning">PENDING ONBOARDING</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px;">
                            <?php if ($r['status'] === 'pending'): ?>
                                <a href="<?=url('/application-process.php?id='.$r['id'])?>" class="btn" style="padding: 8px 14px; font-size: 0.8rem; background: var(--primary);">
                                    🚀 Proceed (Complete 4-Step KYC) →
                                </a>
                            <?php elseif ($r['status'] === 'kyc_completed'): ?>
                                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                    <form action="<?=url('/api/manual-pay-emi.php')?>" method="POST" style="display:inline;">
                                        <input type="hidden" name="finance_id" value="<?=$r['id']?>">
                                        <input type="hidden" name="amount" value="<?=$r['down_payment']?>">
                                        <input type="hidden" name="is_downpayment" value="1">
                                        <button type="submit" class="btn" style="padding: 8px 14px; font-size: 0.8rem; background: linear-gradient(135deg, var(--success), #059669);" onclick="return confirm('Clear Down Payment of <?=money($r['down_payment'])?> cash and approve application?')">
                                            💵 Clear Down Payment (<?=money($r['down_payment'])?>) & Approve
                                        </button>
                                    </form>
                                    <a href="<?=url('/application-process.php?id='.$r['id'])?>" class="btn" style="padding: 8px 12px; font-size: 0.78rem; background: rgba(59,130,246,0.12); color: var(--primary); border: 1px solid var(--border-color);">
                                        👁️ View Onboarding Data
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
<?php render_end(); ?>
