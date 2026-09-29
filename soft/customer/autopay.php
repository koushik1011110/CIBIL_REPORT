<?php 
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/cashfree.php';
role('customer');

ensure_mandate_tables();

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

// Fetch All Finance Applications
$allApps = [];
$selectedAppId = isset($_GET['app_id']) ? (int)$_GET['app_id'] : (isset($_GET['finance_id']) ? (int)$_GET['finance_id'] : 0);
$f = null;

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
        $f = $allApps[0];
    }
}

$financeId = $f ? (int)$f['id'] : 0;

// Fetch Mandate details for the selected loan
$mandate = null;
$mandateDebits = [];
$unpaidEmisCount = 0;
$nextEmiDueDate = null;

if ($financeId > 0) {
    $mandate = get_latest_loan_mandate($financeId);

    // Unpaid EMIs count and next due date
    $emiStmt = $p->prepare("SELECT * FROM emi_schedules WHERE finance_id = ? AND status != 'paid' ORDER BY installment_no ASC");
    $emiStmt->execute([$financeId]);
    $unpaidEmis = $emiStmt->fetchAll();
    $unpaidEmisCount = count($unpaidEmis);
    $nextEmiDueDate = $unpaidEmis[0]['due_date'] ?? null;

    // Fetch Mandate Debits history
    if ($mandate) {
        $debStmt = $p->prepare("SELECT * FROM mandate_debits WHERE finance_id = ? ORDER BY id DESC");
        $debStmt->execute([$financeId]);
        $mandateDebits = $debStmt->fetchAll();
    }
}

$mandateStatus = $mandate ? strtoupper($mandate['status']) : 'NONE';

start('Autopay & e-Mandate');
?>

<!-- ALERTS & NOTICES -->
<?php if (isset($_GET['mandate']) && $_GET['mandate'] === 'success'): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 16px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <span style="font-size: 1.5rem;">🎉</span>
            <div>
                <strong>Autopay Mandate Activated!</strong>
                <p style="margin: 2px 0 0 0; font-size: 0.85rem; color: #a7f3d0;">Your monthly loan EMI will now be automatically debited on your scheduled due date via Cashfree secure e-Mandate.</p>
            </div>
        </div>
        <span class="badge badge-success" style="padding: 6px 12px;">ACTIVE</span>
    </div>
<?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'cancelled_success'): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: #fca5a5; padding: 16px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 12px;">
        <span style="font-size: 1.5rem;">✓</span>
        <div>
            <strong style="color: #fff;">Autopay Mandate Cancelled Successfully</strong>
            <p style="margin: 2px 0 0 0; font-size: 0.85rem; color: #cbd5e1;">Your e-mandate has been revoked and recurring auto-debit has been disabled. Please make your monthly EMI payments manually.</p>
        </div>
    </div>
<?php elseif (isset($_GET['already_active'])): ?>
    <div style="background: rgba(59, 130, 246, 0.15); border: 1px solid var(--primary); color: #93c5fd; padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
        ℹ️ <strong>Autopay is already active</strong> for this loan application. You do not need to set it up again.
    </div>
<?php elseif (isset($_GET['mandate']) && $_GET['mandate'] === 'failed'): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 14px 18px; border-radius: 12px; margin-bottom: 20px;">
        ❌ <strong>Mandate Authorization Incomplete:</strong> The Cashfree e-mandate was not approved. You can retry setup anytime.
    </div>
<?php endif; ?>

<!-- LOAN APPLICATION SWITCHER (If multiple loans exist) -->
<?php if (count($allApps) > 1): ?>
    <div class="card" style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; padding: 14px 20px; background: rgba(30,41,59,0.7); border: 1px solid var(--border-color);">
        <div style="display: flex; align-items: center; gap: 8px;">
            <i data-lucide="layers" style="color: #60a5fa; width: 18px; height: 18px;"></i>
            <span style="font-weight: 700; color: #60a5fa; font-size: 0.9rem;">Select Loan Application:</span>
        </div>
        <div>
            <select onchange="window.location.href='?finance_id=' + this.value" style="background: #0f172a; color: #fff; border: 1px solid #3b82f6; padding: 8px 14px; border-radius: 8px; font-size: 0.85rem;">
                <?php foreach ($allApps as $aItem): ?>
                    <option value="<?=$aItem['id']?>" <?=$financeId == $aItem['id'] ? 'selected' : ''?>>
                        <?=e($aItem['application_no'])?> (<?=e($aItem['product_name'] ?: 'Loan')?>) — EMI: <?=money($aItem['emi'])?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
