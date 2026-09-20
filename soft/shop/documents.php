<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/document_engine.php';

role('superadmin', 'shop_admin', 'staff');

$p = db();
$user = u();
$isSuperAdmin = ($user['role'] === 'superadmin');
$shopId = (int)($user['shop_id'] ?? 1);

// Fetch Available Loans for Dropdowns & Selection
$loanSql = "
    SELECT f.id, f.application_no, f.finance_amount, f.status, f.created_at,
           c.id as customer_id, c.name as customer_name, c.mobile as customer_mobile,
           p.name as product_name, s.name as shop_name
    FROM finance_applications f
    JOIN customers c ON c.id = f.customer_id
    LEFT JOIN products p ON p.id = f.product_id
    LEFT JOIN shops s ON s.id = f.shop_id
";
if (!$isSuperAdmin) {
    $loanSql .= " WHERE f.shop_id = " . $shopId;
}
$loanSql .= " ORDER BY f.id DESC";
$allLoans = $p->query($loanSql)->fetchAll();

// Filter inputs
$filterType = trim($_GET['type'] ?? '');
$filterSearch = trim($_GET['q'] ?? '');
$filterCustomer = (int)($_GET['customer_id'] ?? 0);
$filterFinance = (int)($_GET['finance_id'] ?? 0);

// Fetch Document History
$histSql = "
    SELECT d.*, 
           c.name as customer_name, c.mobile as customer_mobile,
           f.application_no,
           u.name as generated_by_name
    FROM documents d
    LEFT JOIN customers c ON c.id = d.customer_id
    LEFT JOIN finance_applications f ON f.id = d.finance_id
    LEFT JOIN users u ON u.id = d.generated_by
    WHERE 1=1
";
$params = [];

if (!$isSuperAdmin) {
    $histSql .= " AND (f.shop_id = ? OR d.finance_id IS NULL)";
    $params[] = $shopId;
}

if (!empty($filterType)) {
    $histSql .= " AND d.type = ?";
    $params[] = $filterType;
}
if ($filterCustomer > 0) {
    $histSql .= " AND d.customer_id = ?";
    $params[] = $filterCustomer;
}
if ($filterFinance > 0) {
    $histSql .= " AND d.finance_id = ?";
    $params[] = $filterFinance;
}
if (!empty($filterSearch)) {
    $histSql .= " AND (d.document_no LIKE ? OR c.name LIKE ? OR c.mobile LIKE ? OR f.application_no LIKE ?)";
    $srch = '%' . $filterSearch . '%';
    $params[] = $srch;
    $params[] = $srch;
    $params[] = $srch;
    $params[] = $srch;
}

$histSql .= " ORDER BY d.id DESC LIMIT 100";
$histStmt = $p->prepare($histSql);
$histStmt->execute($params);
$historyRows = $histStmt->fetchAll();

// Document Type Definitions
$docTypesList = [
    'loan_application'      => 'Loan Application Form',
    'sanction_letter'       => 'Loan Sanction Letter',
    'loan_agreement'        => 'Loan Agreement / Finance Agreement',
    'repayment_schedule'    => 'EMI Repayment Schedule',
    'disbursement_letter'   => 'Disbursement Letter / Statement',
    'payment_receipt'       => 'Payment / EMI Receipt',
    'account_statement'     => 'Customer Account Statement (SOA)',
    'outstanding_statement' => 'Outstanding Statement',
    'foreclosure_statement' => 'Foreclosure Statement',
    'noc_certificate'       => 'NOC / No-Due Certificate',
    'loan_closure'          => 'Loan Closure Certificate'
];

start('Loan Document & PDF Automation');
?>

