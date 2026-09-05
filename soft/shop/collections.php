<?php 
require_once __DIR__.'/../includes/layout.php';
role('shop_admin');

$p = db();
$sid = (int)(u()['shop_id'] ?: 1);

// Fetch Unpaid EMI Installments for Dropdown grouped by Customer
$emiStmt = $p->prepare('
    SELECT e.*, f.application_no, f.customer_id, c.name as customer_name, c.mobile as customer_mobile 
    FROM emi_schedules e 
    JOIN finance_applications f ON f.id = e.finance_id 
    JOIN customers c ON c.id = f.customer_id 
    WHERE f.shop_id = ? AND e.status != "paid" 
    ORDER BY c.name ASC, f.id DESC, e.installment_no ASC
');
$emiStmt->execute([$sid]);
$allEmis = $emiStmt->fetchAll();

// Group EMIs by Customer ID
$customersWithEmis = [];
foreach ($allEmis as $emi) {
    $cid = $emi['customer_id'];
    if (!isset($customersWithEmis[$cid])) {
        $customersWithEmis[$cid] = [
            'id' => $cid,
            'name' => $emi['customer_name'],
            'mobile' => $emi['customer_mobile'],
            'emis' => []
        ];
    }
    $customersWithEmis[$cid]['emis'][] = [
        'id' => $emi['id'],
        'finance_id' => $emi['finance_id'],
        'app_no' => $emi['application_no'],
        'installment_no' => $emi['installment_no'],
        'due_date' => date('d M Y', strtotime($emi['due_date'])),
        'due_month' => date('M Y', strtotime($emi['due_date'])),
        'amount' => $emi['amount'],
        'status' => strtoupper($emi['status'])
    ];
}

// Fetch Collections/Payments History
$q = $p->prepare('
    SELECT p.*, c.name as customer_name, f.application_no, e.installment_no, e.due_date 
    FROM payments p 
    JOIN customers c ON c.id = p.customer_id 
    JOIN finance_applications f ON f.id = p.finance_id 
    LEFT JOIN emi_schedules e ON e.id = p.emi_id 
    WHERE f.shop_id = ? 
    ORDER BY p.id DESC
');
$q->execute([$sid]);
$rows = $q->fetchAll();

start('EMI Collections & Manual Payment');
?>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'paid_success'): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
        <strong>✓ Payment Recorded!</strong> Manual cash collection for selected EMI month updated successfully.
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; margin-bottom: 30px;">
    <!-- MANUAL CASH COLLECTION FORM -->
    <div class="card">
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="dollar-sign" style="color: var(--success);"></i> Record Offline / Manual Cash EMI Payment
        </h3>

        <form action="<?=url('/api/manual-pay-emi.php')?>" method="POST">
            <input type="hidden" name="finance_id" id="financeIdInput">
            <input type="hidden" name="is_foreclosure" id="isForeclosureInput" value="0">

            <!-- STEP 1: SELECT CUSTOMER -->
            <div class="field" style="margin-bottom: 14px;">
                <label style="color:#60a5fa; font-weight:700;">1. Select Customer *</label>
                <select id="customerSelect" onchange="onCustomerChange(this.value)" required style="width: 100%; padding: 10px; font-size: 0.85rem;">
                    <option value="">-- Select Customer --</option>
                    <?php foreach($customersWithEmis as $cid => $cData): ?>
                        <option value="<?=$cid?>">
                            👤 <?=e($cData['name'])?> (<?=$cData['mobile']?>) — <?=count($cData['emis'])?> Pending Month(s)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- STEP 2: SELECT PENDING EMI MONTH / FULL SETTLEMENT -->
            <div class="field" style="margin-bottom: 14px;">
                <label style="color:#60a5fa; font-weight:700;">2. Select Payment Type / EMI Month *</label>
                <select name="emi_id" id="emiMonthSelect" onchange="onEmiMonthChange()" required disabled style="width: 100%; padding: 10px; font-size: 0.85rem;">
                    <option value="">-- First Select a Customer Above --</option>
                </select>
            </div>

            <div class="field" style="margin-bottom: 14px;">
                <label>Payment Amount Received (₹)</label>
                <input type="number" step="0.01" name="amount" id="amountInput" placeholder="Enter amount" required style="width: 100%; padding: 10px;">
            </div>

            <div class="field" style="margin-bottom: 16px;">
                <label>Remarks / Note</label>
                <input type="text" name="remarks" id="remarksInput" value="Counter Cash Payment" style="width: 100%; padding: 10px;">
            </div>

            <button type="submit" class="btn" style="width: 100%; padding: 12px; background: linear-gradient(135deg, var(--success), #059669);">
                <i data-lucide="check-circle"></i> Record Payment & Update Loan Balance
            </button>
        </form>
    </div>

    <!-- COLLECTIONS SUMMARY CARD -->
    <div class="card">
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 12px;">Store Collections Info</h3>
        <p class="muted" style="margin-bottom: 16px;">Select a customer to clear an individual EMI month or process a <strong>Full Loan Settlement / Foreclosure</strong> to close the loan completely.</p>
        
        <?php
        $totalCollected = array_sum(array_column($rows, 'amount'));
        ?>
        <div style="background: rgba(15,23,42,0.6); padding: 16px; border-radius: 12px; border: 1px solid var(--border-color);">
            <div style="font-size: 0.8rem; color: var(--text-muted); text-transform: uppercase;">Total Collected Amount</div>
            <div style="font-size: 2.2rem; font-weight: 800; color: var(--success); margin-top: 4px;"><?=money($totalCollected)?></div>
        </div>
    </div>
</div>

<!-- COLLECTIONS TABLE -->
<div class="card">
    <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px;">Collection History</h3>
    <div style="overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                    <th style="padding: 12px;">#</th>
                    <th style="padding: 12px;">Payment Date</th>
                    <th style="padding: 12px;">Customer</th>
                    <th style="padding: 12px;">App No</th>
                    <th style="padding: 12px;">Installment / Due Month</th>
                    <th style="padding: 12px;">Method</th>
                    <th style="padding: 12px;">Ref No</th>
                    <th style="padding: 12px;">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($rows)): ?>
                    <tr><td colspan="8" style="text-align: center; padding: 20px;">No payments collected yet.</td></tr>
                <?php else: ?>
                    <?php foreach($rows as $idx => $r): ?>
                        <?php
                        $instMonthDisplay = !empty($r['installment_no']) ? ('Installment #' . $r['installment_no'] . ($r['due_date'] ? ' (' . date('M Y', strtotime($r['due_date'])) . ')' : '')) : ($r['remarks'] ?: 'EMI Payment');
                        ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 12px;"><?=$idx + 1?></td>
                            <td style="padding: 12px;"><?=date('d M Y, h:i A', strtotime($r['paid_at']))?></td>
                            <td style="padding: 12px;"><strong><?=e($r['customer_name'])?></strong></td>
                            <td style="padding: 12px;"><strong><?=e($r['application_no'])?></strong></td>
                            <td style="padding: 12px;"><span class="badge badge-info" style="font-size: 0.78rem;"><?=e($instMonthDisplay)?></span></td>
                            <td style="padding: 12px;"><span class="badge badge-success"><?=e($r['payment_method'])?></span></td>
                            <td style="padding: 12px;"><code><?=e($r['reference_no'] ?: '-')?></code></td>
                            <td style="padding: 12px;"><strong style="color:var(--success);"><?=money($r['amount'])?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const customerData = <?=json_encode($customersWithEmis)?>;