<?php endif; ?>

<?php if (!$f): ?>
    <div class="card" style="text-align: center; padding: 40px 20px;">
        <i data-lucide="info" style="width: 48px; height: 48px; color: var(--text-muted); margin-bottom: 12px;"></i>
        <h3 style="font-size: 1.1rem; color: #fff;">No Active Loan Found</h3>
        <p class="muted" style="margin-top: 6px;">You currently do not have any store loan applications on record.</p>
    </div>
<?php else: ?>

    <!-- MAIN AUTOPAY STATUS CARD -->
    <div class="card" style="margin-bottom: 24px; position: relative; overflow: hidden; background: linear-gradient(135deg, rgba(30,41,59,0.95), rgba(15,23,42,0.98)); border: 1px solid <?=($mandateStatus === 'ACTIVE') ? 'rgba(16,185,129,0.4)' : 'rgba(59,130,246,0.3)'?>;">
        
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
            <div>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 6px;">
                    <h3 style="font-size: 1.25rem; font-weight: 800; color: #fff; margin: 0;">
                        Recurring Autopay & e-Mandate
                    </h3>
                    <?php if ($mandateStatus === 'ACTIVE'): ?>
                        <span class="badge badge-success" style="font-size: 0.75rem; padding: 4px 10px; display: inline-flex; align-items: center; gap: 4px;">
                            <span style="display:inline-block; width:6px; height:6px; border-radius:50%; background:#10b981; animation: pulse 1.5s infinite;"></span>
                            ACTIVE
                        </span>
                    <?php elseif ($mandateStatus === 'BANK_APPROVAL_PENDING'): ?>
                        <span class="badge badge-warning" style="font-size: 0.75rem; padding: 4px 10px;">APPROVAL PENDING</span>
                    <?php elseif ($mandateStatus === 'CANCELLED'): ?>
                        <span class="badge badge-danger" style="font-size: 0.75rem; padding: 4px 10px;">CANCELLED</span>
                    <?php else: ?>
                        <span class="badge" style="background: rgba(148,163,184,0.2); color: #cbd5e1; font-size: 0.75rem; padding: 4px 10px;">NOT CONFIGURED</span>
                    <?php endif; ?>
                </div>
                <p class="muted" style="margin: 0; font-size: 0.85rem;">
                    Automatic monthly loan debit via Cashfree PG (UPI Autopay, e-NACH & NetBanking)
                </p>
            </div>

            <!-- ACTION BUTTONS -->
            <div>
                <?php 
                $isNonRevocable = $mandate ? ((isset($mandate['non_revocable']) && $mandate['non_revocable'] == 1) || (isset($mandate['is_revocable']) && $mandate['is_revocable'] == 0)) : true;
                ?>
                <?php if ($mandateStatus === 'ACTIVE'): ?>
                    <?php if ($isNonRevocable): ?>
                        <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 4px;">
                            <span class="badge" style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); color: #34d399; font-weight: 700; padding: 8px 14px; font-size: 0.85rem; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px;">
                                🔒 Non-Revocable Loan Mandate
                            </span>
                            <span style="font-size: 0.72rem; color: #94a3b8;">Secured AutoPay (Active until loan closure / NOC)</span>
                        </div>
                    <?php else: ?>
                        <button type="button" onclick="openCancelModal()" class="btn" style="background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.4); color: #f87171; font-weight: 700; padding: 9px 18px; font-size: 0.85rem; border-radius: 8px; cursor: pointer; transition: all 0.2s ease;">
                            ❌ Cancel Autopay / Revoke Mandate
                        </button>
                    <?php endif; ?>
                <?php elseif ($unpaidEmisCount > 0): ?>
                    <a href="<?=url('/api/setup-mandate.php?finance_id=' . $financeId)?>" class="btn" style="background: linear-gradient(135deg, #10b981, #059669); color: #fff; font-weight: 800; padding: 10px 22px; font-size: 0.9rem; border-radius: 10px; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 10px 20px -5px rgba(16,185,129,0.4);">
                        <span>⚡</span> Setup Monthly Autopay / e-Mandate
                    </a>
                <?php else: ?>
                    <span style="color: var(--success); font-weight: 700; font-size: 0.9rem;">✓ Loan Fully Repaid</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- DETAILS GRID -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; background: rgba(15,23,42,0.65); border: 1px solid rgba(255,255,255,0.06); border-radius: 12px; padding: 18px;">
            <div>
                <span class="muted" style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">Loan Application</span>
                <div style="font-size: 1.05rem; font-weight: 800; color: #fff; margin-top: 4px;"><?=e($f['application_no'] ?: '#' . $financeId)?></div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;"><?=e($f['product_name'] ?: 'Store Loan')?></div>
            </div>

            <div>
                <span class="muted" style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">Monthly EMI Debit</span>
                <div style="font-size: 1.25rem; font-weight: 800; color: #10b981; margin-top: 4px;"><?=money($f['emi'] ?: 0)?></div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">Monthly recurring debit</div>
            </div>

            <div>
                <span class="muted" style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">Next Auto-Debit Date</span>
                <div style="font-size: 1.05rem; font-weight: 700; color: #fbbf24; margin-top: 4px;">
                    <?=($mandate && !empty($mandate['next_debit_date'])) ? date('d M Y', strtotime($mandate['next_debit_date'])) : ($nextEmiDueDate ? date('d M Y', strtotime($nextEmiDueDate)) : 'N/A')?>
                </div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;"><?=$unpaidEmisCount?> installment<?=$unpaidEmisCount>1?'s':''?> remaining</div>
            </div>

            <div>
                <span class="muted" style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">Mandate Reference ID</span>
                <div style="font-size: 0.88rem; font-family: monospace; font-weight: 700; color: #60a5fa; margin-top: 4px;">
                    <?=e($mandate['mandate_id'] ?? 'Not Created')?>
                </div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">Mode: <?=e($mandate['auth_mode'] ?? 'UPI / e-NACH')?></div>
            </div>

            <div>
                <span class="muted" style="font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.5px;">Mandate Policy</span>
                <div style="font-size: 0.95rem; font-weight: 700; color: #38bdf8; margin-top: 4px;">
                    🔒 Non-Revocable
                </div>
                <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;">Revocable: <strong>No</strong> | Non-Revocable: <strong>Yes</strong></div>
            </div>
        </div>

        <?php if ($mandateStatus === 'CANCELLED'): ?>
            <div style="margin-top: 18px; padding: 12px 16px; background: rgba(239,68,68,0.08); border-left: 4px solid var(--danger); border-radius: 4px; font-size: 0.85rem; color: #cbd5e1;">
                <strong style="color: #fca5a5;">Mandate Status: CANCELLED</strong>
                <p style="margin: 4px 0 0 0; font-size: 0.82rem; color: #94a3b8;">
                    Cancelled on <?=date('d M Y, h:i A', strtotime($mandate['cancelled_at'] ?: $mandate['updated_at']))?>. 
                    <?=!empty($mandate['cancellation_reason']) ? 'Reason: ' . e($mandate['cancellation_reason']) : ''?>
                </p>
                <div style="margin-top: 10px;">
                    <a href="<?=url('/api/setup-mandate.php?finance_id=' . $financeId)?>" class="btn" style="padding: 6px 14px; font-size: 0.8rem; background: var(--primary); color: #fff;">
                        🔄 Re-activate Monthly Autopay
                    </a>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- BENEFITS SECTION (If not active) -->
    <?php if ($mandateStatus !== 'ACTIVE' && $unpaidEmisCount > 0): ?>
        <div style="margin-bottom: 24px; display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px;">
            <div class="card" style="background: rgba(30,41,59,0.5); border: 1px solid rgba(16,185,129,0.2);">
                <div style="font-size: 1.8rem; margin-bottom: 8px;">⚡</div>
                <h4 style="font-size: 0.95rem; font-weight: 700; color: #fff; margin: 0 0 4px 0;">Never Miss an EMI</h4>
                <p class="muted" style="font-size: 0.82rem; line-height: 1.5; margin: 0;">Auto-debit automatically pays your installment on the due date, saving you from penalties or overdue charges.</p>
            </div>

            <div class="card" style="background: rgba(30,41,59,0.5); border: 1px solid rgba(59,130,246,0.2);">
                <div style="font-size: 1.8rem; margin-bottom: 8px;">📈</div>
                <h4 style="font-size: 0.95rem; font-weight: 700; color: #fff; margin: 0 0 4px 0;">Boosts CIBIL Score</h4>
                <p class="muted" style="font-size: 0.82rem; line-height: 1.5; margin: 0;">100% on-time automated repayments reflect positively on your bureau report and unlock higher credit limits.</p>
            </div>

            <div class="card" style="background: rgba(30,41,59,0.5); border: 1px solid rgba(56,189,248,0.25);">
                <div style="font-size: 1.8rem; margin-bottom: 8px;">🔒</div>
                <h4 style="font-size: 0.95rem; font-weight: 700; color: #fff; margin: 0 0 4px 0;">Non-Revocable Loan Mandate</h4>
                <p class="muted" style="font-size: 0.82rem; line-height: 1.5; margin: 0;">In accordance with NPCI & RBI rules for lending, this mandate is non-revocable to guarantee disciplined EMI clearance and closes upon full repayment.</p>
            </div>
        </div>
    <?php endif; ?>

    <!-- MANDATE TRANSACTION DEBITS HISTORY -->
    <div class="card" style="margin-bottom: 24px; padding: 0; overflow-x: auto;">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <div>
                <h3 style="font-size: 1.05rem; font-weight: 700; color: #fff; margin: 0;">Automatic Debit Transactions</h3>
                <p class="muted" style="font-size: 0.8rem; margin: 2px 0 0 0;">Log of automated monthly EMI deductions processed via Cashfree mandate</p>
            </div>
            <a href="<?=url('/customer/emi-schedule.php?finance_id=' . $financeId)?>" class="btn" style="padding: 6px 12px; font-size: 0.78rem; background: rgba(255,255,255,0.06); color: #cbd5e1;">
                View Full EMI Schedule →
            </a>
        </div>

        <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                    <th style="padding: 12px 16px;">#</th>
                    <th style="padding: 12px 16px;">Debit Date</th>
                    <th style="padding: 12px 16px;">Mandate ID</th>
                    <th style="padding: 12px 16px;">Cashfree Txn Ref</th>
                    <th style="padding: 12px 16px;">Debited Amount</th>
                    <th style="padding: 12px 16px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($mandateDebits)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 24px; color: var(--text-muted);">
                            No automated debits executed yet. <?=$mandateStatus === 'ACTIVE' ? 'The next debit will be processed on your upcoming EMI due date.' : ''?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($mandateDebits as $dIdx => $dItem): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 12px 16px;"><?=$dIdx + 1?></td>
                            <td style="padding: 12px 16px;"><?=date('d M Y, h:i A', strtotime($dItem['debited_at'] ?: $dItem['created_at']))?></td>
                            <td style="padding: 12px 16px;"><code><?=e($dItem['mandate_id'])?></code></td>
                            <td style="padding: 12px 16px;"><code><?=e($dItem['cf_payment_id'] ?: '-')?></code></td>
                            <td style="padding: 12px 16px;"><strong style="color:var(--success);"><?=money($dItem['amount'])?></strong></td>
                            <td style="padding: 12px 16px;">
                                <span class="badge badge-success"><?=strtoupper($dItem['status'])?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- FAQ SECTION -->
    <div class="card" style="padding: 22px;">
        <h4 style="font-size: 1rem; font-weight: 700; color: #fff; margin-bottom: 14px;">Frequently Asked Questions</h4>
        
        <div style="margin-bottom: 12px;">
            <strong style="color: #60a5fa; font-size: 0.88rem;">Q: When will my bank account or UPI be debited?</strong>
            <p class="muted" style="font-size: 0.82rem; margin: 4px 0 0 0; line-height: 1.5;">Debits occur on the exact due date of each monthly EMI installment. NPCI guidelines mandate an advance SMS reminder 24-48 hours before debit.</p>
        </div>

        <div style="margin-bottom: 12px;">
            <strong style="color: #60a5fa; font-size: 0.88rem;">Q: What happens if I cancel my Autopay mandate?</strong>
            <p class="muted" style="font-size: 0.82rem; margin: 4px 0 0 0; line-height: 1.5;">When you cancel, Cashfree revokes the mandate immediately so no further automatic debits can occur. You must then pay your monthly EMIs manually via Cashfree PG or UPI on the EMI Schedule page.</p>
        </div>

        <div>
            <strong style="color: #60a5fa; font-size: 0.88rem;">Q: Can I pay my EMI in advance before the auto-debit date?</strong>
            <p class="muted" style="font-size: 0.82rem; margin: 4px 0 0 0; line-height: 1.5;">Yes! If you manually pay an installment online in advance, the system registers it as PAID and the upcoming debit for that month is skipped.</p>
        </div>
    </div>

