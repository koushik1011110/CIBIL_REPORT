<?php 
require_once __DIR__.'/../includes/layout.php';
role('staff');

$p = db();
$sid = (int)(u()['shop_id'] ?: 1);

// Fetch Customers
$custStmt = $p->prepare('SELECT id, name, mobile, pan, credit_score, dob, credit_report_json FROM customers WHERE shop_id=? ORDER BY name');
$custStmt->execute([$sid]);
$customers = $custStmt->fetchAll();

// Fetch Products & Variants from Master Catalog
$prodStmt = $p->query('SELECT id, name, brand, category, selling_price FROM products WHERE status="active" ORDER BY name ASC');
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
                                 data-dob="<?=e($c['dob'] ?? '')?>"
                                 data-address="<?=e($c['address'] ?? '')?>"
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
                                        <span class="badge <?=($c['credit_score'] < 600 ? 'badge-danger' : 'badge-success')?>" style="font-size: 0.75rem;">
                                            Score: <?=e($c['credit_score'])?> <?=($c['credit_score'] < 600 ? '(Ineligible)' : '')?>
                                        </span>
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
                        <option value="transunion_pdf" selected>Credit Report Transunion PDF (₹80.00)</option>
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

                <div class="field">
                    <label>Customer Gender *</label>
                    <select id="custGender" style="background: var(--input-bg); color: #fff; width: 100%; padding: 10px; border-radius: 6px; border: 1px solid var(--border-color);">
                        <option value="male" selected>male</option>
                        <option value="female">female</option>
                    </select>
                </div>


                <div class="field full">
                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; text-transform:none; font-weight:normal; color:var(--text-muted);">
                        <input type="checkbox" id="consentCheck" checked required> Customer explicit consent obtained for credit bureau inquiry (consent=Y)
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

    <!-- LOW CREDIT SCORE INELIGIBLE WARNING CARD -->
    <div class="card" id="lowScoreIneligibleAlert" style="display: none; margin-top: 24px; background: rgba(239, 68, 68, 0.08); border: 2px solid var(--danger); text-align: center; padding: 32px 20px; border-radius: 16px;">
        <div style="font-size: 3rem; margin-bottom: 12px;">🚫</div>
        <h3 style="color: var(--danger); font-size: 1.35rem; font-weight: 800; margin-bottom: 8px;">
            Loan Application Submission Blocked (Credit Score Below 600)
        </h3>
        <p style="color: #cbd5e1; font-size: 0.95rem; max-width: 650px; margin: 0 auto 16px auto; line-height: 1.6;">
            Customer credit score is <strong style="color: #f87171; font-size: 1.25rem;" id="ineligibleScoreText">---</strong>. As per financing policy, loan applications cannot be created or submitted for credit scores under <strong>600</strong>.
        </p>
        <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(239, 68, 68, 0.2); color: #fca5a5; padding: 8px 20px; border-radius: 20px; font-weight: 700; font-size: 0.85rem; border: 1px solid rgba(239, 68, 68, 0.4);">
            🔒 Ineligible for Store Financing
        </div>
    </div>

    <!-- PRODUCT EMI CALCULATOR CARD -->
    <div class="card" id="emiCalcCard" style="display: block; margin-top: 24px;">
        <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
            <span style="background: var(--primary); width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.85rem;">2</span>
            Select Product & Auto Calculate EMI
        </h3>

        <div class="form-grid">
            <div class="field full">
                <label>Select Product from Master Catalog</label>
                <select id="productSelect" onchange="onProductSelect()">
                    <option value="">-- Choose Product --</option>
                    <?php foreach($products as $p): 
                        $pVars = $variantsByProduct[$p['id']] ?? [];
                    ?>
                        <option value="<?=$p['id']?>" data-price="<?=$p['selling_price']?>" data-variants='<?=htmlspecialchars(json_encode($pVars))?>'>
                            <?=e($p['name'])?> - <?=money($p['selling_price'])?> <?=!empty($pVars)?'['.count($pVars).' Variants]':''?>
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

            <!-- 📱 Product IMEI / Serial Number Input & Barcode Scanner -->
            <div class="field full" style="background: rgba(15, 23, 42, 0.7); padding: 14px 16px; border-radius: 12px; border: 1.5px solid rgba(59, 130, 246, 0.35); margin-bottom: 8px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                    <label style="margin: 0; color: #38bdf8; font-weight: 800; display: flex; align-items: center; gap: 6px;">
                        <span>📱 Product IMEI / Serial Number (Scan & Save on Customer Behalf)</span>
                    </label>
                    <div style="display: flex; gap: 6px;">
                        <button type="button" class="btn btn-sm" onclick="openImeiScannerModal()" style="background: linear-gradient(135deg, #0284c7, #2563eb); color: #fff; padding: 6px 14px; font-size: 0.8rem; font-weight: 700; border-radius: 8px; box-shadow: 0 2px 8px rgba(37, 99, 235, 0.3); display: flex; align-items: center; gap: 6px; border: none; cursor: pointer;">
                            📷 Scan via Camera / Barcode
                        </button>
                        <button type="button" class="btn btn-sm" onclick="clearImeiField()" style="background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); padding: 6px 10px; font-size: 0.8rem; border-radius: 8px; cursor: pointer;" title="Clear">
                            ✕ Clear
                        </button>
                    </div>
                </div>
                <div style="position: relative;">
                    <input type="text" id="calcImei" name="imei_number" placeholder="Scan barcode from mobile box or enter 15-digit IMEI..." maxlength="40" style="font-family: monospace; font-size: 1.05rem; font-weight: 700; letter-spacing: 1px; color: #38bdf8; background: #0f172a; border: 1.5px solid #334155; border-radius: 8px; padding: 10px 14px; width: 100%; box-sizing: border-box;" oninput="validateImeiFormat(this.value)">
                </div>
                <div id="imeiHelperText" style="font-size: 0.73rem; color: #94a3b8; margin-top: 6px; display: flex; align-items: center; gap: 6px;">
                    <span>ℹ️ Compatible with USB/Bluetooth barcode scanner guns and box barcodes (Code 128 / QR).</span>
                </div>
            </div>

            <div class="field">
                <label>Product Selling Price (₹)</label>
                <input type="number" id="calcPrice" value="0" oninput="recalculateEMI()">
            </div>

            <div class="field">
                <label>Down Payment Amount (₹)</label>
                <input type="number" id="calcDown" value="0" oninput="recalculateEMI()">
            </div>

            <!-- 🛡️ Optional Addon Fees (Processing Fee: 399 & Device Insurance: 599) -->
            <div class="field full addon-container-box" style="background: rgba(30, 41, 59, 0.5); border: 1.5px solid rgba(59, 130, 246, 0.35); border-radius: 12px; padding: 14px 16px; margin: 4px 0 10px 0;">
                <div style="font-weight: 800; font-size: 0.9rem; margin-bottom: 10px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                    <span class="addon-title-text" style="display: flex; align-items: center; gap: 8px; color: #f8fafc;">
                        <span>📑 Loan Addons & Protection</span>
                        <span style="font-size: 0.72rem; background: rgba(59, 130, 246, 0.2); color: #60a5fa; padding: 2px 8px; border-radius: 999px; font-weight: 700;">Auto-Applied</span>
                    </span>
                    <span id="addonTotalBadge" style="font-size: 0.82rem; color: #34d399; font-weight: 800; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); padding: 3px 10px; border-radius: 20px;">
                        + ₹998 Total Addons
                    </span>
                </div>

                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px;">
                    <!-- Processing Fee Checkbox Card -->
                    <label class="addon-fee-card active" id="cardProcessingFee" style="background: rgba(15, 23, 42, 0.6); border: 1.5px solid rgba(255, 255, 255, 0.08); padding: 12px 14px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; gap: 12px;">
                        <input type="checkbox" id="checkProcessingFee" checked onchange="onFeeToggle()" style="width: 20px; height: 20px; accent-color: #2563eb; cursor: pointer;">
                        <div style="flex: 1;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <strong class="addon-title-text" style="color: #f1f5f9; font-size: 0.88rem;">Processing Fee</strong>
                                <span style="color: #38bdf8; font-weight: 800; font-size: 0.95rem;">₹399</span>
                            </div>
                            <div class="addon-desc-text" style="font-size: 0.72rem; color: #94a3b8; margin-top: 2px;">Standard loan documentation & processing</div>
                        </div>
                    </label>

                    <!-- Device Insurance Checkbox Card -->
                    <label class="addon-fee-card active" id="cardInsuranceFee" style="background: rgba(15, 23, 42, 0.6); border: 1.5px solid rgba(255, 255, 255, 0.08); padding: 12px 14px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; gap: 12px;">
                        <input type="checkbox" id="checkInsuranceFee" checked onchange="onFeeToggle()" style="width: 20px; height: 20px; accent-color: #10b981; cursor: pointer;">
                        <div style="flex: 1;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <strong class="addon-title-text" style="color: #f1f5f9; font-size: 0.88rem;">Device Insurance</strong>
                                <span style="color: #34d399; font-weight: 800; font-size: 0.95rem;">₹599</span>
                            </div>
                            <div class="addon-desc-text" style="font-size: 0.72rem; color: #94a3b8; margin-top: 2px;">Comprehensive protection against accidental damage</div>
                        </div>
                    </label>
                </div>
            </div>

            <div class="field">
                <label style="color: #60a5fa; font-weight: 700;">Interest Rate (% p.m. / Per Month) ✏️</label>
                <input type="number" step="0.1" min="0" max="100" id="calcRate" value="2.0" oninput="recalculateEMI()" style="color: #10b981; font-weight: 800; border: 1px solid var(--primary);">
                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 3px;">Monthly flat rate (e.g. 1.5% or 2.0% per month)</div>
            </div>

            <div class="field">
                <label>Financed Principal Amount</label>
                <input type="text" id="calcPrincipal" value="₹0" readonly style="color: var(--primary); font-weight: 700;">
            </div>

            <div class="field">
                <label style="color: #10b981; font-weight: 700;">Total Interest Amount (₹)</label>
                <input type="text" id="calcTotalInterest" value="₹0" readonly style="color: #10b981; font-weight: 800; background: rgba(16, 185, 129, 0.08); border: 1.5px solid rgba(16, 185, 129, 0.35);">
                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 3px;" id="calcInterestTenureLabel">For selected tenure</div>
            </div>

            <div class="field">
                <label style="color: #3b82f6; font-weight: 700;">Total Repayable Amount (₹)</label>
                <input type="text" id="calcTotalPayable" value="₹0" readonly style="color: #3b82f6; font-weight: 800; background: rgba(59, 130, 246, 0.08); border: 1.5px solid rgba(59, 130, 246, 0.35);">
                <div style="font-size: 0.72rem; color: var(--text-muted); margin-top: 3px;">Principal + Interest Total</div>
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

