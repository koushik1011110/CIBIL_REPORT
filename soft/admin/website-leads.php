<?php
require_once __DIR__ . '/../includes/layout.php';
role('superadmin');

$p = db();
$msg = '';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $leadId = (int)($_POST['lead_id'] ?? 0);

    if ($action === 'convert' && $leadId > 0) {
        $lStmt = $p->prepare("SELECT * FROM website_leads WHERE id = ?");
        $lStmt->execute([$leadId]);
        $lead = $lStmt->fetch();

        if ($lead) {
            $shopId = (int)(u()['shop_id'] ?: 1);

            // 1. Get or Create Customer
            $cStmt = $p->prepare("SELECT id FROM customers WHERE mobile = ? LIMIT 1");
            $cStmt->execute([$lead['mobile']]);
            $custId = $cStmt->fetchColumn();

            if (!$custId) {
                $insCust = $p->prepare("INSERT INTO customers (shop_id, name, mobile, email, status) VALUES (?, ?, ?, ?, 'active')");
                $insCust->execute([$shopId, $lead['name'], $lead['mobile'], $lead['email']]);
                $custId = $p->lastInsertId();
            }

            // 2. Find or Create Product
            $pStmt = $p->prepare("SELECT id FROM products WHERE name = ? LIMIT 1");
            $pStmt->execute([$lead['product']]);
            $productId = $pStmt->fetchColumn() ?: null;

            if (!$productId) {
                $insProd = $p->prepare("INSERT INTO products (shop_id, name, brand, model, selling_price, status) VALUES (?, ?, 'Generic', 'Standard', ?, 'active')");
                $insProd->execute([$shopId, $lead['product'], $prodPrice]);
                $productId = $p->lastInsertId();
            }

            // 3. Create Finance Application
            $appNo = 'APP' . time() . rand(10,99);
            $prodPrice = (float)$lead['price'];
            $downPayment = (float)$lead['down_payment'];
            $financeAmount = max(0, $prodPrice - $downPayment);
            $tenure = (int)$lead['tenure'] ?: 6;
            $rate = 1.5;
            $totalInt = ($financeAmount * $rate * $tenure) / 100;
            $totalPayable = $financeAmount + $totalInt;
            $emi = ceil($totalPayable / $tenure);

            $insApp = $p->prepare("
                INSERT INTO finance_applications (
                    application_no, shop_id, customer_id, product_id, product_name, product_price, down_payment, finance_amount, interest_rate, tenure, emi, total_payable, status, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
            ");
            $insApp->execute([
                $appNo, $shopId, $custId, $productId, $lead['product'], $prodPrice, $downPayment, $financeAmount, $rate, $tenure, $emi, $totalPayable
            ]);
            $financeId = $p->lastInsertId();

            // 4. Mark Lead as Converted
            $p->prepare("UPDATE website_leads SET status = 'converted' WHERE id = ?")->execute([$leadId]);


            // Redirect directly to 4-Step Onboarding Process
            header('Location: ' . url('/application-process.php?id=' . $financeId));
            exit;
        }
    } elseif ($action === 'mark_contacted' && $leadId > 0) {
        $p->prepare("UPDATE website_leads SET status = 'contacted' WHERE id = ?")->execute([$leadId]);
        header('Location: website-leads.php?msg=updated');
        exit;
    } elseif ($action === 'delete' && $leadId > 0) {
        $p->prepare("DELETE FROM website_leads WHERE id = ?")->execute([$leadId]);
        header('Location: website-leads.php?msg=deleted');
        exit;
    }
}

// Fetch Leads
$leads = $p->query("SELECT * FROM website_leads ORDER BY id DESC")->fetchAll();

start('Website Installment Leads');
?>

<div class="card" style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <div>
        <h3 style="font-size: 1.15rem; font-weight: 800;">Website Installment Inquiries & Leads</h3>
        <p class="muted">Customer application requests submitted from frontend website (apply.php)</p>
    </div>
    <span class="badge badge-primary" style="font-size: 0.85rem; padding: 6px 14px;">Total Leads: <?=count($leads)?></span>
</div>

<div class="card" style="overflow-x: auto;">
    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.88rem;">
        <thead>
            <tr style="border-bottom: 2px solid var(--border-color); color: var(--text-muted);">
                <th style="padding: 12px;">ID & Date</th>
                <th style="padding: 12px;">Customer Details</th>
                <th style="padding: 12px;">Requested Product</th>
                <th style="padding: 12px;">Approx Price</th>
                <th style="padding: 12px;">Down Payment & Tenure</th>
                <th style="padding: 12px;">Status</th>
                <th style="padding: 12px;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($leads)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 24px; color: var(--text-muted);">No website leads received yet. Submit an application from <a href="<?=url('/apply.php')?>" target="_blank" style="color: var(--primary);">frontend website apply page</a> to test.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($leads as $l): ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 12px;">
                            <strong>#LEAD-<?=$l['id']?></strong><br>
                            <span style="font-size: 0.75rem; color: var(--text-muted);"><?=date('d M Y, h:i A', strtotime($l['created_at']))?></span>
                        </td>
                        <td style="padding: 12px;">
                            <strong><?=e($l['name'])?></strong><br>
                            <span style="font-size: 0.8rem; color: var(--text-muted);"><?=e($l['mobile'])?></span>
                            <?php if ($l['email']): ?><br><span style="font-size: 0.75rem; color: var(--text-muted);"><?=e($l['email'])?></span><?php endif; ?>
                        </td>
                        <td style="padding: 12px;">
                            <span class="badge" style="background: rgba(59,130,246,0.12); color: var(--primary); font-weight: 700;"><?=e($l['product'])?></span>
                        </td>
                        <td style="padding: 12px;">
                            <strong><?=money($l['price'])?></strong>
                        </td>
                        <td style="padding: 12px;">
                            <strong style="color: var(--success);"><?=money($l['down_payment'])?></strong><br>
                            <span style="font-size: 0.75rem; color: var(--text-muted);"><?=e($l['tenure'])?> Months EMI</span>
                        </td>
                        <td style="padding: 12px;">
                            <?php if ($l['status'] === 'converted'): ?>
                                <span class="badge badge-success">✓ CONVERTED</span>
                            <?php elseif ($l['status'] === 'contacted'): ?>
                                <span class="badge badge-primary">CONTACTED</span>
                            <?php else: ?>
                                <span class="badge badge-warning">NEW LEAD</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px;">
                            <div style="display: flex; gap: 6px; flex-wrap: wrap;">
                                <?php if ($l['status'] !== 'converted'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="convert">
                                        <input type="hidden" name="lead_id" value="<?=$l['id']?>">
                                        <button type="submit" class="btn" style="padding: 6px 12px; font-size: 0.78rem; background: linear-gradient(135deg, var(--primary), #2563eb);">
                                            🚀 Convert & Start 4-Step KYC
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($l['status'] === 'new'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="mark_contacted">
                                        <input type="hidden" name="lead_id" value="<?=$l['id']?>">
                                        <button type="submit" class="btn" style="padding: 6px 10px; font-size: 0.75rem; background: var(--text-muted);">
                                            Mark Contacted
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this lead?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="lead_id" value="<?=$l['id']?>">
                                    <button type="submit" class="btn" style="padding: 6px 10px; font-size: 0.75rem; background: rgba(239,68,68,0.15); color: var(--danger); border: 1px solid var(--danger);">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php render_end(); ?>
