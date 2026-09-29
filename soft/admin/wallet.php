<?php
require_once __DIR__.'/../includes/layout.php';
role('superadmin');

$p = db();
$u = u();
$userId = (int)$u['id'];

// Handle direct manual wallet adjustment by Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'admin_adjust_wallet') {
    $targetShopId = (int)($_POST['shop_id'] ?? 0);
    $walletAction = $_POST['wallet_action'] ?? 'add_credit'; // 'add_credit', 'deduct_debit', 'set_exact'
    $amount = floatval($_POST['amount'] ?? 0);
    $remarks = trim($_POST['remarks'] ?? 'Admin Manual Adjustment');

    if ($targetShopId > 0 && $amount >= 0) {
        $sStmt = $p->prepare('SELECT wallet_balance, name FROM shops WHERE id = ?');
        $sStmt->execute([$targetShopId]);
        $targetShop = $sStmt->fetch();

        if ($targetShop) {
            $curBal = floatval($targetShop['wallet_balance'] ?? 0);
            $newBal = $curBal;
            $txType = 'credit';
            $diff = $amount;

            if ($walletAction === 'add_credit') {
                $newBal = $curBal + $amount;
                $txType = 'credit';
                $diff = $amount;
            } elseif ($walletAction === 'deduct_debit') {
                $newBal = max(0, $curBal - $amount);
                $txType = 'debit';
                $diff = min($curBal, $amount);
            } elseif ($walletAction === 'set_exact') {
                $newBal = max(0, $amount);
                if ($newBal >= $curBal) {
                    $txType = 'credit';
                    $diff = $newBal - $curBal;
                } else {
                    $txType = 'debit';
                    $diff = $curBal - $newBal;
                }
            }

            // Update shops table balance
            $upStmt = $p->prepare('UPDATE shops SET wallet_balance = ? WHERE id = ?');
            $upStmt->execute([$newBal, $targetShopId]);

            // Record in wallet_transactions table
            $txnid = 'ADM' . time() . rand(100, 999);
            $fullRemarks = 'Admin Manual Adjustment: ' . $remarks . ' (Bal: ₹' . number_format($curBal, 2) . ' ➔ ₹' . number_format($newBal, 2) . ')';
            try {
                $insTx = $p->prepare('INSERT INTO wallet_transactions (user_id, shop_id, txnid, amount, type, status, payment_gateway, remarks) VALUES (?, ?, ?, ?, ?, "success", "Admin Manual", ?)');
                $insTx->execute([$userId, $targetShopId, $txnid, $diff, $txType, $fullRemarks]);
            } catch (Exception $e) {
                error_log('Error logging admin wallet transaction: ' . $e->getMessage());
            }

            header('Location: wallet.php?shop_id=' . $targetShopId . '&msg=wallet_adjusted&bal=' . urlencode(number_format($newBal, 2)) . '&name=' . urlencode($targetShop['name']));
            exit;
        }
    }
}

// Fetch all active shops
$allShops = $p->query("SELECT id, name, wallet_balance FROM shops WHERE status = 'active' ORDER BY id ASC")->fetchAll();
$shopId = (int)($_GET['shop_id'] ?? ($u['shop_id'] ?? ($allShops[0]['id'] ?? 1)));

// Get current shop wallet balance & shop details
$shopStmt = $p->prepare('SELECT wallet_balance, name FROM shops WHERE id = ?');
$shopStmt->execute([$shopId]);
$shop = $shopStmt->fetch();
$walletBalance = floatval($shop['wallet_balance'] ?? 0);
$shopName = $shop['name'] ?? 'Primary Store';

// Get shop wallet transactions history
$txStmt = $p->prepare('SELECT * FROM wallet_transactions WHERE shop_id = ? OR shop_id IS NULL ORDER BY id DESC LIMIT 50');
$txStmt->execute([$shopId]);
$transactions = $txStmt->fetchAll();

start('Merchant Shop Wallets & Credit Management');
?>

<?php if (isset($_GET['msg'])): ?>
    <?php if ($_GET['msg'] === 'wallet_adjusted'): ?>
        <div style="background: rgba(16, 185, 129, 0.15); border: 1.5px solid var(--success); color: var(--success); padding: 16px 20px; border-radius: 12px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px; box-shadow: 0 4px 12px rgba(16, 185, 129, 0.15);">
            <i data-lucide="check-circle" style="width: 24px; height: 24px;"></i>
            <div>
                <strong style="font-size: 1.05rem;">Shop Wallet Updated Successfully!</strong>
                <div style="font-size: 0.9rem; margin-top: 3px; color: #cbd5e1;">
                    Store <strong><?=e($_GET['name'] ?? $shopName)?></strong> balance is now <strong>₹<?=e($_GET['bal'] ?? number_format($walletBalance, 2))?></strong>. Ledger transaction recorded.
                </div>
            </div>
        </div>
    <?php elseif ($_GET['msg'] === 'success'): ?>
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

