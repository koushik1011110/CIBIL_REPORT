<?php
$pageTitle = 'EMI Calculator';
require_once __DIR__ . '/includes/header.php';
?>

<div class="section-wrapper" style="padding-top: 130px;">
    <div class="section-header">
        <h2>Store Loan <span>EMI Calculator</span></h2>
        <p>Calculate your exact monthly installments, total interest, and total payable amount before applying.</p>
    </div>

    <div class="calc-card">
        <div>
            <h3 style="font-size: 1.25rem; font-weight: 800; color: #fff; margin-bottom: 24px;"><i class="fa-solid fa-calculator" style="color:var(--primary);"></i> EMI Calculator Controls</h3>
            
            <div class="range-group">
                <label><span>Loan Amount (Financed):</span> <span id="loanAmtVal" style="color:var(--primary);">₹25,000</span></label>
                <input type="range" id="loanAmt" min="5000" max="100000" step="1000" value="25000">
            </div>

            <div class="range-group">
                <label><span>Interest Rate:</span> <span id="interestRateVal" style="color:var(--secondary);">12% p.a.</span></label>
                <input type="range" id="interestRate" min="0" max="24" step="0.5" value="12">
            </div>

            <div class="range-group">
                <label><span>Tenure (Months):</span> <span id="tenureVal" style="color:var(--accent);">6 Months</span></label>
                <input type="range" id="tenureMonths" min="3" max="24" step="1" value="6">
            </div>
        </div>

        <div class="calc-display">
            <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Calculated Monthly EMI</span>
            <div class="calc-emi-num" id="emiResultDisplay">₹4,315</div>
            <span style="font-size: 0.82rem; color: var(--text-muted);">per month</span>

            <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border-color); text-align: left; font-size: 0.85rem;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span style="color: var(--text-muted);">Principal Financed:</span>
                    <strong id="principalDisplay" style="color: #fff;">₹25,000</strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span style="color: var(--text-muted);">Total Interest Charges:</span>
                    <strong id="interestDisplay" style="color: var(--warning);">₹890</strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Total Amount Payable:</span>
                    <strong id="totalPayableDisplay" style="color: var(--accent);">₹25,890</strong>
                </div>
            </div>

            <a href="apply.php" class="btn-cta btn-primary" style="width: 100%; justify-content: center; margin-top: 20px;">
                <i class="fa-solid fa-paper-plane"></i> Apply for This Plan
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