<style>
.doc-action-card { background: var(--bg-card, #1e293b); border: 1px solid var(--border-color, #334155); border-radius: var(--radius-md, 10px); padding: 20px; }
.doc-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 6px; font-size: 0.75rem; font-weight: 700; }
.bulk-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 9999; align-items: center; justify-content: center; padding: 20px; }
.bulk-modal.active { display: flex; }
.bulk-card { background: #0f172a; border: 1px solid #334155; border-radius: 12px; width: 100%; max-width: 650px; padding: 25px; box-shadow: 0 20px 50px rgba(0,0,0,0.5); }
</style>

<div class="card" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <div>
        <h3 style="font-size: 1.25rem; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="file-check" style="color: var(--primary);"></i> Loan Document & PDF Automation
        </h3>
        <p class="muted" style="margin-top: 4px;">Generate, download, print and bulk-export 11 official loan documents</p>
    </div>
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <button type="button" onclick="openBulkModal()" class="btn" style="background: linear-gradient(135deg, #2563eb, #1d4ed8);">
            <i data-lucide="archive"></i> 📦 Bulk PDF Generation (ZIP)
        </button>
        <?php if ($isSuperAdmin): ?>
            <a href="<?=url('/admin/document-templates.php')?>" class="btn" style="background: rgba(255,255,255,0.08); border: 1px solid var(--border-color);">
                <i data-lucide="settings"></i> Manage Templates
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- QUICK GENERATE BAR -->
<div class="doc-action-card" style="margin-bottom: 24px;">
    <h4 style="font-size: 1rem; font-weight: 800; color: #fff; margin-bottom: 14px; display: flex; align-items: center; gap: 6px;">
        <i data-lucide="zap" style="color: #f59e0b;"></i> Quick Document Generation
    </h4>
    <form action="<?=url('/view-document.php')?>" method="GET" target="_blank" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)) auto; gap: 14px; align-items: flex-end;">
        <div class="field" style="margin: 0;">
            <label style="font-size: 0.8rem; font-weight: 700; color: #94a3b8;">1. Select Loan Application *</label>
            <select name="id" required style="width: 100%; padding: 9px; font-size: 0.85rem;" id="quickLoanSelect">
                <option value="">-- Choose Loan Account --</option>
                <?php foreach ($allLoans as $l): ?>
                    <option value="<?=$l['id']?>" <?=($filterFinance === (int)$l['id'] ? 'selected' : '')?>>
                        #<?=e($l['application_no'])?> — <?=e($l['customer_name'])?> (<?=money($l['finance_amount'])?> · <?=strtoupper($l['status'])?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field" style="margin: 0;">
            <label style="font-size: 0.8rem; font-weight: 700; color: #94a3b8;">2. Select Document Type *</label>
            <select name="type" required style="width: 100%; padding: 9px; font-size: 0.85rem;" id="quickTypeSelect">
                <?php foreach ($docTypesList as $dKey => $dLabel): ?>
                    <option value="<?=$dKey?>" <?=($filterType === $dKey ? 'selected' : '')?>>
                        <?=e($dLabel)?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn" style="background: var(--primary); padding: 9px 18px; font-weight: 700;">
                <i data-lucide="eye"></i> 👁️ View & Print
            </button>
            <button type="button" onclick="triggerQuickDownload()" class="btn" style="background: #059669; padding: 9px 18px; font-weight: 700;">
                <i data-lucide="download"></i> ⬇️ Download PDF
            </button>
        </div>
    </form>
</div>

<!-- FILTER & SEARCH BAR -->
<div class="card" style="margin-bottom: 20px; padding: 16px;">
    <form method="GET" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)) auto; gap: 12px; align-items: flex-end;">
        <div class="field" style="margin: 0;">
            <label style="font-size: 0.78rem; font-weight: 700;">Search Keyword</label>
            <input type="text" name="q" value="<?=e($filterSearch)?>" placeholder="Doc No, Customer, Mobile..." style="padding: 8px;">
        </div>

        <div class="field" style="margin: 0;">
            <label style="font-size: 0.78rem; font-weight: 700;">Document Type</label>
            <select name="type" style="padding: 8px;">
                <option value="">-- All Document Types --</option>
                <?php foreach ($docTypesList as $dKey => $dLabel): ?>
                    <option value="<?=$dKey?>" <?=($filterType === $dKey ? 'selected' : '')?>><?=e($dLabel)?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div style="display: flex; gap: 8px;">
            <button type="submit" class="btn" style="padding: 8px 16px;"><i data-lucide="filter"></i> Filter</button>
            <?php if (!empty($filterSearch) || !empty($filterType) || $filterFinance > 0): ?>
                <a href="<?=url($isSuperAdmin ? '/admin/documents.php' : '/shop/documents.php')?>" class="btn" style="background: rgba(255,255,255,0.06); padding: 8px 12px;">Clear</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- DOCUMENT HISTORY TABLE -->
