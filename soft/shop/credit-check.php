<?php 
require_once __DIR__.'/../includes/layout.php';
role('shop_admin');

$p = db();
$sid = (int)(u()['shop_id'] ?: 1);

// Fetch Customers
$custStmt = $p->prepare('SELECT id, name, mobile, pan, credit_score, dob, credit_report_json FROM customers WHERE shop_id=? ORDER BY name');
$custStmt->execute([$sid]);
$customers = $custStmt->fetchAll();

// Fetch Products & Variants
$prodStmt = $p->prepare('SELECT id, name, brand, category, selling_price, stock FROM products WHERE shop_id=? AND status="active" ORDER BY name');
$prodStmt->execute([$sid]);
$products = $prodStmt->fetchAll();

$varsStmt = $p->prepare('SELECT id, product_id, variant_name, price, stock FROM product_variants WHERE status="active" ORDER BY price ASC');
$varsStmt->execute();
$allVariants = $varsStmt->fetchAll();

$variantsByProduct = [];
foreach ($allVariants as $v) {
    $variantsByProduct[$v['product_id']][] = $v;
}

start('Credit Check & Product EMI Calculator');
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
    <div>
        <h2 style="font-size: 1.3rem; font-weight: 800; color: #fff;">Credit Bureau Assessment & EMI Financing</h2>
        <p class="muted" style="margin-top: 4px;">Step-by-step customer CIBIL check, PDF report export, and auto EMI calculation</p>
    </div>
    <div style="display: flex; gap: 10px;">
        <input type="file" id="jsonFileInput" accept=".json" style="display:none" onchange="convertJsonFileToPdf(event)">
        <button type="button" class="btn" style="background: var(--primary); color: #fff;" onclick="document.getElementById('jsonFileInput').click()"><i data-lucide="file-text"></i> 📄 Convert JSON File to PDF</button>
        <a href="customer-create.php" class="btn"><i data-lucide="user-plus"></i> + Add New Customer</a>
    </div>
</div>

