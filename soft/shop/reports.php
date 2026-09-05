<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/onboarding_db_init.php';
role('shop_admin', 'superadmin', 'staff');

$p = db();
$u = u();
$shopId = (int)($u['shop_id'] ?? 0);

if ($shopId === 0 && $u['role'] === 'superadmin') {
    $shopId = (int)($p->query("SELECT id FROM shops ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 1);
}

$type = $_GET['type'] ?? 'pos_sales';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to'] ?? date('Y-m-d');
$paymentMethod = $_GET['payment_method'] ?? 'all';
$search   = trim($_GET['search'] ?? '');

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    if ($type === 'pos_sales') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="POS_Sales_Report_' . date('Ymd') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Invoice No', 'Date', 'Customer Name', 'Mobile', 'GSTIN', 'Tax Type', 'Payment Method', 'Taxable Amount', 'CGST', 'SGST', 'IGST', 'Total GST', 'Discount', 'Grand Total']);
        
        $sql = "SELECT * FROM pos_sales WHERE shop_id = ? AND DATE(created_at) BETWEEN ? AND ?";
        $params = [$shopId, $dateFrom, $dateTo];
        if ($paymentMethod !== 'all') {
            $sql .= " AND payment_method = ?";
            $params[] = $paymentMethod;
        }
        $sql .= " ORDER BY id DESC";
        
        $stmt = $p->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            fputcsv($output, [
                $row['invoice_no'],
                $row['created_at'],
                $row['customer_name'],
                $row['customer_mobile'],
                $row['customer_gstin'],
                $row['tax_type'],
                strtoupper($row['payment_method']),
                $row['taxable_amount'],
                $row['cgst_amount'],
                $row['sgst_amount'],
                $row['igst_amount'],
                $row['total_gst'],
                $row['discount'],
                $row['grand_total']
            ]);
        }
        fclose($output);
        exit;
    } elseif ($type === 'gstr1') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="GSTR1_Return_Report_' . date('Ymd') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Type (B2B/B2C)', 'GSTIN / Rate Slab', 'Customer Name / Supply Type', 'Invoice No', 'Invoice Date', 'HSN Code', 'Taxable Value', 'CGST Amount', 'SGST Amount', 'IGST Amount', 'Total Tax', 'Invoice Total']);
        
        // Fetch B2B
        $b2bStmt = $p->prepare("SELECT s.*, i.hsn_code, i.gst_rate FROM pos_sales s JOIN pos_sale_items i ON i.pos_sale_id = s.id WHERE s.shop_id = ? AND s.customer_gstin != '' AND s.customer_gstin IS NOT NULL AND DATE(s.created_at) BETWEEN ? AND ? ORDER BY s.id DESC");
        $b2bStmt->execute([$shopId, $dateFrom, $dateTo]);
        while ($r = $b2bStmt->fetch()) {
            fputcsv($output, ['B2B', $r['customer_gstin'], $r['customer_name'], $r['invoice_no'], $r['created_at'], $r['hsn_code'], $r['taxable_amount'], $r['cgst_amount'], $r['sgst_amount'], $r['igst_amount'], $r['total_gst'], $r['grand_total']]);
        }
        
        // Fetch B2C Slabs
        $b2cStmt = $p->prepare("SELECT i.gst_rate, s.tax_type, SUM(i.taxable_amount) as total_taxable, SUM(i.gst_amount) as total_gst FROM pos_sale_items i JOIN pos_sales s ON s.id = i.pos_sale_id WHERE s.shop_id = ? AND (s.customer_gstin = '' OR s.customer_gstin IS NULL) AND DATE(s.created_at) BETWEEN ? AND ? GROUP BY i.gst_rate, s.tax_type");
        $b2cStmt->execute([$shopId, $dateFrom, $dateTo]);
        while ($r = $b2cStmt->fetch()) {
            $cgst = $r['tax_type'] === 'intra_state' ? ($r['total_gst']/2) : 0;
            $sgst = $r['tax_type'] === 'intra_state' ? ($r['total_gst']/2) : 0;
            $igst = $r['tax_type'] === 'inter_state' ? $r['total_gst'] : 0;
            fputcsv($output, ['B2C Summary', $r['gst_rate'] . '% Slab', strtoupper($r['tax_type']), 'Aggregated', '-', 'Multiple', $r['total_taxable'], $cgst, $sgst, $igst, $r['total_gst'], $r['total_taxable'] + $r['total_gst']]);
        }
        fclose($output);
        exit;
    } elseif ($type === 'audit_logs') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="Audit_Trail_Logs_' . date('Ymd') . '.csv"');
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Log ID', 'Timestamp', 'User / Collector Staff', 'Role', 'Module', 'Action Event', 'Activity Details']);
        
        $sql = "SELECT a.*, u.name as user_name, u.role as user_role FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE DATE(a.created_at) BETWEEN ? AND ? ORDER BY a.id DESC";
        $stmt = $p->prepare($sql);
        $stmt->execute([$dateFrom, $dateTo]);
        while ($r = $stmt->fetch()) {
            fputcsv($output, [
                $r['id'],
                $r['created_at'],
                $r['user_name'] ?: 'System / Automated',
                strtoupper($r['user_role'] ?: 'SYSTEM'),
                $r['module'],
                $r['action'],
                $r['description']
            ]);
        }
        fclose($output);
        exit;
    }
}