<div class="card" style="padding: 0; overflow-x: auto;">
    <div style="padding: 16px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <h4 style="margin: 0; font-size: 1rem; font-weight: 800; color: #fff;">Generated Document Records & Registry</h4>
        <span class="muted" style="font-size: 0.8rem;">Showing <?=count($historyRows)?> recent records</span>
    </div>

    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
        <thead>
            <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted);">
                <th style="padding: 12px;">Document #</th>
                <th style="padding: 12px;">Document Type</th>
                <th style="padding: 12px;">Customer Details</th>
                <th style="padding: 12px;">Loan App #</th>
                <th style="padding: 12px;">Generated At</th>
                <th style="padding: 12px;">Generated By</th>
                <th style="padding: 12px; text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($historyRows)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 30px;" class="muted">
                        No documents recorded yet. Use the Quick Generate form above or click "Documents" on any loan application to generate your first document.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($historyRows as $row): 
                    $badgeBg = 'rgba(59,130,246,0.15); color: #60a5fa;';
                    if (strpos($row['type'], 'noc') !== false || strpos($row['type'], 'closure') !== false) {
                        $badgeBg = 'rgba(16,185,129,0.15); color: #34d399;';
                    } elseif (strpos($row['type'], 'receipt') !== false) {
                        $badgeBg = 'rgba(245,158,11,0.15); color: #fbbf24;';
                    }
                ?>
                    <tr style="border-bottom: 1px solid var(--border-color);">
                        <td style="padding: 12px;">
                            <strong style="font-family: monospace; color: #93c5fd;"><?=e($row['document_no'] ?: 'DOC-#' . $row['id'])?></strong>
                        </td>
                        <td style="padding: 12px;">
                            <span class="doc-badge" style="background: <?=$badgeBg?>">
                                <?=e($docTypesList[$row['type']] ?? ucwords(str_replace('_', ' ', $row['type'])))?>
                            </span>
                        </td>
                        <td style="padding: 12px;">
                            <strong><?=e($row['customer_name'] ?: 'Customer #' . $row['customer_id'])?></strong><br>
                            <span style="font-size: 0.75rem; color: var(--text-muted);"><?=e($row['customer_mobile'])?></span>
                        </td>
                        <td style="padding: 12px;">
                            <?=e($row['application_no'] ?: ($row['finance_id'] ? 'App #' . $row['finance_id'] : '-'))?>
                        </td>
                        <td style="padding: 12px; font-size: 0.8rem; color: var(--text-muted);">
                            <?=date('d M Y, h:i A', strtotime($row['created_at']))?>
                        </td>
                        <td style="padding: 12px; font-size: 0.8rem;">
                            <?=e($row['generated_by_name'] ?: 'System')?>
                        </td>
                        <td style="padding: 12px; text-align: right;">
                            <div style="display: flex; gap: 6px; justify-content: flex-end;">
                                <a href="<?=url('/view-document.php?type=' . urlencode($row['type']) . '&id=' . ($row['finance_id'] ?: 0) . '&customer_id=' . ($row['customer_id'] ?: 0) . ($row['payment_id'] ? '&payment_id=' . $row['payment_id'] : ''))?>" target="_blank" class="btn" style="padding: 4px 8px; font-size: 0.75rem; background: var(--primary);" title="View / Print">
                                    👁️ View
                                </a>
                                <a href="<?=url('/download-document.php?type=' . urlencode($row['type']) . '&id=' . ($row['finance_id'] ?: 0) . '&doc_no=' . urlencode($row['document_no'] ?: ''))?>" class="btn" style="padding: 4px 8px; font-size: 0.75rem; background: #059669;" title="Download PDF">
                                    ⬇️ PDF
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- BULK GENERATION MODAL -->
<div class="bulk-modal" id="bulkGenModal">
    <div class="bulk-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; border-bottom: 1px solid #334155; padding-bottom: 12px;">
            <h3 style="margin: 0; font-size: 1.15rem; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="archive" style="color: #60a5fa;"></i> Bulk Loan Document Generation & ZIP Export
            </h3>
            <button type="button" onclick="closeBulkModal()" style="background: none; border: none; color: #94a3b8; font-size: 1.4rem; cursor: pointer;">&times;</button>
        </div>

        <form id="bulkForm" onsubmit="submitBulkGen(event)">
            <div class="field" style="margin-bottom: 16px;">
                <label style="color: #60a5fa; font-weight: 700; margin-bottom: 6px; display: block;">1. Select Document Type to Generate *</label>
                <select id="bulkDocType" required style="width: 100%; padding: 10px; font-size: 0.9rem; background: #1e293b; color: #fff; border: 1px solid #334155; border-radius: 6px;">
                    <?php foreach ($docTypesList as $dKey => $dLabel): ?>
                        <option value="<?=$dKey?>"><?=e($dLabel)?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field" style="margin-bottom: 16px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <label style="color: #60a5fa; font-weight: 700;">2. Select Loans to Include *</label>
                    <div style="font-size: 0.8rem;">
                        <a href="javascript:void(0)" onclick="toggleSelectAll(true)" style="color: #38bdf8; margin-right: 8px;">Select All</a>
                        <a href="javascript:void(0)" onclick="toggleSelectAll(false)" style="color: #94a3b8;">Deselect All</a>
                    </div>
                </div>

                <div style="max-height: 220px; overflow-y: auto; background: #1e293b; border: 1px solid #334155; border-radius: 6px; padding: 10px;">
                    <?php foreach ($allLoans as $l): ?>
                        <label style="display: flex; align-items: center; gap: 10px; padding: 6px 4px; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 0.85rem; cursor: pointer;">
                            <input type="checkbox" name="bulk_loans[]" value="<?=$l['id']?>" class="bulk-loan-chk" checked style="width: 16px; height: 16px;">
                            <span>
                                <strong>#<?=e($l['application_no'])?></strong> — <?=e($l['customer_name'])?> (<?=money($l['finance_amount'])?>)
                                <span style="font-size: 0.75rem; color: #94a3b8;">· <?=strtoupper($l['status'])?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="bulkStatusMsg" style="display: none; margin-bottom: 14px; padding: 12px; border-radius: 6px; font-size: 0.85rem;"></div>

            <div style="display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #334155; padding-top: 14px;">
                <button type="button" onclick="closeBulkModal()" class="btn" style="background: #334155;">Cancel</button>
                <button type="submit" id="bulkSubmitBtn" class="btn" style="background: linear-gradient(135deg, #059669, #10b981); font-weight: 700;">
                    🚀 Start Generation & Download ZIP
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function triggerQuickDownload() {
    const loanSelect = document.getElementById('quickLoanSelect');
    const typeSelect = document.getElementById('quickTypeSelect');
    if (!loanSelect.value) {
        alert('Please select a loan application first.');
        loanSelect.focus();
        return;
    }
    const url = '<?=url("/download-document.php")?>?type=' + encodeURIComponent(typeSelect.value) + '&id=' + encodeURIComponent(loanSelect.value);
    window.location.href = url;
}

