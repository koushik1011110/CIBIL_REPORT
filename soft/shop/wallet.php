<?php
require_once __DIR__.'/../includes/layout.php';
role('shop_admin');

$p = db();
$u = u();
$userId = (int)$u['id'];
$shopId = (int)($u['shop_id'] ?? 1);

// Get current shop wallet balance & shop details
$shopStmt = $p->prepare('SELECT wallet_balance, name FROM shops WHERE id = ?');
$shopStmt->execute([$shopId]);
$shop = $shopStmt->fetch();
$walletBalance = floatval($shop['wallet_balance'] ?? 0);
$shopName = $shop['name'] ?? 'Shop Wallet';

// Get shop wallet transactions history
$txStmt = $p->prepare('SELECT * FROM wallet_transactions WHERE shop_id = ? OR user_id = ? ORDER BY id DESC LIMIT 50');
$txStmt->execute([$shopId, $userId]);
$transactions = $txStmt->fetchAll();

start('Shop Wallet & PayU Realtime Topup');
?>

<?php if (isset($_GET['msg'])): ?>
    <?php if ($_GET['msg'] === 'success'): ?>
        <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 16px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
            <i data-lucide="check-circle"></i>
            <div>
                <strong>Payment Successful!</strong> Realtime money added to shop wallet: <strong><?=money($_GET['amount'] ?? 0)?></strong>
            </div>
        </div>
    <?php elseif ($_GET['msg'] === 'failed'): ?>
        <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 16px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px;">
            <i data-lucide="alert-triangle"></i>
            <div>
                <strong>Payment Failed or Cancelled:</strong> <?=e($_GET['err'] ?? 'Transaction was not completed.')?>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; margin-bottom: 30px;">
    <!-- WALLET BALANCE DISPLAY CARD -->
    <div class="card" style="background: linear-gradient(135deg, rgba(30, 41, 59, 0.9), rgba(15, 23, 42, 0.95)); border: 1px solid var(--border-color); position: relative; overflow: hidden;">
        <div style="position: absolute; right: -20px; top: -20px; width: 120px; height: 120px; background: rgba(59, 130, 246, 0.15); border-radius: 50%; blur: 20px;"></div>
        <div style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;"><?=e($shopName)?> Wallet Balance</div>
        <div style="font-size: 2.8rem; font-weight: 800; color: #fff; margin: 12px 0; display: flex; align-items: center; gap: 8px;">
            <span style="color: var(--primary);"><?=money($walletBalance)?></span>
        </div>
        <p style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 16px;">Shop wallet balance is used for credit check fees (Equifax ₹70 / Experian ₹60) and credit assessment services.</p>
        <span class="badge badge-success" style="font-size: 0.8rem; padding: 6px 12px;"><i data-lucide="zap"></i> PayU Realtime Enabled</span>
    </div>

    <!-- ADD MONEY VIA PAYU FORM CARD -->
    <div class="card">
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="plus-circle" style="color: var(--primary);"></i> Add Money to Shop Wallet via PayU
        </h3>
        
        <form action="<?=url('/api/wallet-payu-init.php')?>" method="POST">
            <input type="hidden" name="shop_id" value="<?=$shopId?>">

            <div class="form-group" style="margin-bottom: 16px;">
                <label style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Enter Topup Amount (₹ - Min ₹10)</label>
                <input type="number" name="amount" id="topupAmount" min="10" max="100000" step="0.01" value="500" required style="width: 100%; font-size: 1.2rem; font-weight: 700; color: var(--primary); padding: 12px; background: rgba(15,23,42,0.6); border: 1px solid var(--border-color); border-radius: 10px;">
            </div>

            <!-- Quick Amount Presets -->
            <div style="display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap;">
                <button type="button" class="btn" style="padding: 6px 14px; font-size: 0.85rem; background: rgba(30,41,59,0.8); border: 1px solid var(--border-color);" onclick="setAmount(10)">+ ₹10</button>
                <button type="button" class="btn" style="padding: 6px 14px; font-size: 0.85rem; background: rgba(30,41,59,0.8); border: 1px solid var(--border-color);" onclick="setAmount(100)">+ ₹100</button>
                <button type="button" class="btn" style="padding: 6px 14px; font-size: 0.85rem; background: rgba(30,41,59,0.8); border: 1px solid var(--border-color);" onclick="setAmount(500)">+ ₹500</button>
                <button type="button" class="btn" style="padding: 6px 14px; font-size: 0.85rem; background: rgba(30,41,59,0.8); border: 1px solid var(--border-color);" onclick="setAmount(1000)">+ ₹1,000</button>
                <button type="button" class="btn" style="padding: 6px 14px; font-size: 0.85rem; background: rgba(30,41,59,0.8); border: 1px solid var(--border-color);" onclick="setAmount(2500)">+ ₹2,500</button>
                <button type="button" class="btn" style="padding: 6px 14px; font-size: 0.85rem; background: rgba(30,41,59,0.8); border: 1px solid var(--border-color);" onclick="setAmount(5000)">+ ₹5,000</button>
            </div>

            <button type="submit" class="btn" style="width: 100%; padding: 14px; background: linear-gradient(135deg, var(--primary), #1d4ed8); font-size: 1rem; font-weight: 700;">
                <i data-lucide="shield-check"></i> 🚀 Proceed to PayU Checkout
            </button>
        </form>
    </div>
</div>

<!-- WALLET TRANSACTIONS HISTORY TABLE -->
<div class="card">
    <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
        <i data-lucide="history" style="color: var(--primary);"></i> Shop Wallet Transaction History
    </h3>

    <div style="overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted); text-align: left;">
                    <th style="padding: 12px;">#</th>
                    <th style="padding: 12px;">Date & Time</th>
                    <th style="padding: 12px;">Transaction Ref</th>
                    <th style="padding: 12px;">PayU Payment ID</th>
                    <th style="padding: 12px;">Remarks</th>
                    <th style="padding: 12px;">Type</th>
                    <th style="padding: 12px;">Amount</th>
                    <th style="padding: 12px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr><td colspan="8" style="text-align:center; padding: 20px; color: var(--text-muted);">No wallet transactions recorded yet.</td></tr>
                <?php else: ?>
                    <?php foreach($transactions as $idx => $t): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 12px;"><?=$idx + 1?></td>
                            <td style="padding: 12px;"><?=date('d M Y, h:i A', strtotime($t['created_at']))?></td>
                            <td style="padding: 12px;"><strong><?=e($t['txnid'])?></strong></td>
                            <td style="padding: 12px;"><?=e($t['payu_mihpayid'] ?: '-')?></td>
                            <td style="padding: 12px;"><?=e($t['remarks'] ?: 'Wallet Action')?></td>
                            <td style="padding: 12px;">
                                <span class="badge <?=$t['type']==='credit'?'badge-success':'badge-warning'?>"><?=strtoupper($t['type'])?></span>
                            </td>
                            <td style="padding: 12px; font-weight: 700; color: <?=$t['type']==='credit'?'var(--success)':'var(--danger)'?>;">
                                <?=$t['type']==='credit'?'+':'-'?><?=money($t['amount'])?>
                            </td>
                            <td style="padding: 12px;">
                                <?php if ($t['status'] === 'success'): ?>
                                    <span class="badge badge-success">SUCCESS</span>
                                <?php elseif ($t['status'] === 'pending'): ?>
                                    <span class="badge badge-warning">PENDING</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">FAILED</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function setAmount(val) {
        document.getElementById('topupAmount').value = val;
    }
</script>

<?php render_end(); ?>