<!-- STEP 1 CONTAINER: MANDATORY CUSTOMER SELECTION & REPORT TYPE -->
<div id="step1Container">
    <div class="card">
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <span style="background: var(--primary); width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.85rem;">1</span>
            Mandatory Customer Selection & Inquiry Type
        </h3>

        <form id="cibilForm">
            <div class="form-grid">
                <div class="field full" style="position: relative;">
                    <label style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Select Registered Customer (Search by Name, PAN, or Mobile) *</span>
                        <small style="color: var(--primary); cursor: pointer; font-weight: 600;" onclick="clearCustomerSelection()">✕ Clear Selection</small>
                    </label>

                    <div style="position: relative;">
                        <input type="hidden" name="customer_id" id="customerIdSelect" required>
                        <input type="text" id="customerSearchInput" placeholder="🔍 Type Customer Name, PAN, or Mobile to search..." autocomplete="off" style="width: 100%; padding-right: 40px; font-weight: 600;" onfocus="showCustomerDropdown()" oninput="filterCustomers()">
                        <span id="custSelectCheck" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); color: var(--success); display: none; font-weight: bold; font-size: 1.2rem;">✓</span>
                    </div>

                    <!-- Floating Search Dropdown List -->
                    <div id="customerDropdownList" style="display: none; position: absolute; top: 100%; left: 0; right: 0; z-index: 999; background: #1e293b; border: 1px solid var(--primary); border-radius: 12px; max-height: 280px; overflow-y: auto; box-shadow: 0 10px 25px rgba(0,0,0,0.5); margin-top: 4px;">
                        <?php foreach($customers as $c): ?>
                            <div class="customer-select-item" 
                                 data-id="<?=$c['id']?>" 
                                 data-name="<?=e($c['name'])?>" 
                                 data-pan="<?=e($c['pan'])?>" 
                                 data-mobile="<?=e($c['mobile'])?>" 
                                 data-score="<?=e($c['credit_score'])?>"
                                 data-report='<?=e($c['credit_report_json'] ?? '')?>'
                                 onclick="selectCustomerItem(this)"
                                 style="padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,0.05); cursor: pointer; display: flex; justify-content: space-between; align-items: center; transition: background 0.2s;"
                                 onmouseover="this.style.background='rgba(59,130,246,0.2)'"
                                 onmouseout="this.style.background='transparent'">
                                <div>
                                    <div style="font-weight: 700; color: #fff; font-size: 0.95rem;">
                                        👤 <?=e($c['name'])?>
                                    </div>
                                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 4px; display: flex; gap: 12px; flex-wrap: wrap;">
                                        <span><strong style="color:#94a3b8;">PAN:</strong> <?=e($c['pan'])?></span>
                                        <span><strong style="color:#94a3b8;">Mobile:</strong> <?=e($c['mobile'])?></span>
                                    </div>
                                </div>
                                <div>
                                    <?php if (!empty($c['credit_score'])): ?>
                                        <span class="badge badge-success" style="font-size: 0.75rem;">Score: <?=e($c['credit_score'])?></span>
                                    <?php else: ?>
                                        <span class="badge" style="background: rgba(255,255,255,0.1); color: #94a3b8; font-size: 0.75rem;">New Check</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div id="noCustFound" style="display: none; padding: 16px; text-align: center; color: var(--text-muted); font-size: 0.85rem;">
                            No matching customer found. <a href="customer-create.php" style="color: var(--primary); font-weight: 700; text-decoration: underline;">+ Add New Customer</a>
                        </div>
                    </div>
                </div>

                <div class="field">
                    <label>Select Bureau / Report Type</label>
                    <select name="report_type" id="reportTypeSelect">
                        <option value="equifax_json">Equifax CIBIL Detailed JSON Report</option>
                        <option value="experian_pdf">Experian Bureau Official PDF Report API</option>
                    </select>
                </div>
                
                <div class="field">
                    <label>Mobile Number</label>
                    <input type="text" id="dispMobile" placeholder="Select customer..." readonly>
                </div>

                <div class="field">
                    <label>PAN Card Number</label>
                    <input type="text" id="dispPan" placeholder="Select customer..." readonly>
                </div>

                <div class="field full">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; text-transform:none; font-weight:normal; color:var(--text-muted);">
                        <input type="checkbox" id="consentCheck" required> Customer explicit consent obtained for credit bureau inquiry
                    </label>
                </div>

                <div class="field full" style="display: flex; gap: 12px; flex-wrap: wrap;">
                    <button type="submit" class="btn" id="btnFetchReport" style="flex: 1; min-width: 240px;">
                        <i data-lucide="shield-check"></i> ⚡ Fetch Credit Bureau Score & Report
                    </button>
                    <button type="button" class="btn" id="btnNextToStep2" style="display: none; background: linear-gradient(135deg, var(--accent), #059669); color: #fff; font-weight: 700;" onclick="switchStep(2)">
                        Next Step: View Bureau Report & EMI ➔
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- STEP 2 CONTAINER: CREDIT SCORE, REPORT & EMI CALCULATOR -->
<div id="step2Container" style="display: none;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
        <button type="button" class="btn" style="background: rgba(30,41,59,0.9); border: 1px solid var(--border-color); color: #fff;" onclick="switchStep(1)">
            ← Back to Customer Selection (Step 1)
        </button>
        <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600;">Step 2 of 2: Credit Score Report & EMI Application</span>
    </div>

    <!-- SAVED REPORT NOTICE -->
    <div id="savedReportNotice" style="display: none; background: rgba(59, 130, 246, 0.15); border: 1px solid var(--primary); border-radius: 12px; padding: 14px 18px; margin-bottom: 20px; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
        <div style="display: flex; align-items: center; gap: 12px;">
            <span style="font-size: 1.4rem;">📄</span>
            <div>
                <strong style="color: #60a5fa; font-size: 0.95rem;">Previously Stored Bureau Credit Report Auto-Loaded</strong>
                <p style="color: var(--text-muted); font-size: 0.8rem; margin-top: 2px;">Showing existing saved CIBIL report from database. Click "Back to Customer Selection" to run a fresh bureau inquiry anytime.</p>
            </div>
        </div>
        <span class="badge badge-success" style="font-size: 0.8rem; padding: 6px 12px;">Stored Report Active</span>
    </div>

    <!-- CREDIT REPORT DISPLAY CARD -->
    <div class="card" id="reportResultCard" style="display: block;">
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 16px; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
            <div>
                <span class="badge badge-success" id="rptProviderBadge">Equifax Bureau Verification</span>
                <h3 style="font-size: 1.4rem; font-weight: 800; color: #fff; margin-top: 6px;" id="rptCustName">Customer Credit Report</h3>
                <p class="muted" id="rptOrderMeta">Transaction Ref: -</p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;" id="reportActionBtnGroup">
                <a id="btnExperianDirectPdf" class="btn" style="display:none; background: var(--secondary);" target="_blank" href="#">📥 Open / Print Official PDF Report</a>
                <button class="btn" type="button" onclick="printOfficialPdf()"><i data-lucide="printer"></i> 🖨️ Print Report</button>
                <button class="btn" type="button" style="background: rgba(30,41,59,0.9); border: 1px solid var(--border-color);" onclick="toggleJsonView()"><i data-lucide="code"></i> { } View Overall JSON</button>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
            <div style="background: rgba(15, 23, 42, 0.8); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; text-align: center;">
                <div style="font-size: 0.75rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Credit Score</div>
                <div class="score" id="rptScoreVal">---</div>
                <span class="badge badge-good" id="rptScoreBadge">Good Rating</span>
            </div>

            <div style="background: rgba(15, 23, 42, 0.8); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; display: flex; flex-direction: column; justify-content: center;">
                <span class="muted">Reported Accounts</span>
                <div style="font-size: 1.6rem; font-weight: 800; color: #fff; margin-top: 4px;" id="rptTotalAccounts">0 Active</div>
            </div>

            <div style="background: rgba(15, 23, 42, 0.8); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; display: flex; flex-direction: column; justify-content: center;">
                <span class="muted">Total Outstanding</span>
                <div style="font-size: 1.6rem; font-weight: 800; color: var(--primary);" id="rptTotalBalance">₹0</div>
            </div>

            <div style="background: rgba(15, 23, 42, 0.8); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; display: flex; flex-direction: column; justify-content: center;">
                <span class="muted">Past Due Amount</span>
                <div style="font-size: 1.6rem; font-weight: 800; color: var(--danger);" id="rptPastDue">₹0</div>
            </div>
        </div>

        <!-- TRADE LINES TABLE -->
        <div style="margin-top: 24px;">
            <h4 style="font-size: 0.95rem; font-weight: 700; color: #fff; margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px;">Credit Accounts & Trade Lines Summary</h4>
            <div style="overflow-x: auto; background: rgba(15,23,42,0.6); border-radius: 8px; border: 1px solid var(--border-color);">
                <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.85rem; text-align: left;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border-color); color: var(--text-muted);">
                            <th style="padding: 12px;">#</th>
                            <th style="padding: 12px;">Institution</th>
                            <th style="padding: 12px;">Account Type</th>
                            <th style="padding: 12px;">Sanctioned</th>
                            <th style="padding: 12px;">Current Balance</th>
                            <th style="padding: 12px;">Past Due</th>
                            <th style="padding: 12px;">Opened Date</th>
                            <th style="padding: 12px;">Status</th>
                        </tr>
                    </thead>
                    <tbody id="accountsTableBody">
                        <tr><td colspan="8" style="text-align:center; padding: 15px; color: var(--text-muted);">No accounts loaded</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <pre class="json-box" id="rawJsonBox" style="display:none; background:#0f172a; color:#38bdf8; padding:16px; border-radius:8px; max-height:400px; overflow:auto; font-family:monospace; font-size:0.8rem; margin-top:20px; white-space:pre-wrap; word-break:break-all; border:1px solid var(--border-color);"></pre>
    </div>

    <!-- PRODUCT EMI CALCULATOR CARD -->
    <div class="card" id="emiCalcCard" style="display: block; margin-top: 24px;">
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <span style="background: var(--primary); width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.85rem;">2</span>
            Select Product & Auto Calculate EMI
        </h3>

        <div class="form-grid">
            <div class="field full">
                <label>Select Mobile / Product from Inventory</label>
                <select id="productSelect" onchange="onProductSelect()">
                    <option value="">-- Choose Product --</option>
                    <?php foreach($products as $p): 
                        $pVars = $variantsByProduct[$p['id']] ?? [];
                    ?>
                        <option value="<?=$p['id']?>" data-price="<?=$p['selling_price']?>" data-variants='<?=htmlspecialchars(json_encode($pVars))?>'>
                            <?=e($p['name'])?> - <?=money($p['selling_price'])?> (Stock: <?=$p['stock']?>) <?=!empty($pVars)?'['.count($pVars).' Variants]':''?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field full" id="variantSelectGroup" style="display: none; background: rgba(30, 41, 59, 0.6); padding: 14px; border-radius: 12px; border: 1px solid rgba(59, 130, 246, 0.4); margin-bottom: 10px;">
                <label style="color: #60a5fa; font-weight: 800; display: flex; align-items: center; gap: 6px; margin-bottom: 8px;">
                    <span>🏷️ Select Product Variant (RAM / Storage & Price)</span>
                </label>
                <select id="variantSelect" onchange="onVariantSelect()" style="background: #0f172a; color: #ffffff; border: 1.5px solid var(--primary); font-weight: 700; border-radius: 8px; height: 42px; width: 100%; padding: 0 12px;">
                    <!-- Populated dynamically -->
                </select>
            </div>

            <div class="field">
                <label>Product Selling Price (₹)</label>
                <input type="number" id="calcPrice" value="0" oninput="recalculateEMI()">
            </div>

            <div class="field">
                <label>Down Payment Amount (₹)</label>
                <input type="number" id="calcDown" value="0" oninput="recalculateEMI()">
            </div>

            <div class="field">
                <label style="color: #60a5fa; font-weight: 700;">Interest Rate (% p.a.) ✏️</label>
                <input type="number" step="0.1" min="0" max="100" id="calcRate" value="12.0" oninput="recalculateEMI()" style="color: #10b981; font-weight: 800; border: 1px solid var(--primary);">
            </div>

            <div class="field">
                <label>Financed Principal Amount</label>
                <input type="text" id="calcPrincipal" value="₹0" readonly style="color: var(--primary); font-weight: 700;">
            </div>
        </div>

        <div style="margin-top: 20px;">
            <label>Select Preferred EMI Tenure:</label>
            <div class="emi-grid" id="emiCardsGrid">
                <!-- EMI Tenure Cards populated via JS -->
            </div>
        </div>

        <div style="margin-top: 24px;">
            <button class="btn" style="width: 100%; padding: 14px; font-size: 1rem;" onclick="submitFinanceApplication()">
                <i data-lucide="check-circle"></i> 🚀 Save & Issue Store Finance Application
            </button>
        </div>
    </div>

    <div style="margin-top: 20px;">
        <button type="button" class="btn" style="background: rgba(30,41,59,0.9); border: 1px solid var(--border-color); color: #fff;" onclick="switchStep(1)">
            ← Back to Customer Selection (Step 1)
        </button>
    </div>