function onCustomerChange(custId) {
    const emiSelect = document.getElementById('emiMonthSelect');
    emiSelect.innerHTML = '<option value="">-- Select EMI Month or Full Settlement --</option>';
    document.getElementById('financeIdInput').value = '';
    document.getElementById('isForeclosureInput').value = '0';
    document.getElementById('amountInput').value = '';
    document.getElementById('remarksInput').value = 'Counter Cash Payment';

    if (!custId || !customerData[custId]) {
        emiSelect.disabled = true;
        return;
    }

    const emis = customerData[custId].emis;
    let totalPendingSum = 0;
    let mainFinanceId = emis[0] ? emis[0].finance_id : 0;
    emis.forEach(function(eItem) {
        totalPendingSum += (parseFloat(eItem.amount) || 0);
    });

    if (emis.length > 0) {
        // 🔥 FULL FORECLOSURE OPTION AT TOP
        const fullOpt = document.createElement('option');
        fullOpt.value = 'full';
        fullOpt.style.background = '#0f172a';
        fullOpt.style.color = '#10b981';
        fullOpt.style.fontWeight = 'bold';
        fullOpt.setAttribute('data-finance', mainFinanceId);
        fullOpt.setAttribute('data-amount', totalPendingSum);
        fullOpt.setAttribute('data-foreclosure', '1');
        fullOpt.setAttribute('data-remark', 'Full Loan Foreclosure Settlement - Loan Fully Repaid & Closed');
        fullOpt.textContent = '🔥 FULL LOAN SETTLEMENT / FORECLOSURE (Pay All ' + emis.length + ' Months — ₹' + totalPendingSum.toLocaleString('en-IN') + ' & Close Loan)';
        emiSelect.appendChild(fullOpt);
    }

    emis.forEach(function(eItem) {
        const opt = document.createElement('option');
        opt.value = eItem.id;
        opt.setAttribute('data-finance', eItem.finance_id);
        opt.setAttribute('data-amount', eItem.amount);
        opt.setAttribute('data-foreclosure', '0');
        opt.setAttribute('data-remark', 'Cash Payment for Installment #' + eItem.installment_no + ' (' + eItem.due_month + ')');
        opt.textContent = 'Installment #' + eItem.installment_no + ' | Due: ' + eItem.due_date + ' (Month: ' + eItem.due_month + ') — ₹' + parseFloat(eItem.amount).toLocaleString('en-IN');
        emiSelect.appendChild(opt);
    });

    emiSelect.disabled = false;
}

function onEmiMonthChange() {
    const emiSelect = document.getElementById('emiMonthSelect');
    const selectedOption = emiSelect.options[emiSelect.selectedIndex];
    if (selectedOption && selectedOption.value) {
        document.getElementById('financeIdInput').value = selectedOption.getAttribute('data-finance') || '';
        document.getElementById('isForeclosureInput').value = selectedOption.getAttribute('data-foreclosure') || '0';
        document.getElementById('amountInput').value = selectedOption.getAttribute('data-amount') || '';
        document.getElementById('remarksInput').value = selectedOption.getAttribute('data-remark') || 'Counter Cash Payment';
    }
}
</script>

<?php render_end(); ?>