<!-- TOP ACTION HEADER -->
<div class="card" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 14px;">
    <div>
        <h3 style="font-size: 1.25rem; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="wallet" style="color: var(--primary);"></i> Merchant Store Wallets & Direct Admin Credit
        </h3>
        <p class="muted" style="margin: 4px 0 0 0; font-size: 0.88rem;">Admin can directly credit/debit any store balance or top up via PayU gateway.</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <a href="shops.php" class="btn" style="background: rgba(15, 23, 42, 0.8); border: 1px solid var(--border-color); color: #fff; font-weight: 700;">
            <i data-lucide="store"></i> View All Stores Table
        </a>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px; margin-bottom: 30px;">
    
    <!-- 1. DIRECT ADMIN WALLET EDIT / CREDIT CARD -->
    <div class="card" style="border: 1.5px solid rgba(16, 185, 129, 0.4); background: linear-gradient(145deg, rgba(15, 23, 42, 0.95), rgba(16, 185, 129, 0.05)); position: relative;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 12px;">
            <div style="display: flex; align-items: center; gap: 8px;">
                <span style="background: rgba(16, 185, 129, 0.15); color: #10b981; padding: 6px; border-radius: 8px; display: inline-flex;">
                    <i data-lucide="zap" style="width: 18px; height: 18px;"></i>
                </span>
                <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin: 0;">Direct Admin Wallet Credit / Edit</h3>
            </div>
            <span class="badge badge-success" style="font-size: 0.72rem; padding: 4px 10px;">Instant ₹0 Gateway Fee</span>
        </div>

        <form method="POST" id="adminAdjustForm">
            <input type="hidden" name="action" value="admin_adjust_wallet">
            
            <!-- Shop Selector -->
            <div class="form-group" style="margin-bottom: 14px;">
                <label style="font-size: 0.8rem; color: #94a3b8; font-weight: 700; text-transform: uppercase;">Target Shop Outlet *</label>
                <select name="shop_id" id="directShopSelect" onchange="onShopChange(this.value)" style="width: 100%; padding: 11px 14px; background: rgba(15,23,42,0.8); border: 1.5px solid var(--border-color); border-radius: 10px; color: #fff; font-weight: 700; font-size: 0.92rem;">
                    <?php foreach ($allShops as $sh): ?>
                        <option value="<?=$sh['id']?>" data-balance="<?=$sh['wallet_balance']?>" <?=$sh['id'] == $shopId ? 'selected' : ''?>>
                            <?=e($sh['name'])?> · Balance: <?=money($sh['wallet_balance'])?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Current Balance Display -->
            <div style="background: rgba(15, 23, 42, 0.7); border: 1px solid var(--border-color); border-radius: 10px; padding: 10px 14px; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 0.82rem; color: #94a3b8;">Current Wallet Balance:</span>
                <strong style="color: #10b981; font-size: 1.25rem;" id="directCurrentBalDisplay"><?=money($walletBalance)?></strong>
            </div>

            <!-- Action Type Radio Switcher -->
            <div class="form-group" style="margin-bottom: 16px;">
                <label style="font-size: 0.8rem; color: #94a3b8; font-weight: 700; text-transform: uppercase; margin-bottom: 8px; display: block;">Adjustment Action *</label>
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px;">
                    <label style="cursor: pointer; background: rgba(16, 185, 129, 0.1); border: 1.5px solid rgba(16, 185, 129, 0.4); border-radius: 8px; padding: 8px 4px; text-align: center; color: #10b981; font-weight: 700; font-size: 0.8rem;">
                        <input type="radio" name="wallet_action" value="add_credit" checked onchange="calcDirectNewBal()" style="margin-right: 4px;">
                        ➕ Credit
                    </label>
                    <label style="cursor: pointer; background: rgba(239, 68, 68, 0.08); border: 1.5px solid rgba(239, 68, 68, 0.35); border-radius: 8px; padding: 8px 4px; text-align: center; color: #ef4444; font-weight: 700; font-size: 0.8rem;">
                        <input type="radio" name="wallet_action" value="deduct_debit" onchange="calcDirectNewBal()" style="margin-right: 4px;">
                        ➖ Debit
                    </label>
                    <label style="cursor: pointer; background: rgba(59, 130, 246, 0.08); border: 1.5px solid rgba(59, 130, 246, 0.35); border-radius: 8px; padding: 8px 4px; text-align: center; color: #60a5fa; font-weight: 700; font-size: 0.8rem;">
                        <input type="radio" name="wallet_action" value="set_exact" onchange="calcDirectNewBal()" style="margin-right: 4px;">
                        ⚙️ Set Exact
                    </label>
                </div>
            </div>

            <!-- Amount Input -->
            <div class="form-group" style="margin-bottom: 12px;">
                <label style="font-size: 0.8rem; color: #94a3b8; font-weight: 700; text-transform: uppercase;" id="directAmountLabel">Amount to Credit (₹) *</label>
                <input type="number" name="amount" id="directAmountInput" min="1" step="0.01" value="500" required oninput="calcDirectNewBal()" style="width: 100%; font-size: 1.25rem; font-weight: 800; color: #fff; padding: 11px; background: rgba(2, 6, 23, 0.7); border: 1.5px solid rgba(16, 185, 129, 0.4); border-radius: 10px;">
                
                <div style="display: flex; gap: 6px; margin-top: 8px; flex-wrap: wrap;">
                    <button type="button" class="btn" style="padding: 4px 10px; font-size: 0.75rem; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15);" onclick="setDirectAmount(100)">+₹100</button>
                    <button type="button" class="btn" style="padding: 4px 10px; font-size: 0.75rem; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15);" onclick="setDirectAmount(500)">+₹500</button>
                    <button type="button" class="btn" style="padding: 4px 10px; font-size: 0.75rem; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15);" onclick="setDirectAmount(1000)">+₹1,000</button>
                    <button type="button" class="btn" style="padding: 4px 10px; font-size: 0.75rem; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15);" onclick="setDirectAmount(2500)">+₹2,500</button>
                    <button type="button" class="btn" style="padding: 4px 10px; font-size: 0.75rem; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15);" onclick="setDirectAmount(5000)">+₹5,000</button>
                </div>
            </div>

            <!-- Projected Live Preview -->
            <div style="background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.25); border-radius: 10px; padding: 10px 14px; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 0.82rem; color: #cbd5e1; font-weight: 600;">Projected New Balance:</span>
                <strong style="color: #10b981; font-size: 1.25rem;" id="directProjectedBal">₹500.00</strong>
            </div>

            <!-- Remarks -->
            <div class="form-group" style="margin-bottom: 18px;">
                <label style="font-size: 0.8rem; color: #94a3b8; font-weight: 700; text-transform: uppercase;">Note / Reason for Adjustment</label>
                <input type="text" name="remarks" value="Direct Credit Added by Admin" placeholder="e.g. Approved Credit Topup by Superadmin" style="width: 100%; padding: 10px; background: rgba(2, 6, 23, 0.7); border: 1px solid var(--border-color); border-radius: 8px; color: #fff; font-size: 0.85rem;">
            </div>

            <button type="submit" class="btn" style="width: 100%; padding: 12px; background: linear-gradient(135deg, #10b981, #059669); color: #fff; font-weight: 800; font-size: 0.95rem; border-radius: 10px; display: flex; justify-content: center; align-items: center; gap: 8px;">
                <i data-lucide="check-circle-2"></i> ✓ Apply Wallet Balance Update
            </button>
        </form>
    </div>

    <!-- 2. ADD MONEY VIA PAYU PAYMENT GATEWAY CARD -->
    <div class="card" style="border: 1px solid var(--border-color); background: rgba(30, 41, 59, 0.4);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 12px;">
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="credit-card" style="color: var(--primary);"></i> PayU Gateway Self-Topup
            </h3>
            <span class="badge badge-primary" style="font-size: 0.72rem; padding: 4px 10px;">Online Gateway</span>
        </div>

        <form action="<?=url('/api/wallet-payu-init.php')?>" method="POST">
            <input type="hidden" name="shop_id" value="<?=$shopId?>">

            <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border-color); border-radius: 10px; padding: 14px; margin-bottom: 18px;">
                <div style="font-size: 0.8rem; color: #94a3b8; font-weight: 700; text-transform: uppercase;">Selected Outlet</div>
                <div style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-top: 4px;"><?=e($shopName)?></div>
                <div style="font-size: 0.85rem; color: #10b981; font-weight: 700; margin-top: 2px;">Balance: <?=money($walletBalance)?></div>
            </div>

            <div class="form-group" style="margin-bottom: 16px;">
                <label style="font-size: 0.82rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase;">Enter Online Amount (₹ - Min ₹10)</label>
                <input type="number" name="amount" id="topupAmount" min="10" max="100000" step="0.01" value="500" required style="width: 100%; font-size: 1.2rem; font-weight: 700; color: var(--primary); padding: 12px; background: rgba(15,23,42,0.6); border: 1px solid var(--border-color); border-radius: 10px;">
            </div>

            <!-- Quick Amount Presets -->
            <div style="display: flex; gap: 6px; margin-bottom: 20px; flex-wrap: wrap;">
                <button type="button" class="btn" style="padding: 5px 12px; font-size: 0.8rem; background: rgba(30,41,59,0.8); border: 1px solid var(--border-color);" onclick="setPayUAmount(100)">+ ₹100</button>
                <button type="button" class="btn" style="padding: 5px 12px; font-size: 0.8rem; background: rgba(30,41,59,0.8); border: 1px solid var(--border-color);" onclick="setPayUAmount(500)">+ ₹500</button>
                <button type="button" class="btn" style="padding: 5px 12px; font-size: 0.8rem; background: rgba(30,41,59,0.8); border: 1px solid var(--border-color);" onclick="setPayUAmount(1000)">+ ₹1,000</button>
                <button type="button" class="btn" style="padding: 5px 12px; font-size: 0.8rem; background: rgba(30,41,59,0.8); border: 1px solid var(--border-color);" onclick="setPayUAmount(2500)">+ ₹2,500</button>
            </div>

            <button type="submit" class="btn" style="width: 100%; padding: 12px; background: linear-gradient(135deg, var(--primary), #1d4ed8); font-size: 0.95rem; font-weight: 700; border-radius: 10px;">
                <i data-lucide="shield-check"></i> 🚀 Proceed to PayU Checkout
            </button>
        </form>
    </div>