<!-- ========================================================================= -->
<!-- 📷 MODAL: CAMERA / BARCODE IMEI SCANNER                                    -->
<!-- ========================================================================= -->
<div id="imeiScannerModal" style="display:none; position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(6px); z-index: 10000; align-items: center; justify-content: center; padding: 20px;">
    <div style="background: #0f172a; border: 1px solid rgba(255, 255, 255, 0.15); border-radius: 20px; max-width: 480px; width: 100%; padding: 24px; box-shadow: 0 25px 60px rgba(0,0,0,0.8); position: relative;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <div style="width: 38px; height: 38px; border-radius: 10px; background: rgba(2, 132, 199, 0.2); display: flex; align-items: center; justify-content: center; font-size: 1.2rem; color: #38bdf8;">
                    📷
                </div>
                <div>
                    <h3 style="font-size: 1.1rem; font-weight: 800; margin: 0; color: #fff;">Scan Product IMEI / Barcode</h3>
                    <p style="font-size: 0.75rem; color: var(--text-muted); margin: 2px 0 0 0;">Point camera at barcode on the mobile box</p>
                </div>
            </div>
            <button type="button" onclick="closeImeiScannerModal()" style="background: rgba(255,255,255,0.08); border: none; color: #94a3b8; width: 32px; height: 32px; border-radius: 8px; font-size: 1.1rem; cursor: pointer; display: flex; align-items: center; justify-content: center;">✕</button>
        </div>

        <!-- Scanner Viewfinder Box -->
        <div style="position: relative; width: 100%; border-radius: 14px; overflow: hidden; background: #0b1120; border: 2px dashed #0284c7; min-height: 260px; display: flex; flex-direction: column; align-items: center; justify-content: center;">
            <div id="imei-reader-container" style="width: 100%; min-height: 260px;"></div>
            <div id="scannerStatusText" style="padding: 10px; font-size: 0.8rem; color: #38bdf8; text-align: center; font-weight: 600;">
                Initializing camera feed...
            </div>
        </div>

        <!-- Camera Switcher / Close Controls -->
        <div style="margin-top: 14px; display: flex; justify-content: space-between; align-items: center; gap: 8px;">
            <button type="button" class="btn btn-sm" onclick="toggleCameraFacing()" style="background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.15); color: #e2e8f0; font-size: 0.78rem; padding: 7px 12px; border-radius: 8px; cursor: pointer;">
                🔄 Flip Camera
            </button>
            <button type="button" class="btn btn-sm" onclick="closeImeiScannerModal()" style="background: rgba(239, 68, 68, 0.2); border: 1px solid rgba(239, 68, 68, 0.4); color: #fca5a5; font-size: 0.78rem; padding: 7px 16px; border-radius: 8px; cursor: pointer;">
                Cancel
            </button>
        </div>
    </div>