function openBulkModal() {
    document.getElementById('bulkGenModal').classList.add('active');
    document.getElementById('bulkStatusMsg').style.display = 'none';
}

function closeBulkModal() {
    document.getElementById('bulkGenModal').classList.remove('active');
}

function toggleSelectAll(state) {
    document.querySelectorAll('.bulk-loan-chk').forEach(chk => chk.checked = state);
}

function submitBulkGen(e) {
    e.preventDefault();
    const docType = document.getElementById('bulkDocType').value;
    const chks = document.querySelectorAll('.bulk-loan-chk:checked');
    const ids = Array.from(chks).map(c => c.value);

    if (ids.length === 0) {
        alert('Please select at least one loan application.');
        return;
    }

    const btn = document.getElementById('bulkSubmitBtn');
    const msg = document.getElementById('bulkStatusMsg');

    btn.disabled = true;
    btn.innerHTML = '⏳ Processing ' + ids.length + ' Document(s)...';
    msg.style.display = 'block';
    msg.style.background = 'rgba(59,130,246,0.15)';
    msg.style.border = '1px solid #3b82f6';
    msg.style.color = '#93c5fd';
    msg.innerHTML = 'Generating PDFs and creating ZIP package. Please wait...';

    const formData = new FormData();
    formData.append('doc_type', docType);
    ids.forEach(id => formData.append('finance_ids[]', id));

    fetch('<?=url("/api/bulk-generate-documents.php")?>', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '🚀 Start Generation & Download ZIP';
        if (data.success) {
            msg.style.background = 'rgba(16,185,129,0.15)';
            msg.style.border = '1px solid #10b981';
            msg.style.color = '#34d399';
            msg.innerHTML = '✓ Success! Generated ' + data.count + ' PDF document(s).<br><br><a href="' + data.zip_url + '" class="btn" style="background:#059669; display:inline-block; font-weight:bold;" download>⬇️ Click Here to Download ZIP (' + data.zip_name + ')</a>';
            // Automatically trigger download
            window.location.href = data.zip_url;
        } else {
            msg.style.background = 'rgba(239,68,68,0.15)';
            msg.style.border = '1px solid #ef4444';
            msg.style.color = '#f87171';
            msg.innerHTML = '❌ ' + (data.message || 'Error occurred during bulk generation.');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '🚀 Start Generation & Download ZIP';
        msg.style.background = 'rgba(239,68,68,0.15)';
        msg.style.border = '1px solid #ef4444';
        msg.style.color = '#f87171';
        msg.innerHTML = '❌ Network/Server Error: ' + err.message;
    });
}
</script>

<?php render_end(); ?>