</div>

<!-- WALLET TRANSACTIONS HISTORY TABLE -->
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin: 0; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="history" style="color: var(--primary);"></i> Wallet Ledger & Transaction Audit (<?=e($shopName)?>)
        </h3>
        <span class="muted" style="font-size: 0.82rem;">Showing latest 50 wallet activities</span>
    </div>

    <div style="overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted); text-align: left;">
                    <th style="padding: 12px;">#</th>
                    <th style="padding: 12px;">Date & Time</th>
                    <th style="padding: 12px;">Transaction Ref</th>
                    <th style="padding: 12px;">Channel / Gateway</th>
                    <th style="padding: 12px;">Remarks</th>
                    <th style="padding: 12px;">Type</th>
                    <th style="padding: 12px;">Amount</th>
                    <th style="padding: 12px;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($transactions)): ?>
                    <tr><td colspan="8" style="text-align:center; padding: 24px; color: var(--text-muted);">No wallet transactions recorded for this store yet.</td></tr>
                <?php else: ?>
                    <?php foreach($transactions as $idx => $t): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 12px;"><?=$idx + 1?></td>
                            <td style="padding: 12px;"><?=date('d M Y, h:i A', strtotime($t['created_at']))?></td>
                            <td style="padding: 12px;"><strong><?=e($t['txnid'])?></strong></td>
                            <td style="padding: 12px;">
                                <?php if ($t['payment_gateway'] === 'Admin Manual'): ?>
                                    <span class="badge" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.35);">
                                        <i data-lucide="shield-check" style="width: 12px; height: 12px;"></i> Admin Manual
                                    </span>
                                <?php else: ?>
                                    <span class="badge" style="background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.35);">
                                        <?=e($t['payment_gateway'] ?: 'PayU')?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px; max-width: 320px; word-break: break-word;"><?=e($t['remarks'] ?: 'Wallet Action')?></td>
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
let currentSelectedBal = <?=json_encode($walletBalance)?>;