// Handle Invoice Deletion
if (isset($_POST['action']) && $_POST['action'] === 'delete_pos_sale') {
    $delId = (int)$_POST['sale_id'];
    if ($delId > 0) {
        $p->prepare("DELETE FROM pos_sales WHERE id = ? AND shop_id = ?")->execute([$delId, $shopId]);
    }
    header("Location: reports.php?type=pos_sales&date_from=$dateFrom&date_to=$dateTo");
    exit;
}

start('Reports & GST Returns Center');
?>

<style>
.report-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    border-bottom: 2px solid var(--border-color);
    padding-bottom: 10px;
    flex-wrap: wrap;
}
.report-tab-btn {
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 0.88rem;
    font-weight: 700;
    color: var(--text-muted);
    text-decoration: none;
    background: rgba(255,255,255,0.05);
    border: 1px solid var(--border-color);
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}
.report-tab-btn:hover, .report-tab-btn.active {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
    box-shadow: 0 4px 15px var(--primary-glow);
}
.filter-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 20px;
}
</style>



<!-- DATE & FILTER BAR -->
<div class="filter-card">
    <form method="GET" style="display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap;">
        <input type="hidden" name="type" value="<?=e($type)?>">
        
        <div>
            <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">Date From</label>
            <input type="date" name="date_from" value="<?=e($dateFrom)?>" style="height: 38px; font-size: 0.85rem;">
        </div>
        
        <div>
            <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">Date To</label>
            <input type="date" name="date_to" value="<?=e($dateTo)?>" style="height: 38px; font-size: 0.85rem;">
        </div>
        
        <?php if ($type === 'pos_sales'): ?>
            <div>
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">Payment Method</label>
                <select name="payment_method" style="height: 38px; font-size: 0.85rem;">
                    <option value="all" <?=$paymentMethod==='all'?'selected':''?>>All Methods</option>
                    <option value="cash" <?=$paymentMethod==='cash'?'selected':''?>>Cash</option>
                    <option value="upi" <?=$paymentMethod==='upi'?'selected':''?>>UPI / QR</option>
                    <option value="card" <?=$paymentMethod==='card'?'selected':''?>>Card</option>
                    <option value="netbanking" <?=$paymentMethod==='netbanking'?'selected':''?>>NetBanking</option>
                </select>
            </div>
            
            <div style="flex: 1; min-width: 180px;">
                <label style="font-size: 0.78rem; font-weight: 700; color: var(--text-muted); display: block; margin-bottom: 4px;">Search Invoice / Customer</label>
                <input type="text" name="search" value="<?=e($search)?>" placeholder="Invoice No, Name, Mobile..." style="height: 38px; font-size: 0.85rem; width: 100%;">
            </div>
        <?php endif; ?>
        
        <button type="submit" class="btn" style="height: 38px; padding: 0 16px; background: var(--primary);">
            🔍 Filter Report
        </button>
        
        <?php if (in_array($type, ['pos_sales', 'gstr1'])): ?>
            <a href="reports.php?type=<?=e($type)?>&date_from=<?=e($dateFrom)?>&date_to=<?=e($dateTo)?>&payment_method=<?=e($paymentMethod)?>&export=csv" class="btn" style="height: 38px; padding: 0 16px; background: #10b981; color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 6px;">
                📥 Export CSV
            </a>
        <?php endif; ?>
    </form>