</div>

<script src="<?=url('/public/assets/js/html5-qrcode.min.js')?>"></script>
<script>
    let selectedCustomerId = null;
    let selectedScore = 746;
    let selectedTenure = 6;
    let currentStep = 1;
    let reportAvailable = false;

    function updateEligibilityView() {
        const emiCard = document.getElementById('emiCalcCard');
        const lowAlert = document.getElementById('lowScoreIneligibleAlert');
        const scoreText = document.getElementById('ineligibleScoreText');
        const nextBtn = document.getElementById('btnNextToStep2');

        if (selectedScore < 600) {
            if (emiCard) emiCard.style.display = 'none';
            if (lowAlert) lowAlert.style.display = 'block';
            if (scoreText) scoreText.textContent = selectedScore;
            if (nextBtn) {
                nextBtn.innerHTML = 'Next Step: View Bureau Report (Score ' + selectedScore + ' < 600: Ineligible) ➔';
                nextBtn.style.background = 'linear-gradient(135deg, #ef4444, #b91c1c)';
            }
        } else {
            if (emiCard) emiCard.style.display = 'block';
            if (lowAlert) lowAlert.style.display = 'none';
            if (nextBtn) {
                nextBtn.innerHTML = 'Next Step: View Bureau Report & EMI ➔';
                nextBtn.style.background = 'linear-gradient(135deg, var(--accent), #059669)';
            }
        }
    }

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
            updateEligibilityView();
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

        selectedScore = (el.dataset.score && parseInt(el.dataset.score) > 0) ? parseInt(el.dataset.score) : 746;
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
        updateEligibilityView();
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

        const reportType = document.getElementById('reportTypeSelect').value;

        const btn = document.getElementById('btnFetchReport');
        btn.disabled = true;
        btn.innerHTML = 'Connecting to Bureau API...';

        try {
            const formData = new FormData();
            formData.append('customer_id', selectedCustomerId);
            formData.append('report_type', 'transunion_pdf');
            formData.append('gender', document.getElementById('custGender') ? document.getElementById('custGender').value : 'male');
            formData.append('consent', 'Y');

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
        if (data.score !== undefined && data.score !== null) {
            selectedScore = parseInt(data.score);
        } else if (data.credit_score !== undefined && data.credit_score !== null) {
            selectedScore = parseInt(data.credit_score);
        } else if (data.data && data.data.credit_score !== undefined && data.data.credit_score !== null) {
            selectedScore = parseInt(data.data.credit_score);
        } else {
            selectedScore = 746;
        }

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
        } else if (selectedScore >= 600) {
            badge.textContent = 'MODERATE RISK';
            badge.className = 'badge badge-warning';
        } else if (selectedScore <= 0) {
            badge.textContent = 'NEW TO CREDIT / NO HISTORY (' + selectedScore + ')';
            badge.className = 'badge badge-warning';
        } else {
            badge.textContent = 'CRITICAL RISK (SCORE < 600 - INELIGIBLE)';
            badge.className = 'badge badge-danger';
        }

        updateEligibilityView();

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
                    optEl.textContent = `${v.variant_name} - ₹${parseFloat(v.price).toLocaleString('en-IN')}`;
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

    function onFeeToggle() {
        const procCheck = document.getElementById('checkProcessingFee');
        const insCheck = document.getElementById('checkInsuranceFee');
        const cardProc = document.getElementById('cardProcessingFee');
        const cardIns = document.getElementById('cardInsuranceFee');

        if (cardProc && procCheck) {
            cardProc.classList.toggle('active', procCheck.checked);
        }
        if (cardIns && insCheck) {
            cardIns.classList.toggle('active', insCheck.checked);
        }
        recalculateEMI();
    }

    function recalculateEMI() {
        const price = parseFloat(document.getElementById('calcPrice').value) || 0;
        const down = parseFloat(document.getElementById('calcDown').value) || 0;
        const basePrincipal = Math.max(0, price - down);

        const procCheck = document.getElementById('checkProcessingFee');
        const insCheck = document.getElementById('checkInsuranceFee');
        const procFee = (procCheck && procCheck.checked) ? 399 : 0;
        const insFee = (insCheck && insCheck.checked) ? 599 : 0;
        const addonTotal = procFee + insFee;

        const badge = document.getElementById('addonTotalBadge');
        if (badge) {
            if (addonTotal > 0) {
                badge.textContent = `+ ₹${addonTotal.toLocaleString('en-IN')} Total Addons (Proc: ₹${procFee} | Ins: ₹${insFee})`;
                badge.style.display = 'inline-block';
            } else {
                badge.textContent = '₹0 Addons Selected';
            }
        }

        // Financed principal includes addons when price is set
        const principal = basePrincipal > 0 ? (basePrincipal + addonTotal) : 0;

        const calcPrincipalEl = document.getElementById('calcPrincipal');
        if (calcPrincipalEl) {
            if (principal > 0 && addonTotal > 0) {
                calcPrincipalEl.value = `₹${principal.toLocaleString('en-IN')} (Net: ₹${basePrincipal.toLocaleString('en-IN')} + Addons: ₹${addonTotal})`;
            } else {
                calcPrincipalEl.value = '₹' + principal.toLocaleString('en-IN');
            }
        }

        // Read user editable interest rate
        const rate = parseFloat(document.getElementById('calcRate').value) || 0;

        let curSelectedInterest = 0;
        if (principal > 0 && rate > 0 && selectedTenure > 0) {
            curSelectedInterest = Math.round((principal * rate * selectedTenure) / 100);
        }
        const curSelectedTotal = principal + curSelectedInterest;

        const calcInterestEl = document.getElementById('calcTotalInterest');
        if (calcInterestEl) {
            calcInterestEl.value = '₹' + curSelectedInterest.toLocaleString('en-IN');
        }
        const calcPayableEl = document.getElementById('calcTotalPayable');
        if (calcPayableEl) {
            calcPayableEl.value = '₹' + curSelectedTotal.toLocaleString('en-IN');
        }
        const labelTenure = document.getElementById('calcInterestTenureLabel');
        if (labelTenure) {
            labelTenure.textContent = `For selected ${selectedTenure} Months tenure (@ ${rate}% p.m.)`;
        }

        const tenures = [3, 6, 9, 12, 18, 24];
        const grid = document.getElementById('emiCardsGrid');
        grid.innerHTML = '';

        tenures.forEach(months => {
            // Per month interest calculation
            let totalInterest = 0;
            if (principal > 0 && rate > 0) {
                totalInterest = Math.round((principal * rate * months) / 100);
            }
            const totalPayable = principal + totalInterest;
            const emi = months > 0 ? Math.round(totalPayable / months) : 0;

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

            // 20th Cutoff Condition: loans created after 20th skip next month, start on 4th of following month
            const now = new Date();
            const curDay = now.getDate();
            const startOffset = (curDay > 20) ? 2 : 1;
            const firstEmiDate = new Date(now.getFullYear(), now.getMonth() + startOffset, 4);
            const firstEmiMonth = firstEmiDate.toLocaleString('en-IN', { month: 'short', year: 'numeric' });

            card.innerHTML = `
                <div style="font-weight: 800; color: #fff;">${months} Months EMI</div>
                <strong>₹${emi.toLocaleString('en-IN')}<span style="font-size:0.75rem; color:var(--text-muted); font-weight:normal;">/mo</span></strong>
                <div style="font-size: 0.75rem; margin-top: 4px;">
                    <span style="color:#10b981; font-weight: 800;">Interest: ₹${totalInterest.toLocaleString('en-IN')}</span> 
                    <span style="color:var(--text-muted);">| Total: ₹${totalPayable.toLocaleString('en-IN')}</span>
                </div>
                <div style="font-size: 0.72rem; color: #60a5fa; margin-top: 4px;">📅 1st EMI: <strong>04 ${firstEmiMonth}</strong> ${curDay > 20 ? '<span style="color:#f59e0b; font-size:0.68rem;">(Post-20th cycle)</span>' : ''}</div>
            `;
            grid.appendChild(card);
        });
    }

    async function submitFinanceApplication() {
        if (!selectedCustomerId) {
            alert('Mandatory: Please select a customer.');
            return;
        }

        if (selectedScore < 600) {
            alert('Application Blocked: Customer credit score (' + selectedScore + ') is below 600. Loan application cannot be submitted.');
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
        const imeiInput = document.getElementById('calcImei');
        const imeiVal = imeiInput ? imeiInput.value.trim() : '';

        const procCheck = document.getElementById('checkProcessingFee');
        const insCheck = document.getElementById('checkInsuranceFee');
        const procFee = (procCheck && procCheck.checked) ? 399 : 0;
        const insFee = (insCheck && insCheck.checked) ? 599 : 0;

        let prodName = '';
        if (prodSelect.selectedIndex >= 0 && prodSelect.value) {
            const pText = prodSelect.options[prodSelect.selectedIndex].text.split(' - ')[0].trim();
            const vSelect = document.getElementById('variantSelect');
            const vGroup = document.getElementById('variantSelectGroup');
            if (vGroup && vGroup.style.display !== 'none' && vSelect && vSelect.selectedIndex > 0) {
                const vOpt = vSelect.options[vSelect.selectedIndex];
                const vName = vOpt.dataset.name || vOpt.text.split(' - ')[0].trim();
                prodName = pText + ' (' + vName + ')';
            } else {
                prodName = pText;
            }
        }

        const formData = new FormData();
        formData.append('customer_id', selectedCustomerId);
        formData.append('product_id', prodSelect.value || '');
        formData.append('product_name', prodName);
        formData.append('imei_number', imeiVal);
        formData.append('product_price', price);
        formData.append('down_payment', down);
        formData.append('processing_fee', procFee);
        formData.append('insurance_fee', insFee);
        formData.append('tenure', selectedTenure);
        formData.append('interest_rate', rate);

        try {
            const res = await fetch('<?=url('/api/create-application.php')?>', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();
            if (data.success) {
                alert('Success! Finance Application Created: ' + data.app_no + (data.first_emi_date ? '\n1st Installment Due: ' + data.first_emi_date : '') + (imeiVal ? '\nIMEI Saved: ' + imeiVal : ''));
                window.location.href = 'applications.php';
            } else {
                alert('Error: ' + data.message);
            }
        } catch (err) {
            alert('Submission error: ' + err.message);
        }
    }

    // =========================================================================
    // 📱 IMEI SCANNER & CAMERA CONTROLS
    // =========================================================================
    let html5QrScanner = null;
    let currentCameraFacing = 'environment';

    async function openImeiScannerModal() {
        const modal = document.getElementById('imeiScannerModal');
        if (!modal) return;
        modal.style.display = 'flex';
        document.getElementById('scannerStatusText').textContent = 'Requesting camera permissions...';

        if (typeof Html5Qrcode === 'undefined') {
            document.getElementById('scannerStatusText').textContent = 'Scanner library is loading, please wait...';
            return;
        }

        try {
            if (html5QrScanner) {
                try { await html5QrScanner.stop(); } catch(e){}
                html5QrScanner = null;
            }

            html5QrScanner = new Html5Qrcode("imei-reader-container");
            const config = {
                fps: 15,
                qrbox: { width: 280, height: 160 },
                aspectRatio: 1.333334
            };

            await html5QrScanner.start(
                { facingMode: currentCameraFacing },
                config,
                onImeiScanSuccess,
                onImeiScanFailure
            );
            document.getElementById('scannerStatusText').textContent = 'Align IMEI / Barcode inside the rectangular viewfinder';
        } catch(err) {
            console.error('Camera start error:', err);
            document.getElementById('scannerStatusText').innerHTML = '<span style="color:#f87171;">⚠️ Camera access denied or unavailable: ' + (err.message || err) + '</span><br><small style="color:#94a3b8;">You can also enter IMEI manually or scan with a USB barcode gun.</small>';
        }
    }

    async function closeImeiScannerModal() {
        const modal = document.getElementById('imeiScannerModal');
        if (modal) modal.style.display = 'none';
        if (html5QrScanner) {
            try {
                await html5QrScanner.stop();
                await html5QrScanner.clear();
            } catch(e) {}
            html5QrScanner = null;
        }
    }

    function playScanBeep() {
        try {
            const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, audioCtx.currentTime);
            gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.12);
        } catch(e) {}
    }

    function cleanImeiText(decodedText) {
        let raw = (decodedText || '').trim();
        raw = raw.replace(/^(IMEI\s*1?|IMEI\s*2?|S\/N|SN|SERIAL)\s*[:=\-]?\s*/i, '');
        raw = raw.replace(/[^a-zA-Z0-9]/g, '');
        return raw;
    }

    function onImeiScanSuccess(decodedText) {
        playScanBeep();
        const cleaned = cleanImeiText(decodedText);
        const imeiInput = document.getElementById('calcImei');
        if (imeiInput) {
            imeiInput.value = cleaned;
            validateImeiFormat(cleaned);
            imeiInput.style.borderColor = '#10b981';
            setTimeout(() => {
                imeiInput.style.borderColor = '#334155';
            }, 1500);
        }
        closeImeiScannerModal();
    }

    function onImeiScanFailure(error) {
        // Scanning in progress
    }

    async function toggleCameraFacing() {
        currentCameraFacing = (currentCameraFacing === 'environment') ? 'user' : 'environment';
        if (html5QrScanner) {
            try {
                await html5QrScanner.stop();
                html5QrScanner = null;
            } catch(e){}
        }
        openImeiScannerModal();
    }

    function clearImeiField() {
        const imeiInput = document.getElementById('calcImei');
        if (imeiInput) {
            imeiInput.value = '';
            validateImeiFormat('');
        }
    }

    function validateImeiFormat(val) {
        const helper = document.getElementById('imeiHelperText');
        if (!helper) return;
        const v = (val || '').trim();
        if (!v) {
            helper.innerHTML = '<span>ℹ️ Compatible with USB/Bluetooth barcode scanner guns and box barcodes (Code 128 / QR).</span>';
            helper.style.color = '#94a3b8';
        } else if (/^\d{15}$/.test(v)) {
            helper.innerHTML = '<span style="color:#10b981; font-weight:700;">✓ Valid 15-digit Standard IMEI detected</span>';
        } else if (v.length >= 10 && v.length <= 20) {
            helper.innerHTML = '<span style="color:#38bdf8; font-weight:600;">✓ Serial / IMEI (' + v.length + ' chars) entered</span>';
        } else {
            helper.innerHTML = '<span style="color:#f59e0b;">⚠️ Note: Standard smartphone IMEIs are typically 15 digits (' + v.length + ' chars entered)</span>';
        }
    }

    // Enter key safeguard on IMEI input to prevent accidental submit when barcode scanner sends Enter
    document.addEventListener('DOMContentLoaded', () => {
        const imeiEl = document.getElementById('calcImei');
        if (imeiEl) {
            imeiEl.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    validateImeiFormat(imeiEl.value);
                    imeiEl.blur();
                }
            });
        }
    });
</script>

<?php render_end(); ?>
