<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/document_engine.php';

role('customer');

$p = db();
$user = u();

// Customer Lookup matching customer portal pattern
$userEmail = $user['email'] ?? '';
$userName  = $user['name'] ?? '';
$mobileFromEmail = str_replace('@customer.local', '', $userEmail);

$s = $p->prepare('
    SELECT * FROM customers 
    WHERE (email != "" AND email = ?) 
       OR (mobile != "" AND (mobile = ? OR mobile = ?)) 
       OR name = ? 
    LIMIT 1
');
$s->execute([$userEmail, $userEmail, $mobileFromEmail, $userName]);
$customer = $s->fetch() ?: [];
$customerId = (int)($customer['id'] ?? 0);

// Fetch Loans for this customer
$loans = [];
$payments = [];
$docHistory = [];

if ($customerId > 0) {
    $lStmt = $p->prepare("
        SELECT f.*, p.name as product_name,
               (SELECT COUNT(*) FROM emi_schedules e WHERE e.finance_id = f.id) as total_emi_count,
               (SELECT COUNT(*) FROM emi_schedules e WHERE e.finance_id = f.id AND e.status != 'paid') as unpaid_count
        FROM finance_applications f
        LEFT JOIN products p ON p.id = f.product_id
        WHERE f.customer_id = ?
        ORDER BY f.id DESC
    ");
    $lStmt->execute([$customerId]);
    $loans = $lStmt->fetchAll();

    // Fetch Payments
    $payStmt = $p->prepare("
        SELECT p.*, f.application_no, e.installment_no
        FROM payments p
        JOIN finance_applications f ON f.id = p.finance_id
        LEFT JOIN emi_schedules e ON e.id = p.emi_id
        WHERE p.customer_id = ? OR f.customer_id = ?
        ORDER BY p.id DESC
    ");
    $payStmt->execute([$customerId, $customerId]);
    $payments = $payStmt->fetchAll();

    // Fetch Document History
    $histStmt = $p->prepare("
        SELECT d.*, f.application_no
        FROM documents d
        LEFT JOIN finance_applications f ON f.id = d.finance_id
        WHERE d.customer_id = ?
        ORDER BY d.id DESC
    ");
    $histStmt->execute([$customerId]);
    $docHistory = $histStmt->fetchAll();
}

$docTypesList = [
    'loan_agreement'        => '📜 Loan Agreement',
    'repayment_schedule'    => '📅 EMI Repayment Schedule',
    'sanction_letter'       => '📝 Sanction Letter',
    'disbursement_letter'   => '💵 Disbursement Letter',
    'account_statement'     => '📊 Account Statement (SOA)',
    'outstanding_statement' => '⚠️ Outstanding Statement',
    'foreclosure_statement' => '🔥 Foreclosure Statement',
    'noc_certificate'       => '🎓 NOC Certificate',
    'loan_closure'          => '🏆 Loan Closure Certificate'
];

start('My Loan Documents & Certificates');
?>

<style>
.doc-card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; margin-bottom: 24px; }
.doc-loan-card { background: var(--bg-card, #1e293b); border: 1px solid var(--border-color, #334155); border-radius: var(--radius-md, 10px); padding: 20px; display: flex; flex-direction: column; justify-content: space-between; }
.doc-btn-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 14px; }
.doc-btn { padding: 8px 10px; border-radius: 6px; font-size: 0.78rem; font-weight: 700; text-decoration: none; text-align: center; display: inline-flex; align-items: center; justify-content: center; gap: 4px; transition: all 0.2s; }
.doc-btn-primary { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
.doc-btn-primary:hover { background: var(--primary); color: #fff; }
.doc-btn-success { background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3); }
.doc-btn-success:hover { background: #059669; color: #fff; }
.doc-btn-locked { background: rgba(255,255,255,0.04); color: var(--text-muted); border: 1px dashed var(--border-color); cursor: not-allowed; }
</style>

<div class="card" style="margin-bottom: 24px;">
    <h3 style="font-size: 1.2rem; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
        <i data-lucide="file-check" style="color: var(--primary);"></i> My Loan Documents & Certificates
    </h3>
    <p class="muted" style="margin-top: 4px;">Instantly access, print, and download PDF copies of your agreements, schedules, payment receipts, and NOCs</p>
</div>

<!-- LOAN-WISE DOCUMENTS -->
<h4 style="font-size: 1rem; font-weight: 800; color: #fff; margin-bottom: 14px; display: flex; align-items: center; gap: 6px;">
    <i data-lucide="wallet" style="color: #38bdf8;"></i> Active & Closed Loan Document Vault
</h4>

<?php if (empty($loans)): ?>
    <div class="card" style="text-align: center; padding: 40px;">
        <p class="muted">No loan applications found for your account.</p>
    </div>
<?php else: ?>
    <div class="doc-card-grid">
        <?php foreach ($loans as $l): 
            $totalEmiCount = (int)$l['total_emi_count'];
            $unpaidCount = (int)$l['unpaid_count'];
            $isNocUnlocked = ($totalEmiCount > 0 && $unpaidCount === 0 && in_array($l['status'], ['approved', 'active', 'completed']));
        ?>
            <div class="doc-loan-card">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                        <div>
                            <span style="font-size: 0.75rem; color: var(--text-muted); text-transform: uppercase;">Loan Account</span>
                            <h4 style="margin: 2px 0 0 0; font-size: 1.05rem; font-weight: 800; color: #fff;">
                                #<?=e($l['application_no'])?>
                            </h4>
                        </div>
                        <?php if ($isNocUnlocked): ?>
                            <span class="badge badge-success">✓ 100% PAID / CLOSED</span>
                        <?php elseif (in_array($l['status'], ['approved', 'active'])): ?>
                            <span class="badge badge-success">ACTIVE LOAN</span>
                        <?php else: ?>
                            <span class="badge badge-warning"><?=strtoupper($l['status'])?></span>
                        <?php endif; ?>
                    </div>

                    <p style="font-size: 0.85rem; color: var(--text-muted); margin: 6px 0;">
                        Product: <strong style="color: #fff;"><?=e($l['product_name'] ?: 'Mobile Finance')?></strong> · Loan: <strong><?=money($l['finance_amount'])?></strong>
                    </p>
                    <p style="font-size: 0.8rem; color: var(--text-muted); margin: 0;">
                        EMI: <strong><?=money($l['emi'])?>/mo</strong> · Remaining Dues: <strong style="color: <?=($unpaidCount > 0 ? '#f87171' : '#34d399')?>;"><?=$unpaidCount?> Month(s)</strong>
                    </p>
                </div>

                <div class="doc-btn-grid">
                    <a href="<?=url('/view-document.php?type=loan_agreement&id=' . $l['id'])?>" target="_blank" class="doc-btn doc-btn-primary">
                        📜 Loan Agreement
                    </a>
                    <a href="<?=url('/view-document.php?type=repayment_schedule&id=' . $l['id'])?>" target="_blank" class="doc-btn doc-btn-primary">
                        📅 EMI Schedule
                    </a>
                    <a href="<?=url('/view-document.php?type=sanction_letter&id=' . $l['id'])?>" target="_blank" class="doc-btn doc-btn-primary">
                        📝 Sanction Letter
                    </a>
                    <a href="<?=url('/view-document.php?type=account_statement&id=' . $l['id'])?>" target="_blank" class="doc-btn doc-btn-primary">
                        📊 Account SOA
                    </a>
                    <a href="<?=url('/view-document.php?type=outstanding_statement&id=' . $l['id'])?>" target="_blank" class="doc-btn doc-btn-primary">
                        ⚠️ Outstanding
                    </a>
                    <a href="<?=url('/view-document.php?type=foreclosure_statement&id=' . $l['id'])?>" target="_blank" class="doc-btn doc-btn-primary">
                        🔥 Foreclosure
                    </a>

                    <?php if ($isNocUnlocked): ?>
                        <a href="<?=url('/view-document.php?type=noc_certificate&id=' . $l['id'])?>" target="_blank" class="doc-btn doc-btn-success" style="grid-column: span 2; font-weight: 800;">
                            🎓 Download NOC Certificate
                        </a>
                        <a href="<?=url('/view-document.php?type=loan_closure&id=' . $l['id'])?>" target="_blank" class="doc-btn doc-btn-success" style="grid-column: span 2; font-weight: 800;">
                            🏆 Loan Closure Certificate
                        </a>
                    <?php else: ?>
                        <div class="doc-btn doc-btn-locked" style="grid-column: span 2;" title="NOC unlocks automatically once all EMIs are paid in full">
                            🔒 NOC & Closure Locked (<?=$unpaidCount?> EMIs remaining)
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- PAYMENT RECEIPTS SECTION -->
<div class="card" style="margin-bottom: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
        <h4 style="margin: 0; font-size: 1rem; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 6px;">
            <i data-lucide="receipt" style="color: #10b981;"></i> My Payment Receipts
        </h4>
    </div>

    <div style="overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                    <th style="padding: 10px;">Date</th>
                    <th style="padding: 10px;">Loan App #</th>
                    <th style="padding: 10px;">Reference ID</th>
                    <th style="padding: 10px;">Method</th>
                    <th style="padding: 10px;">Amount</th>
                    <th style="padding: 10px; text-align: right;">Receipt</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payments)): ?>
                    <tr><td colspan="6" style="text-align: center; padding: 20px;" class="muted">No payment records found yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($payments as $pay): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 10px;"><?=date('d M Y, h:i A', strtotime($pay['paid_at']))?></td>
                            <td style="padding: 10px;"><strong><?=e($pay['application_no'])?></strong></td>
                            <td style="padding: 10px;"><code><?=e($pay['reference_no'] ?: 'MAN' . $pay['id'])?></code></td>
                            <td style="padding: 10px;"><span class="badge badge-info"><?=e($pay['payment_method'])?></span></td>
                            <td style="padding: 10px;"><strong style="color: var(--success);"><?=money($pay['amount'])?></strong></td>
                            <td style="padding: 10px; text-align: right;">
                                <a href="<?=url('/view-document.php?type=payment_receipt&payment_id=' . $pay['id'])?>" target="_blank" class="btn" style="padding: 4px 10px; font-size: 0.75rem; background: var(--success);">
                                    🧾 Receipt
                                </a>
                                <a href="<?=url('/download-document.php?type=payment_receipt&payment_id=' . $pay['id'])?>" class="btn" style="padding: 4px 8px; font-size: 0.75rem; background: rgba(255,255,255,0.08); border: 1px solid var(--border-color);">
                                    ⬇️ PDF
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_end(); ?>