function onShopChange(shopId) {
    const sel = document.getElementById('directShopSelect');
    const opt = sel.options[sel.selectedIndex];
    currentSelectedBal = parseFloat(opt.getAttribute('data-balance') || 0);
    document.getElementById('directCurrentBalDisplay').textContent = '₹' + currentSelectedBal.toLocaleString('en-IN', { minimumFractionDigits: 2 });
    calcDirectNewBal();
}

function setDirectAmount(amt) {
    document.getElementById('directAmountInput').value = amt;
    calcDirectNewBal();
}

function calcDirectNewBal() {
    const actionEl = document.querySelector('input[name="wallet_action"]:checked');
    const action = actionEl ? actionEl.value : 'add_credit';
    const amt = parseFloat(document.getElementById('directAmountInput').value) || 0;
    const label = document.getElementById('directAmountLabel');
    
    let newBal = currentSelectedBal;
    if (action === 'add_credit') {
        newBal = currentSelectedBal + amt;
        if (label) label.textContent = 'Amount to Credit (₹) *';
    } else if (action === 'deduct_debit') {
        newBal = Math.max(0, currentSelectedBal - amt);
        if (label) label.textContent = 'Amount to Deduct (₹) *';
    } else if (action === 'set_exact') {
        newBal = Math.max(0, amt);
        if (label) label.textContent = 'Set New Exact Balance (₹) *';
    }
    
    document.getElementById('directProjectedBal').textContent = '₹' + newBal.toLocaleString('en-IN', { minimumFractionDigits: 2 });
}

function setPayUAmount(val) {
    document.getElementById('topupAmount').value = val;
}

// Initial calculation on load
calcDirectNewBal();
</script>

<?php render_end(); ?>