</div>

<?php if ($type === 'pos_sales'): ?>
    <!-- TAB 1: POS SALES INVOICES REPORT -->
    <?php
    $whereSql = "WHERE shop_id = ? AND DATE(created_at) BETWEEN ? AND ?";
    $params = [$shopId, $dateFrom, $dateTo];
    
    if ($paymentMethod !== 'all') {
        $whereSql .= " AND payment_method = ?";
        $params[] = $paymentMethod;
    }
    if (!empty($search)) {
        $whereSql .= " AND (invoice_no LIKE ? OR customer_name LIKE ? OR customer_mobile LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $summaryStmt = $p->prepare("
        SELECT 
            COUNT(*) as total_count,
            COALESCE(SUM(taxable_amount), 0) as total_taxable,
            COALESCE(SUM(total_gst), 0) as total_gst,
            COALESCE(SUM(discount), 0) as total_discount,
            COALESCE(SUM(grand_total), 0) as total_sales
        FROM pos_sales $whereSql
    ");
    $summaryStmt->execute($params);
    $sum = $summaryStmt->fetch();
    
    $rowsStmt = $p->prepare("SELECT * FROM pos_sales $whereSql ORDER BY id DESC");
    $rowsStmt->execute($params);
    $salesRows = $rowsStmt->fetchAll();
    ?>

    <!-- METRICS CARDS -->
    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
        <div class="card">
            <div class="muted" style="font-size: 0.78rem;">Total Invoices Issued</div>
            <div class="metric" style="font-size: 1.6rem; margin-top: 4px;"><?=number_format($sum['total_count'])?></div>
        </div>
        <div class="card">
            <div class="muted" style="font-size: 0.78rem;">Total Sales Value</div>
            <div class="metric" style="font-size: 1.6rem; color: #10b981; margin-top: 4px;"><?=money($sum['total_sales'])?></div>
        </div>
        <div class="card">
            <div class="muted" style="font-size: 0.78rem;">Total Taxable Turnover</div>
            <div class="metric" style="font-size: 1.6rem; color: var(--primary); margin-top: 4px;"><?=money($sum['total_taxable'])?></div>
        </div>
        <div class="card">
            <div class="muted" style="font-size: 0.78rem;">Total GST Tax Collected</div>
            <div class="metric" style="font-size: 1.6rem; color: #f59e0b; margin-top: 4px;"><?=money($sum['total_gst'])?></div>
        </div>
    </div>

    <!-- SALES TABLE -->
    <div class="card" style="padding: 0; overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                    <th style="padding: 12px;">Invoice No</th>
                    <th style="padding: 12px;">Date & Time</th>
                    <th style="padding: 12px;">Customer Details</th>
                    <th style="padding: 12px;">Mode</th>
                    <th style="padding: 12px;">Taxable</th>
                    <th style="padding: 12px;">CGST / SGST / IGST</th>
                    <th style="padding: 12px;">Grand Total</th>
                    <th style="padding: 12px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($salesRows)): ?>
                    <tr><td colspan="8" style="text-align: center; padding: 20px;" class="muted">No POS invoices found for selected date range.</td></tr>
                <?php else: ?>
                    <?php foreach ($salesRows as $r): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 12px;">
                                <strong style="color: var(--primary);"><?=e($r['invoice_no'])?></strong>
                            </td>
                            <td style="padding: 12px;"><?=date('d M Y, h:i A', strtotime($r['created_at']))?></td>
                            <td style="padding: 12px;">
                                <strong><?=e($r['customer_name'])?></strong><br>
                                <span style="font-size: 0.75rem; color: var(--text-muted);"><?=e($r['customer_mobile'])?></span>
                                <?php if (!empty($r['customer_gstin'])): ?>
                                    <br><span style="font-size: 0.72rem; color: #3b82f6; font-weight: 700;">GSTIN: <?=e($r['customer_gstin'])?></span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px;">
                                <span class="badge badge-info" style="text-transform: uppercase;"><?=e($r['payment_method'])?></span>
                            </td>
                            <td style="padding: 12px;"><?=money($r['taxable_amount'])?></td>
                            <td style="padding: 12px;">
                                <?php if ($r['tax_type'] === 'intra_state'): ?>
                                    <span style="font-size: 0.75rem; color: #10b981;">C: <?=money($r['cgst_amount'])?> | S: <?=money($r['sgst_amount'])?></span>
                                <?php else: ?>
                                    <span style="font-size: 0.75rem; color: #3b82f6;">IGST: <?=money($r['igst_amount'])?></span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px;">
                                <strong style="color: var(--success); font-size: 0.95rem;"><?=money($r['grand_total'])?></strong>
                            </td>
                            <td style="padding: 12px;">
                                <div style="display: flex; gap: 6px;">
                                    <a href="pos-invoice.php?id=<?=$r['id']?>" target="_blank" class="btn" style="padding: 4px 10px; font-size: 0.75rem; background: var(--primary);">
                                        🖨️ View Invoice
                                    </a>
                                    <form method="POST" onsubmit="return confirm('Delete invoice <?=e($r['invoice_no'])?>?');" style="display: inline;">
                                        <input type="hidden" name="action" value="delete_pos_sale">
                                        <input type="hidden" name="sale_id" value="<?=$r['id']?>">
                                        <button type="submit" class="btn" style="padding: 4px 8px; font-size: 0.75rem; background: rgba(239,68,68,0.2); color: var(--danger); border: 1px solid var(--danger);">🗑️</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($type === 'gstr1'): ?>
    <!-- TAB 2: GST RETURN GSTR-1 SUMMARY REPORT -->
    <?php
    // B2B Sales (Registered dealers with GSTIN)
    $b2bStmt = $p->prepare("
        SELECT s.*, i.hsn_code, i.gst_rate 
        FROM pos_sales s 
        JOIN pos_sale_items i ON i.pos_sale_id = s.id 
        WHERE s.shop_id = ? AND s.customer_gstin != '' AND s.customer_gstin IS NOT NULL 
          AND DATE(s.created_at) BETWEEN ? AND ? 
        ORDER BY s.id DESC
    ");
    $b2bStmt->execute([$shopId, $dateFrom, $dateTo]);
    $b2bRows = $b2bStmt->fetchAll();

    // B2C Sales (Retail Unregistered customers grouped by GST Slab & Tax Type)
    $b2cStmt = $p->prepare("
        SELECT 
            i.gst_rate, 
            s.tax_type, 
            COUNT(DISTINCT s.id) as invoice_count,
            SUM(i.taxable_amount) as total_taxable, 
            SUM(i.gst_amount) as total_gst 
        FROM pos_sale_items i 
        JOIN pos_sales s ON s.id = i.pos_sale_id 
        WHERE s.shop_id = ? AND (s.customer_gstin = '' OR s.customer_gstin IS NULL) 
          AND DATE(s.created_at) BETWEEN ? AND ? 
        GROUP BY i.gst_rate, s.tax_type
        ORDER BY i.gst_rate ASC
    ");
    $b2cStmt->execute([$shopId, $dateFrom, $dateTo]);
    $b2cSlabs = $b2cStmt->fetchAll();

    // Total GSTR-1 Metrics
    $totGstr1Stmt = $p->prepare("
        SELECT 
            COALESCE(SUM(taxable_amount), 0) as total_taxable,
            COALESCE(SUM(cgst_amount), 0) as total_cgst,
            COALESCE(SUM(sgst_amount), 0) as total_sgst,
            COALESCE(SUM(igst_amount), 0) as total_igst,
            COALESCE(SUM(total_gst), 0) as total_gst,
            COALESCE(SUM(grand_total), 0) as total_turnover
        FROM pos_sales 
        WHERE shop_id = ? AND DATE(created_at) BETWEEN ? AND ?
    ");
    $totGstr1Stmt->execute([$shopId, $dateFrom, $dateTo]);
    $gSum = $totGstr1Stmt->fetch();
    ?>

    <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px;">
        <div class="card">
            <div class="muted" style="font-size: 0.78rem;">Total GSTR-1 Turnover</div>
            <div class="metric" style="font-size: 1.6rem; color: #10b981; margin-top: 4px;"><?=money($gSum['total_turnover'])?></div>
        </div>
        <div class="card">
            <div class="muted" style="font-size: 0.78rem;">Total Taxable Turnover</div>
            <div class="metric" style="font-size: 1.6rem; color: var(--primary); margin-top: 4px;"><?=money($gSum['total_taxable'])?></div>
        </div>
        <div class="card">
            <div class="muted" style="font-size: 0.78rem;">CGST + SGST Liability</div>
            <div class="metric" style="font-size: 1.4rem; color: #f59e0b; margin-top: 4px;"><?=money($gSum['total_cgst'] + $gSum['total_sgst'])?></div>
        </div>
        <div class="card">
            <div class="muted" style="font-size: 0.78rem;">IGST Tax Liability</div>
            <div class="metric" style="font-size: 1.4rem; color: #3b82f6; margin-top: 4px;"><?=money($gSum['total_igst'])?></div>
        </div>
    </div>

    <!-- TABLE 1: B2B REGISTERED SALES -->
    <div class="card" style="margin-bottom: 24px;">
        <h4 style="font-size: 1rem; font-weight: 800; margin-bottom: 14px; color: var(--primary);">
            🏢 1. B2B Invoices (Sales to Registered Dealers with GSTIN)
        </h4>
        <div style="overflow-x: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.82rem;">
                <thead>
                    <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                        <th style="padding: 10px;">Customer GSTIN</th>
                        <th style="padding: 10px;">Party Name</th>
                        <th style="padding: 10px;">Invoice No</th>
                        <th style="padding: 10px;">Date</th>
                        <th style="padding: 10px;">HSN</th>
                        <th style="padding: 10px;">Rate</th>
                        <th style="padding: 10px;">Taxable (₹)</th>
                        <th style="padding: 10px;">CGST (₹)</th>
                        <th style="padding: 10px;">SGST (₹)</th>
                        <th style="padding: 10px;">IGST (₹)</th>
                        <th style="padding: 10px;">Invoice Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($b2bRows)): ?>
                        <tr><td colspan="11" style="text-align: center; padding: 16px;" class="muted">No B2B registered dealer sales found in this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($b2bRows as $b): ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 10px;"><strong style="color: #3b82f6;"><?=e($b['customer_gstin'])?></strong></td>
                                <td style="padding: 10px;"><strong><?=e($b['customer_name'])?></strong></td>
                                <td style="padding: 10px;"><?=e($b['invoice_no'])?></td>
                                <td style="padding: 10px;"><?=date('d M Y', strtotime($b['created_at']))?></td>
                                <td style="padding: 10px;"><?=e($b['hsn_code']?:'8517')?></td>
                                <td style="padding: 10px;"><?=floatval($b['gst_rate'])?>%</td>
                                <td style="padding: 10px;"><?=money($b['taxable_amount'])?></td>
                                <td style="padding: 10px;"><?=money($b['cgst_amount'])?></td>
                                <td style="padding: 10px;"><?=money($b['sgst_amount'])?></td>
                                <td style="padding: 10px;"><?=money($b['igst_amount'])?></td>
                                <td style="padding: 10px;"><strong style="color: #10b981;"><?=money($b['grand_total'])?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- TABLE 2: B2C UNREGISTERED RETAIL SUMMARY -->
    <div class="card">
        <h4 style="font-size: 1rem; font-weight: 800; margin-bottom: 14px; color: #10b981;">
            🛒 2. B2C Retail Summary (Grouped by Tax Rate Slab & Supply Type)
        </h4>
        <div style="overflow-x: auto;">
            <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                <thead>
                    <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                        <th style="padding: 10px;">GST Rate Slab</th>
                        <th style="padding: 10px;">Supply Type</th>
                        <th style="padding: 10px;">Invoices Count</th>
                        <th style="padding: 10px;">Total Taxable Value (₹)</th>
                        <th style="padding: 10px;">CGST Amount (₹)</th>
                        <th style="padding: 10px;">SGST Amount (₹)</th>
                        <th style="padding: 10px;">IGST Amount (₹)</th>
                        <th style="padding: 10px;">Total Tax (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($b2cSlabs)): ?>
                        <tr><td colspan="8" style="text-align: center; padding: 16px;" class="muted">No B2C sales found in this period.</td></tr>
                    <?php else: ?>
                        <?php foreach ($b2cSlabs as $s): ?>
                            <?php
                            $cgst = $s['tax_type'] === 'intra_state' ? ($s['total_gst'] / 2) : 0;
                            $sgst = $s['tax_type'] === 'intra_state' ? ($s['total_gst'] / 2) : 0;
                            $igst = $s['tax_type'] === 'inter_state' ? $s['total_gst'] : 0;
                            ?>
                            <tr style="border-bottom: 1px solid var(--border-color);">
                                <td style="padding: 10px;"><span class="badge badge-info" style="font-weight: 800; font-size: 0.82rem;"><?=floatval($s['gst_rate'])?>% Slab</span></td>
                                <td style="padding: 10px; text-transform: uppercase; font-weight: 700;"><?=e($s['tax_type'] === 'inter_state' ? 'Inter-State (IGST)' : 'Intra-State (CGST+SGST)')?></td>
                                <td style="padding: 10px;"><strong><?=$s['invoice_count']?></strong> Invoices</td>
                                <td style="padding: 10px;"><strong><?=money($s['total_taxable'])?></strong></td>
                                <td style="padding: 10px; color: #10b981;"><?=money($cgst)?></td>
                                <td style="padding: 10px; color: #10b981;"><?=money($sgst)?></td>
                                <td style="padding: 10px; color: #3b82f6;"><?=money($igst)?></td>
                                <td style="padding: 10px;"><strong style="color: #f59e0b;"><?=money($s['total_gst'])?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php elseif ($type === 'collections'): ?>
    <!-- TAB 3: STORE COLLECTIONS & PAYMENTS REPORT -->
    <?php
    $cStmt = $p->prepare("
        SELECT p.*, c.name as customer_name, c.mobile as customer_mobile, f.application_no 
        FROM payments p 
        JOIN customers c ON c.id = p.customer_id 
        JOIN finance_applications f ON f.id = p.finance_id 
        WHERE f.shop_id = ? AND DATE(p.paid_at) BETWEEN ? AND ? 
        ORDER BY p.id DESC
    ");
    $cStmt->execute([$shopId, $dateFrom, $dateTo]);
    $payRows = $cStmt->fetchAll();
    ?>
    <div class="card" style="padding: 0; overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                    <th style="padding: 12px;">App No</th>
                    <th style="padding: 12px;">Customer</th>
                    <th style="padding: 12px;">Amount Paid</th>
                    <th style="padding: 12px;">Payment Method</th>
                    <th style="padding: 12px;">Reference No</th>
                    <th style="padding: 12px;">Paid Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($payRows)): ?>
                    <tr><td colspan="6" style="text-align: center; padding: 20px;" class="muted">No collections recorded in this date range.</td></tr>
                <?php else: ?>
                    <?php foreach ($payRows as $r): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 12px;"><strong><?=e($r['application_no'])?></strong></td>
                            <td style="padding: 12px;"><strong><?=e($r['customer_name'])?></strong><br><span style="font-size:0.75rem; color:var(--text-muted);"><?=e($r['customer_mobile'])?></span></td>
                            <td style="padding: 12px;"><strong style="color:var(--success);"><?=money($r['amount'])?></strong></td>
                            <td style="padding: 12px;"><span class="badge badge-info" style="text-transform:uppercase;"><?=e($r['payment_method'])?></span></td>
                            <td style="padding: 12px;"><?=e($r['reference_no'] ?: '-')?></td>
                            <td style="padding: 12px;"><?=date('d M Y, h:i A', strtotime($r['paid_at']))?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($type === 'applications'): ?>
    <!-- TAB 4: STORE FINANCE APPLICATIONS REPORT -->
    <?php
    $appStmt = $p->prepare("
        SELECT f.*, c.name as customer_name, c.mobile as customer_mobile, p.name as product_name 
        FROM finance_applications f 
        JOIN customers c ON c.id = f.customer_id 
        LEFT JOIN products p ON p.id = f.product_id 
        WHERE f.shop_id = ? AND DATE(f.created_at) BETWEEN ? AND ? 
        ORDER BY f.id DESC
    ");
    $appStmt->execute([$shopId, $dateFrom, $dateTo]);
    $appRows = $appStmt->fetchAll();
    ?>
    <div class="card" style="padding: 0; overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                    <th style="padding: 12px;">App No</th>
                    <th style="padding: 12px;">Customer</th>
                    <th style="padding: 12px;">Product</th>
                    <th style="padding: 12px;">Financed Loan</th>
                    <th style="padding: 12px;">EMI</th>
                    <th style="padding: 12px;">Status</th>
                    <th style="padding: 12px;">Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($appRows)): ?>
                    <tr><td colspan="7" style="text-align: center; padding: 20px;" class="muted">No finance applications found in this date range.</td></tr>
                <?php else: ?>
                    <?php foreach ($appRows as $r): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 12px;"><strong><?=e($r['application_no'])?></strong></td>
                            <td style="padding: 12px;"><strong><?=e($r['customer_name'])?></strong><br><span style="font-size:0.75rem; color:var(--text-muted);"><?=e($r['customer_mobile'])?></span></td>
                            <td style="padding: 12px;"><?=e($r['product_name'] ?: 'Mobile Product')?></td>
                            <td style="padding: 12px;"><strong style="color:var(--primary);"><?=money($r['finance_amount'])?></strong></td>
                            <td style="padding: 12px;"><strong><?=money($r['emi'])?>/mo</strong></td>
                            <td style="padding: 12px;">
                                <span class="badge <?=$r['status']==='approved'||$r['status']==='active'?'badge-success':'badge-warning'?>"><?=strtoupper(e($r['status']))?></span>
                            </td>
                            <td style="padding: 12px;"><?=date('d M Y', strtotime($r['created_at']))?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($type === 'audit_logs'): ?>
    <!-- TAB 5: DETAILED AUDIT TRAIL & SYSTEM ACTIVITY LOGS -->
    <?php
    $auditStmt = $p->prepare("
        SELECT a.*, u.name as user_name, u.email as user_email, u.role as user_role 
        FROM audit_logs a 
        LEFT JOIN users u ON u.id = a.user_id 
        WHERE DATE(a.created_at) BETWEEN ? AND ? 
        ORDER BY a.id DESC
    ");
    $auditStmt->execute([$dateFrom, $dateTo]);
    $auditRows = $auditStmt->fetchAll();
    ?>
    <div class="card" style="padding: 0; overflow-x: auto;">
        <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
            <thead>
                <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                    <th style="padding: 12px;">Log ID</th>
                    <th style="padding: 12px;">Timestamp</th>
                    <th style="padding: 12px;">User / Staff</th>
                    <th style="padding: 12px;">Module</th>
                    <th style="padding: 12px;">Action Event</th>
                    <th style="padding: 12px;">Activity Details & Reference</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($auditRows)): ?>
                    <tr><td colspan="6" style="text-align: center; padding: 20px;" class="muted">No audit activity logs recorded in this date range.</td></tr>
                <?php else: ?>
                    <?php foreach ($auditRows as $r): ?>
                        <tr style="border-bottom: 1px solid var(--border-color);">
                            <td style="padding: 12px;"><span style="font-family: monospace; color: var(--primary);">#<?=e($r['id'])?></span></td>
                            <td style="padding: 12px;">
                                <strong><?=date('d M Y', strtotime($r['created_at']))?></strong><br>
                                <span style="font-size:0.75rem; color:var(--text-muted);"><?=date('h:i:s A', strtotime($r['created_at']))?></span>
                            </td>
                            <td style="padding: 12px;">
                                <strong><?=e($r['user_name'] ?: 'System / Automated')?></strong><br>
                                <span class="badge badge-info" style="font-size: 0.72rem; text-transform: uppercase;"><?=e($r['user_role'] ?: 'system')?></span>
                            </td>
                            <td style="padding: 12px;"><span class="badge badge-warning" style="font-weight: 800;"><?=e($r['module'] ?: 'General')?></span></td>
                            <td style="padding: 12px;"><strong style="color: #fff;"><?=e($r['action'])?></strong></td>
                            <td style="padding: 12px; font-size: 0.82rem; color: var(--text-muted);"><?=e($r['description'])?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

<?php render_end(); ?>