<?php endif; ?>

<!-- CANCELLATION CONFIRMATION MODAL -->
<div id="cancelMandateModal" style="display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); z-index: 9999; justify-content: center; align-items: center; padding: 20px; backdrop-filter: blur(4px);">
    <div style="background: #1e293b; border: 1px solid rgba(239,68,68,0.4); border-radius: 16px; max-width: 480px; width: 100%; padding: 28px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.7);">
        
        <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 16px;">
            <div style="width: 44px; height: 44px; border-radius: 50%; background: rgba(239,68,68,0.15); display: flex; align-items: center; justify-content: center; color: #ef4444; font-size: 1.4rem;">
                ⚠️
            </div>
            <div>
                <h3 style="font-size: 1.15rem; font-weight: 800; color: #fff; margin: 0;">Cancel Autopay Mandate?</h3>
                <p class="muted" style="margin: 2px 0 0 0; font-size: 0.82rem;">This will stop recurring auto-debit for your loan</p>
            </div>
        </div>

        <form action="<?=url('/api/cancel-mandate.php')?>" method="POST" id="cancelMandateForm">
            <input type="hidden" name="mandate_id" value="<?=e($mandate['mandate_id'] ?? '')?>">
            <input type="hidden" name="finance_id" value="<?=$financeId?>">

            <div style="background: rgba(15,23,42,0.6); padding: 14px; border-radius: 10px; margin-bottom: 18px; font-size: 0.83rem; color: #cbd5e1; line-height: 1.5;">
                <p style="margin: 0 0 6px 0;"><strong>Please Note:</strong></p>
                <ul style="margin: 0; padding-left: 20px;">
                    <li>Cashfree gateway will be instructed to revoke this e-mandate.</li>
                    <li>No further automatic deductions will take place.</li>
                    <li>You will need to pay all upcoming monthly EMIs manually before due dates to avoid late payment marks.</li>
                </ul>
            </div>

            <div style="margin-bottom: 20px;">
                <label style="display: block; font-size: 0.82rem; font-weight: 700; color: #94a3b8; margin-bottom: 6px;">Reason for Cancellation:</label>
                <select name="reason" style="width: 100%; background: #0f172a; color: #fff; border: 1px solid rgba(255,255,255,0.15); padding: 10px 12px; border-radius: 8px; font-size: 0.85rem;">
                    <option value="Prefer to pay monthly EMIs manually">Prefer to pay monthly EMIs manually</option>
                    <option value="Switching to a different bank account">Switching to a different bank account</option>
                    <option value="Planning to prepay full loan balance">Planning to prepay full loan balance</option>
                    <option value="Other / Personal choice">Other / Personal choice</option>
                </select>
            </div>

            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button type="button" onclick="closeCancelModal()" class="btn" style="background: #334155; color: #cbd5e1; padding: 10px 18px; font-size: 0.85rem;">
                    Keep Autopay
                </button>
                <button type="submit" class="btn" style="background: #ef4444; color: #fff; font-weight: 700; padding: 10px 20px; font-size: 0.85rem; box-shadow: 0 4px 12px rgba(239,68,68,0.4);">
                    Yes, Cancel Mandate
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openCancelModal() {
    const m = document.getElementById('cancelMandateModal');
    if (m) m.style.display = 'flex';
}
function closeCancelModal() {
    const m = document.getElementById('cancelMandateModal');
    if (m) m.style.display = 'none';
}
// Close on click outside modal content
window.addEventListener('click', function(e) {
    const m = document.getElementById('cancelMandateModal');
    if (e.target === m) {
        closeCancelModal();
    }
});
</script>

<?php render_end(); ?>