</div>

<script>
    let selectedCustomerId = null;
    let selectedScore = 746;
    let selectedTenure = 6;
    let currentStep = 1;
    let reportAvailable = false;

    function switchStep(stepNum) {
        if (stepNum === 2 && !selectedCustomerId && !reportAvailable) {
            alert('Please select a customer or fetch a credit bureau report first.');
            return;
        }
        
        currentStep = stepNum;
        const step1 = document.getElementById('step1Container');
        const step2 = document.getElementById('step2Container');

        if (stepNum === 1) {
            step1.style.display = 'block';
            step2.style.display = 'none';
        } else {
            step1.style.display = 'none';
            step2.style.display = 'block';
        }

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function showCustomerDropdown() {
        document.getElementById('customerDropdownList').style.display = 'block';
        filterCustomers();
    }

    function filterCustomers() {
        const q = document.getElementById('customerSearchInput').value.toLowerCase().trim();
        const items = document.querySelectorAll('.customer-select-item');
        let count = 0;
        items.forEach(item => {
            const text = (item.dataset.name + ' ' + item.dataset.pan + ' ' + item.dataset.mobile).toLowerCase();
            if (text.includes(q)) {
                item.style.display = 'flex';
                count++;
            } else {
                item.style.display = 'none';
            }
        });
        document.getElementById('noCustFound').style.display = count === 0 ? 'block' : 'none';
    }

    function selectCustomerItem(el) {
        selectedCustomerId = el.dataset.id;
        document.getElementById('customerIdSelect').value = el.dataset.id;
        document.getElementById('customerSearchInput').value = `${el.dataset.name} (PAN: ${el.dataset.pan} | Mobile: ${el.dataset.mobile})`;
        document.getElementById('dispPan').value = el.dataset.pan || '';
        document.getElementById('dispMobile').value = el.dataset.mobile || '';
        selectedScore = parseInt(el.dataset.score) || 746;
        document.getElementById('custSelectCheck').style.display = 'block';
        document.getElementById('customerDropdownList').style.display = 'none';

        const savedReportRaw = el.dataset.report;
        const btn = document.getElementById('btnFetchReport');
        const nextBtn = document.getElementById('btnNextToStep2');
        const notice = document.getElementById('savedReportNotice');
        const tab2 = document.getElementById('stepTab2');

        if (savedReportRaw && savedReportRaw.trim() !== '' && savedReportRaw !== 'null') {
            try {
                const savedObj = JSON.parse(savedReportRaw);
                renderCreditReport(savedObj);
                reportAvailable = true;
                recalculateEMI();

                if (notice) notice.style.display = 'flex';
                if (btn) btn.innerHTML = '<i data-lucide="refresh-cw"></i> 🔄 Re-check / Refresh Bureau Credit Score (New API Inquiry)';
                if (nextBtn) nextBtn.style.display = 'inline-flex';
                if (tab2) tab2.style.opacity = '1';
            } catch (err) {
                reportAvailable = false;
                if (notice) notice.style.display = 'none';
                if (btn) btn.innerHTML = '<i data-lucide="shield-check"></i> ⚡ Fetch Credit Bureau Score & Report';
                if (nextBtn) nextBtn.style.display = 'none';
            }
        } else {
            reportAvailable = false;
            if (notice) notice.style.display = 'none';
            if (btn) btn.innerHTML = '<i data-lucide="shield-check"></i> ⚡ Fetch Credit Bureau Score & Report';
            if (nextBtn) nextBtn.style.display = 'none';
        }
    }

    function clearCustomerSelection() {
        selectedCustomerId = null;
        reportAvailable = false;
        document.getElementById('customerIdSelect').value = '';
        document.getElementById('customerSearchInput').value = '';
        document.getElementById('dispPan').value = '';
        document.getElementById('dispMobile').value = '';
        document.getElementById('custSelectCheck').style.display = 'none';
        const notice = document.getElementById('savedReportNotice');
        if (notice) notice.style.display = 'none';
        const btn = document.getElementById('btnFetchReport');
        if (btn) btn.innerHTML = '<i data-lucide="shield-check"></i> ⚡ Fetch Credit Bureau Score & Report';
        const nextBtn = document.getElementById('btnNextToStep2');
        if (nextBtn) nextBtn.style.display = 'none';
        switchStep(1);
    }

    document.addEventListener('click', function(e) {
        const container = document.getElementById('customerSearchInput');
        const dropdown = document.getElementById('customerDropdownList');
        if (container && dropdown && !container.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });

    document.getElementById('cibilForm').addEventListener('submit', async function(e) {
        e.preventDefault();
        if (!selectedCustomerId) {
            alert('Mandatory: Please select a customer from the dropdown.');
            return;
        }

        const btn = document.getElementById('btnFetchReport');
        btn.disabled = true;
        btn.innerHTML = 'Fetching Credit Bureau Report...';

        try {
            const formData = new FormData();
            formData.append('customer_id', selectedCustomerId);
            formData.append('report_type', document.getElementById('reportTypeSelect').value);

            const res = await fetch('<?=url('/api/credit-check.php')?>', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();
            if (data.success) {
                renderCreditReport(data);
                reportAvailable = true;
                recalculateEMI();

                const notice = document.getElementById('savedReportNotice');
                if (notice) notice.style.display = 'none';

                const selectedItem = document.querySelector(`.customer-select-item[data-id="${selectedCustomerId}"]`);
                if (selectedItem) {
                    selectedItem.dataset.report = JSON.stringify(data.overall_json || data);
                    selectedItem.dataset.score = data.score || selectedScore;
                }

                btn.innerHTML = '<i data-lucide="refresh-cw"></i> 🔄 Re-check / Refresh Bureau Credit Score (New API Inquiry)';
                const nextBtn = document.getElementById('btnNextToStep2');
                if (nextBtn) nextBtn.style.display = 'inline-flex';

                // Auto advance to step 2
                switchStep(2);
            } else {
                reportAvailable = false;
                alert('Credit Check Error: ' + data.message);
            }
        } catch (err) {
            alert('CIBIL fetch error: ' + err.message);
        } finally {
            btn.disabled = false;
        }
    });

    let currentOverallJson = null;
    let currentPdfUrl = null;

    function printOfficialPdf() {
        if (currentPdfUrl) {
            window.open(currentPdfUrl, '_blank');
        } else {
            window.print();
        }
    }

    function toggleJsonView() {
        const box = document.getElementById('rawJsonBox');
        if (box) {
            box.style.display = box.style.display === 'block' ? 'none' : 'block';
        }
    }

    function downloadOverallJson() {
        if (!currentOverallJson) {
            alert('No report JSON data available to download.');
            return;
        }
        const dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(currentOverallJson, null, 2));
        const downloadAnchor = document.createElement('a');
        downloadAnchor.setAttribute("href", dataStr);
        downloadAnchor.setAttribute("download", `credit_report_${currentOverallJson.orderid || 'overall'}.json`);
        document.body.appendChild(downloadAnchor);
        downloadAnchor.click();
        downloadAnchor.remove();
    }

    function downloadReportPdf() {
        if (!currentOverallJson) {
            alert('No report JSON available to convert to PDF.');
            return;
        }
        const element = document.getElementById('reportResultCard');
        const orderId = currentOverallJson.orderid || (currentOverallJson.data ? currentOverallJson.data.orderid : 'REPORT');
        const opt = {
            margin:       8,
            filename:     `Credit_Report_${orderId}.pdf`,
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };
        if (window.html2pdf) {
            html2pdf().set(opt).from(element).save();
        } else {
            window.print();
        }
    }

    function convertJsonFileToPdf(event) {
        const file = event.target.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = function(e) {
            try {
                const jsonObj = JSON.parse(e.target.result);
                renderCreditReport(jsonObj);
                reportAvailable = true;
                switchStep(2);
                setTimeout(() => {
                    downloadReportPdf();
                }, 600);
            } catch (err) {
                alert('Invalid JSON file format: ' + err.message);
            }
        };
        reader.readAsText(file);
    }

    function renderCreditReport(data) {
        currentOverallJson = data.overall_json || data.report || data.data || data;
        selectedScore = data.score || data.credit_score || (data.data ? data.data.credit_score : 746) || 746;

        currentPdfUrl = data.pdf_url || 
                        (data.data ? (data.data.report_url || data.data.pdf_url) : null) || 
                        (data.report_url || null) || 
                        (data.overall_json ? (data.overall_json.pdf_url || (data.overall_json.data ? data.overall_json.data.report_url : null)) : null);

        document.getElementById('rptCustName').textContent = data.customer ? data.customer.name : (data.name || (data.data ? data.data.name : 'Customer Credit Report'));
        document.getElementById('rptOrderMeta').textContent = 'Transaction Ref: ' + (data.orderid || (data.data ? data.data.orderid : 'TXN98412'));
        document.getElementById('rptScoreVal').textContent = selectedScore;
        document.getElementById('rptProviderBadge').textContent = data.provider || 'Equifax Bureau Verification';

        const pdfBtn = document.getElementById('btnExperianDirectPdf');
        if (pdfBtn) {
            if (currentPdfUrl) {
                pdfBtn.href = currentPdfUrl;
                pdfBtn.style.display = 'inline-flex';
                pdfBtn.target = '_blank';
                pdfBtn.innerHTML = '📥 Open / Print Official PDF Report';
            } else {
                pdfBtn.style.display = 'none';
            }
        }

        const badge = document.getElementById('rptScoreBadge');
        if (selectedScore >= 750) {
            badge.textContent = 'EXCELLENT RISK';
            badge.className = 'badge badge-success';
        } else if (selectedScore >= 700) {
            badge.textContent = 'STANDARD RISK';
            badge.className = 'badge badge-info';
        } else {
            badge.textContent = 'HIGHER RISK';
            badge.className = 'badge badge-warning';
        }

        const jsonBox = document.getElementById('rawJsonBox');
        if (jsonBox) {
            jsonBox.textContent = JSON.stringify(currentOverallJson, null, 2);
        }

        const dataObj = currentOverallJson.data || currentOverallJson;
        const ccr = dataObj.credit_report || {};
        const cirDataLst = ccr.CCRResponse && ccr.CCRResponse.CIRReportDataLst ? ccr.CCRResponse.CIRReportDataLst[0] : {};
        const cirData = cirDataLst.CIRReportData || {};
        const accounts = cirData.RetailAccountDetails || [];

        let totalBal = 0;
        let pastDue = 0;
        accounts.forEach(acc => {
            totalBal += parseFloat(acc.Balance || 0);
            pastDue += parseFloat(acc.PastDueAmount || 0);
        });

        document.getElementById('rptTotalAccounts').textContent = accounts.length > 0 ? (accounts.length + ' Active') : '0 Active';
        document.getElementById('rptTotalBalance').textContent = '₹' + Math.round(totalBal).toLocaleString('en-IN');
        document.getElementById('rptPastDue').textContent = '₹' + Math.round(pastDue).toLocaleString('en-IN');

        const tbody = document.getElementById('accountsTableBody');
        if (tbody) {
            tbody.innerHTML = '';
            if (accounts.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align:center; padding:15px; color:var(--text-muted);">No trade lines or account details found.</td></tr>';
            } else {
                accounts.forEach((acc, idx) => {
                    const tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid var(--border-color)';
                    tr.innerHTML = `
                        <td style="padding:10px;">${idx + 1}</td>
                        <td style="padding:10px;"><strong>${acc.Institution || '-'}</strong><br><span style="font-size:0.75rem; color:var(--text-muted);">Acc: ${acc.AccountNumber || '-'}</span></td>
                        <td style="padding:10px;">${acc.AccountType || '-'}</td>
                        <td style="padding:10px;">₹${parseInt(acc.SanctionAmount || 0).toLocaleString('en-IN')}</td>
                        <td style="padding:10px;">₹${parseInt(acc.Balance || 0).toLocaleString('en-IN')}</td>
                        <td style="padding:10px; color:${parseInt(acc.PastDueAmount || 0) > 0 ? 'var(--danger)' : 'inherit'}; font-weight:${parseInt(acc.PastDueAmount || 0) > 0 ? '700' : 'normal'}">₹${parseInt(acc.PastDueAmount || 0).toLocaleString('en-IN')}</td>
                        <td style="padding:10px;">${acc.DateOpened || '-'}</td>
                        <td style="padding:10px;"><span class="badge ${acc.Open === 'Yes' ? 'badge-success' : 'badge-info'}">${acc.AccountStatus || (acc.Open === 'Yes' ? 'Open' : 'Closed')}</span></td>
                    `;
                    tbody.appendChild(tr);
                });
            }
        }
    }

    function onProductSelect() {
        const select = document.getElementById('productSelect');
        const opt = select.options[select.selectedIndex];
        const vGroup = document.getElementById('variantSelectGroup');
        const vSelect = document.getElementById('variantSelect');

        if (opt && opt.value) {
            const price = parseFloat(opt.dataset.price) || 0;
            const rawVars = opt.dataset.variants;
            let variants = [];
            try { variants = JSON.parse(rawVars); } catch(e){}

            if (variants && variants.length > 0) {
                vGroup.style.display = 'block';
                vSelect.innerHTML = '<option value="" style="background:#0f172a; color:#ffffff;">-- Select Variant Option --</option>';
                variants.forEach(v => {
                    const optEl = document.createElement('option');
                    optEl.value = v.id;
                    optEl.dataset.price = v.price;
                    optEl.dataset.name = v.variant_name;
                    optEl.style.background = '#0f172a';
                    optEl.style.color = '#ffffff';
                    optEl.textContent = `${v.variant_name} - ₹${parseFloat(v.price).toLocaleString('en-IN')} (Stock: ${v.stock})`;
                    vSelect.appendChild(optEl);
                });
                vSelect.selectedIndex = 1;
                onVariantSelect();
            } else {
                vGroup.style.display = 'none';
                document.getElementById('calcPrice').value = price;
                document.getElementById('calcDown').value = Math.round(price * 0.20);
                recalculateEMI();
            }
        } else {
            vGroup.style.display = 'none';
        }
    }

    function onVariantSelect() {
        const vSelect = document.getElementById('variantSelect');
        const opt = vSelect.options[vSelect.selectedIndex];
        if (opt && opt.dataset.price) {
            const price = parseFloat(opt.dataset.price) || 0;
            document.getElementById('calcPrice').value = price;
            document.getElementById('calcDown').value = Math.round(price * 0.20);
            recalculateEMI();
        }
    }

    function recalculateEMI() {
        const price = parseFloat(document.getElementById('calcPrice').value) || 0;
        const down = parseFloat(document.getElementById('calcDown').value) || 0;
        const principal = Math.max(0, price - down);

        document.getElementById('calcPrincipal').value = '₹' + principal.toLocaleString('en-IN');

        // Read user editable interest rate
        const rate = parseFloat(document.getElementById('calcRate').value) || 0;

        const tenures = [3, 6, 9, 12, 18, 24];
        const grid = document.getElementById('emiCardsGrid');
        grid.innerHTML = '';

        tenures.forEach(months => {
            const monthlyRate = (rate / 12) / 100;
            let emi = 0;
            if (principal > 0 && monthlyRate > 0) {
                emi = Math.round((principal * monthlyRate * Math.pow(1 + monthlyRate, months)) / (Math.pow(1 + monthlyRate, months) - 1));
            } else if (principal > 0) {
                emi = Math.round(principal / months);
            }

            const totalPayable = emi * months;
            const totalInterest = Math.max(0, totalPayable - principal);

            const card = document.createElement('div');
            card.className = `emi ${selectedTenure === months ? 'selected' : ''}`;
            card.style.cursor = 'pointer';
            if (selectedTenure === months) {
                card.style.borderColor = 'var(--primary)';
                card.style.background = 'rgba(59, 130, 246, 0.1)';
            }

            card.onclick = () => {
                selectedTenure = months;
                recalculateEMI();
            };

            card.innerHTML = `
                <div style="font-weight: 800; color: #fff;">${months} Months EMI</div>
                <strong>₹${emi.toLocaleString('en-IN')}<span style="font-size:0.75rem; color:var(--text-muted); font-weight:normal;">/mo</span></strong>
                <div style="font-size: 0.75rem; color: var(--text-muted);">Interest: ₹${totalInterest.toLocaleString('en-IN')} | Total: ₹${totalPayable.toLocaleString('en-IN')}</div>
            `;
            grid.appendChild(card);
        });
    }

    async function submitFinanceApplication() {
        if (!selectedCustomerId) {
            alert('Mandatory: Please select a customer.');
            return;
        }

        const prodSelect = document.getElementById('productSelect');
        const price = parseFloat(document.getElementById('calcPrice').value) || 0;
        const down = parseFloat(document.getElementById('calcDown').value) || 0;

        if (price <= 0) {
            alert('Please select a valid product and price.');
            return;
        }

        const rate = parseFloat(document.getElementById('calcRate').value) || 0;

        const formData = new FormData();
        formData.append('customer_id', selectedCustomerId);
        formData.append('product_id', prodSelect.value || '');
        formData.append('product_price', price);
        formData.append('down_payment', down);
        formData.append('tenure', selectedTenure);
        formData.append('interest_rate', rate);

        try {
            const res = await fetch('<?=url('/api/create-application.php')?>', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();
            if (data.success) {
                alert('Success! Finance Application Created: ' + data.app_no);
                window.location.href = 'applications.php';
            } else {
                alert('Error: ' + data.message);
            }
        } catch (err) {
            alert('Submission error: ' + err.message);
        }
    }
</script>

<?php render_end(); ?>
