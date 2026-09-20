<?php
/**
 * GO4FIN Document Renderers
 * Provides pixel-perfect, print-friendly HTML/A4 renderers for all 11 documents.
 */

require_once __DIR__ . '/document_engine.php';

function render_document_html($docType, $data, $docNo, $template) {
    $title = $template['title'] ?? ucwords(str_replace('_', ' ', $docType));
    $logoUrl = $data['shop_logo'] ?: url('/public/assets/images/logo.png');
    $bodyContent = render_template_placeholders($template['template_content'] ?? '', $data, $docNo);
    $footerNote = $template['footer_text'] ?: 'Computer Generated Official Finance Document · GO4 Finance Private Limited';
    $signName = !empty($data['company_signatory_name'])
        ? $data['company_signatory_name']
        : (!empty($template['authorized_signatory_name']) ? $template['authorized_signatory_name'] : ($data['managing_director'] ?? 'Wazid Hoque'));
    $signTitle = !empty($data['company_signatory_title'])
        ? $data['company_signatory_title']
        : (!empty($template['authorized_signatory_title']) ? $template['authorized_signatory_title'] : 'Managing Director');
    $stampImg = !empty($template['authorized_signature_image'])
        ? url('/uploads/signatures/' . $template['authorized_signature_image'])
        : (!empty($data['company_stamp_url']) ? $data['company_stamp_url'] : '');

    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?=e($title)?> — <?=e($docNo)?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
        <style>
            * { box-sizing: border-box; }
            body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; background: #525659; margin: 0; padding: 25px; color: #1e293b; }
            .doc-container { background: #fff; max-width: 860px; margin: 0 auto; padding: 40px; border-radius: 8px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); font-size: 13px; line-height: 1.5; position: relative; }
            .action-bar { max-width: 860px; margin: 0 auto 16px auto; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; }
            .btn-action { display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px; border-radius: 6px; font-weight: 700; font-size: 13px; text-decoration: none; cursor: pointer; border: none; transition: all 0.2s; }
            .btn-back { background: #334155; color: #fff; }
            .btn-back:hover { background: #1e293b; }
            .btn-print { background: #2563eb; color: #fff; }
            .btn-print:hover { background: #1d4ed8; }
            .btn-download { background: #059669; color: #fff; }
            .btn-download:hover { background: #047857; }
            .btn-regenerate { background: #d97706; color: #fff; }

            .header-table { width: 100%; border-collapse: collapse; border-bottom: 2px solid #0f172a; padding-bottom: 15px; margin-bottom: 20px; }
            .title-badge { background: #0f172a; color: #fff; text-align: center; padding: 9px; font-weight: 800; font-size: 14px; letter-spacing: 1px; margin-bottom: 20px; border-radius: 4px; text-transform: uppercase; }
            .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; margin-bottom: 20px; }
            .box { border: 1px solid #cbd5e1; padding: 14px; border-radius: 6px; background: #f8fafc; }
            .box h4 { margin: 0 0 10px 0; font-size: 12px; font-weight: 800; color: #0f172a; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
            .info-table { width: 100%; border-collapse: collapse; font-size: 12px; }
            .info-table td { padding: 4px 0; vertical-align: top; }
            .info-table td.lbl { color: #64748b; font-weight: 600; width: 42%; }
            .info-table td.val { font-weight: 700; color: #0f172a; }

            .data-table { width: 100%; border-collapse: collapse; margin: 15px 0; font-size: 12px; }
            .data-table th { background: #0f172a; color: #fff; padding: 8px 10px; text-align: left; font-size: 11px; text-transform: uppercase; }
            .data-table td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
            .data-table tr:nth-child(even) td { background: #f8fafc; }
            .text-right { text-align: right; }
            .text-center { text-align: center; }

            .highlight-box { background: #eff6ff; border: 1px solid #bfdbfe; padding: 14px; border-radius: 8px; margin: 18px 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; text-align: center; }
            .highlight-item .label { font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase; }
            .highlight-item .value { font-size: 16px; font-weight: 800; color: #1e3a8a; margin-top: 2px; }

            .terms-box { background: #fff; border: 1px solid #cbd5e1; padding: 14px; border-radius: 6px; margin: 18px 0; font-size: 11.5px; color: #475569; line-height: 1.6; }
            .terms-box h4 { margin: 0 0 6px 0; font-size: 11px; font-weight: 800; text-transform: uppercase; color: #0f172a; }

            .sign-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 30px; text-align: center; }
            .sign-box { border: 1px dashed #94a3b8; border-radius: 6px; padding: 12px; background: #fafafa; min-height: 105px; display: flex; flex-direction: column; justify-content: space-between; }
            .seal-stamp { border: 2px solid #2563eb; color: #2563eb; display: inline-block; padding: 3px 8px; border-radius: 50%; font-size: 9px; font-weight: 900; transform: rotate(-8deg); }
            .esign-badge { background: #e6f4ea; border: 1px solid #ceead6; color: #137333; padding: 4px; border-radius: 4px; font-size: 10px; font-weight: 800; }

            .doc-footer { margin-top: 30px; text-align: center; font-size: 10px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 10px; }

            @media print {
                body { background: #fff; padding: 0; color: #000; }
                .doc-container { box-shadow: none; max-width: 100%; padding: 10px; border: none; }
                .action-bar { display: none !important; }
                .no-print { display: none !important; }
            }
        </style>
    </head>
    <body>

    <!-- ACTION BUTTONS (Hidden during print) -->
    <div class="action-bar no-print">
        <div>
            <a href="javascript:history.back()" class="btn-action btn-back">← Back</a>
        </div>
        <div style="display: flex; gap: 8px;">
            <button onclick="window.print()" class="btn-action btn-print">🖨️ Print Document</button>
            <a href="<?=url('/download-document.php?type=' . urlencode($docType) . '&id=' . $data['finance_id'] . ($data['target_payment'] ? '&payment_id=' . $data['target_payment']['id'] : ''))?>" class="btn-action btn-download">⬇️ Download PDF</a>
        </div>
    </div>

    <div class="doc-container">

        <!-- COMPANY HEADER -->
        <table class="header-table">
            <tr>
                <td style="width: 60%; vertical-align: top;">
                    <div style="display: flex; align-items: center; gap: 14px;">
                        <img src="<?=$logoUrl?>" alt="Logo" style="height: 52px; max-width: 130px; object-fit: contain;">
                        <div>
                            <h2 style="margin: 0; font-size: 18px; font-weight: 900; color: #0f172a;"><?=$data['company_name']?></h2>
                            <p style="margin: 2px 0 0 0; font-size: 11px; color: #64748b;">Certified Retail Financing Partner & Consumer Credit Network</p>
                            <p style="margin: 2px 0 0 0; font-size: 10.5px; color: #64748b;">CIN: <?=$data['company_cin']?> | TAN: <?=$data['company_tan']?> | Corporate Office: Assam, India</p>
                            <p style="margin: 2px 0 0 0; font-size: 10.5px; color: #64748b;">Phone: <?=$data['company_phone']?> | Email: <?=$data['company_email']?></p>
                        </div>
                    </div>
                </td>
                <td style="text-align: right; vertical-align: top; width: 40%;">
                    <div style="font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase;">DOCUMENT REFERENCE NO</div>
                    <div style="font-size: 14px; font-weight: 900; color: #2563eb; font-family: monospace; letter-spacing: 0.5px;"><?=e($docNo)?></div>
                    <div style="font-size: 11px; color: #64748b; margin-top: 4px;">Loan App No: <strong><?=e($data['application_no'])?></strong></div>
                    <div style="font-size: 11px; color: #64748b;">Issue Date: <strong><?=date('d M Y')?></strong></div>
                    <div style="font-size: 11px; color: #64748b;">Status: <strong style="color: #059669;"><?=strtoupper($data['status'])?></strong></div>
                </td>
            </tr>
        </table>

        <!-- TITLE BADGE -->
        <div class="title-badge"><?=e($title)?></div>

        <!-- TWO COLUMN DETAILS (Borrower + Financed Product & Lender) -->
        <div class="grid-2">
            <div class="box">
                <h4>1. Borrower Particulars</h4>
                <table class="info-table">
                    <tr><td class="lbl">Customer Name:</td><td class="val"><?=e($data['customer_name'])?></td></tr>
                    <?php if (!empty($data['father_name'])): ?>
                        <tr><td class="lbl">Father's Name:</td><td class="val"><?=e($data['father_name'])?></td></tr>
                    <?php endif; ?>
                    <tr><td class="lbl">Mobile Number:</td><td class="val"><?=e($data['customer_mobile'])?></td></tr>
                    <tr><td class="lbl">PAN Card:</td><td class="val"><?=e($data['customer_pan'])?></td></tr>
                    <tr><td class="lbl">Aadhaar:</td><td class="val"><?=e($data['aadhaar_masked'])?> 🛡️</td></tr>
                    <tr><td class="lbl">Full Address:</td><td class="val"><?=e($data['customer_address'])?></td></tr>
                </table>
            </div>

            <div class="box">
                <h4>2. Loan & Retail Partner</h4>
                <table class="info-table">
                    <tr><td class="lbl">Retail Merchant:</td><td class="val"><?=e($data['shop_name'])?></td></tr>
                    <tr><td class="lbl">Store GSTIN:</td><td class="val"><?=e($data['shop_gstin'])?></td></tr>
                    <tr><td class="lbl">Product Financed:</td><td class="val"><?=e($data['product_name'])?></td></tr>
                    <tr><td class="lbl">Brand / Model:</td><td class="val"><?=e($data['product_brand'])?> <?=e($data['product_model'])?></td></tr>
                    <tr><td class="lbl">Product Price:</td><td class="val"><?=money($data['product_price'])?></td></tr>
                    <tr><td class="lbl">Down Payment:</td><td class="val" style="color:#059669;"><?=money($data['down_payment'])?> (Paid)</td></tr>
                </table>
            </div>
        </div>

        <!-- LOAN FINANCIAL HIGHLIGHT STRIP -->
        <div class="highlight-box">
            <div class="highlight-item">
                <div class="label">Financed Loan</div>
                <div class="value" style="color:#2563eb;"><?=money($data['finance_amount'])?></div>
            </div>
            <div class="highlight-item">
                <div class="label">Monthly EMI</div>
                <div class="value" style="color:#059669;"><?=money($data['emi_amount'])?>/mo</div>
            </div>
            <div class="highlight-item">
                <div class="label">Tenure & Rate</div>
                <div class="value"><?=e($data['tenure'])?> Mos @ <?=e($data['interest_rate'])?>%</div>
            </div>
            <div class="highlight-item">
                <div class="label">Total Amount Paid</div>
                <div class="value" style="color:#059669;"><?=money($data['total_paid'])?></div>
            </div>
            <div class="highlight-item">
                <div class="label">Total Outstanding</div>
                <div class="value" style="color:<?=($data['total_outstanding_due'] > 0 ? '#dc2626' : '#059669')?>;"><?=money($data['total_outstanding_due'])?></div>
            </div>
        </div>

        <!-- DOCUMENT-SPECIFIC BODY SECTIONS -->
        <?php
        switch ($docType) {
            case 'loan_application':
                render_application_specifics($data);
                break;
            case 'sanction_letter':
                render_sanction_specifics($data);
                break;
            case 'repayment_schedule':
                render_schedule_specifics($data);
                break;
            case 'disbursement_letter':
                render_disbursement_specifics($data);
                break;
            case 'payment_receipt':
                render_receipt_specifics($data);
                break;
            case 'account_statement':
                render_soa_specifics($data);
                break;
            case 'outstanding_statement':
                render_outstanding_specifics($data);
                break;
            case 'foreclosure_statement':
                render_foreclosure_specifics($data);
                break;
            case 'noc_certificate':
                render_noc_specifics($data);
                break;
            case 'loan_closure':
                render_closure_specifics($data);
                break;
            case 'loan_agreement':
            default:
                render_agreement_specifics($data);
                break;
        }
        ?>

        <!-- TERMS & CONDITIONS / DECLARATION BODY -->
        <?php if (!empty($bodyContent)): ?>
            <div class="terms-box">
                <h4>Legal Terms, Declaration & Repayment Obligations</h4>
                <div style="white-space: pre-line;"><?=nl2br(e($bodyContent))?></div>
            </div>
        <?php endif; ?>

        <!-- DIGITAL SIGNATURES & STAMP AREA -->
        <div class="sign-grid">
            <div class="sign-box">
                <div style="font-size: 11px; font-weight: 800; color: #0f172a;">BORROWER SIGNATURE</div>
                <div class="esign-badge">
                    ✓ AADHAAR / KYC VERIFIED<br>
                    <?=e($data['customer_name'])?><br>
                    Date: <?=date('d M Y')?>
                </div>
                <div style="font-size: 10px; color: #64748b; font-weight: 700; border-top: 1px dashed #94a3b8; padding-top: 4px;">Borrower Acceptance</div>
            </div>

            <div class="sign-box">
                <div style="font-size: 11px; font-weight: 800; color: #0f172a;">WITNESS / GUARANTOR</div>
                <div style="height: 35px; display: flex; align-items: center; justify-content: center; font-style: italic; color: #64748b; font-size: 11px;">
                    Reference: <?=e($data['witness_name'])?>
                </div>
                <div style="font-size: 10px; color: #64748b; font-weight: 700; border-top: 1px dashed #94a3b8; padding-top: 4px;">Guarantor / Witness</div>
            </div>

            <div class="sign-box">
                <div style="font-size: 11px; font-weight: 800; color: #0f172a;">FOR GO4 FINANCE PVT LTD</div>
                <div style="margin: 4px 0; min-height: 42px; display: flex; align-items: center; justify-content: center;">
                    <?php if (!empty($stampImg)): ?>
                        <img src="<?=e($stampImg)?>" alt="Official Stamp & Signature" style="max-height: 48px; max-width: 140px; object-fit: contain;">
                    <?php else: ?>
                        <span class="seal-stamp">OFFICIAL SEAL</span>
                    <?php endif; ?>
                </div>
                <div style="font-size: 10px; color: #0f172a; font-weight: 800; border-top: 1px dashed #94a3b8; padding-top: 4px;">
                    <?=e($signName)?><br>
                    <span style="font-size: 9px; color: #64748b; font-weight: normal;"><?=e($signTitle)?></span>
                </div>
            </div>
        </div>

        <!-- FOOTER DISCLOSURE -->
        <div class="doc-footer">
            <?=e($footerNote)?><br>
            Corporate CIN: <?=$data['company_cin']?> | Document No: <strong><?=e($docNo)?></strong> | Generated on: <?=date('d M Y, h:i A')?> | Page 1 of 1
        </div>

    </div>

    </body>
    </html>
    <?php
}

// ---------------- Helper Specifics for Each Document ---------------- //

function render_application_specifics($data) { ?>
    <div class="box" style="margin-bottom: 20px;">
        <h4>3. Applicant KYC, Employment & Mandate Profile</h4>
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; font-size: 12px;">
            <div>
                <table class="info-table">
                    <tr><td class="lbl">Occupation:</td><td class="val"><?=e($data['occupation'])?></td></tr>
                    <tr><td class="lbl">Qualification:</td><td class="val"><?=e($data['qualification'])?></td></tr>
                    <tr><td class="lbl">Monthly Income:</td><td class="val"><?=money($data['monthly_income'])?></td></tr>
                </table>
            </div>
            <div>
                <table class="info-table">
                    <tr><td class="lbl">Salary / Mandate Bank:</td><td class="val"><?=e($data['bank_name'])?></td></tr>
                    <tr><td class="lbl">Account Number:</td><td class="val"><?=e($data['account_no'])?></td></tr>
                    <tr><td class="lbl">IFSC & Mode:</td><td class="val"><?=e($data['ifsc_code'])?> (<?=e($data['mandate_mode'])?>)</td></tr>
                </table>
            </div>
        </div>
    </div>
<?php }

function render_sanction_specifics($data) { ?>
    <div class="box" style="margin-bottom: 20px; background: #f0fdf4; border-color: #bbf7d0;">
        <h4 style="color: #166534;">3. Sanction Parameters & Approval Terms</h4>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; text-align: center; margin-top: 10px;">
            <div style="background: #fff; padding: 10px; border-radius: 6px; border: 1px solid #bbf7d0;">
                <div style="font-size: 11px; color: #64748b;">Sanctioned Loan</div>
                <div style="font-size: 16px; font-weight: 800; color: #15803d;"><?=money($data['finance_amount'])?></div>
            </div>
            <div style="background: #fff; padding: 10px; border-radius: 6px; border: 1px solid #bbf7d0;">
                <div style="font-size: 11px; color: #64748b;">Approved Tenure</div>
                <div style="font-size: 16px; font-weight: 800; color: #15803d;"><?=e($data['tenure'])?> Months</div>
            </div>
            <div style="background: #fff; padding: 10px; border-radius: 6px; border: 1px solid #bbf7d0;">
                <div style="font-size: 11px; color: #64748b;">Monthly Installment</div>
                <div style="font-size: 16px; font-weight: 800; color: #15803d;"><?=money($data['emi_amount'])?>/mo</div>
            </div>
        </div>
    </div>
<?php }

function render_schedule_specifics($data) { ?>
    <?php if (!empty($data['emis'])): ?>
        <h4 style="margin: 15px 0 6px 0; font-size: 12px; font-weight: 800; text-transform: uppercase; color: #0f172a;">3. Complete Monthly Installment Repayment Schedule</h4>
        <table class="data-table">
            <thead>
                <tr>
                    <th class="text-center">Inst #</th>
                    <th>Due Date</th>
                    <th class="text-right">Principal (₹)</th>
                    <th class="text-right">Interest (₹)</th>
                    <th class="text-right">EMI Amount (₹)</th>
                    <th class="text-center">Payment Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['emis'] as $e): ?>
                    <tr>
                        <td class="text-center">#<?=$e['installment_no']?></td>
                        <td><?=date('d M Y', strtotime($e['due_date']))?></td>
                        <td class="text-right"><?=money($e['principal'])?></td>
                        <td class="text-right"><?=money($e['interest'])?></td>
                        <td class="text-right"><strong><?=money($e['amount'])?></strong></td>
                        <td class="text-center">
                            <?php if ($e['status'] === 'paid'): ?>
                                <span style="color: #059669; font-weight: 800;">✓ PAID</span>
                            <?php else: ?>
                                <span style="color: #d97706; font-weight: 700;">UPCOMING</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f1f5f9; font-weight: 800;">
                    <td colspan="2" class="text-center">TOTALS</td>
                    <td class="text-right"><?=money($data['finance_amount'])?></td>
                    <td class="text-right"><?=money($data['total_interest'])?></td>
                    <td class="text-right"><?=money($data['total_payable'])?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>
<?php }

function render_disbursement_specifics($data) { ?>
    <div class="box" style="margin-bottom: 20px;">
        <h4>3. Disbursement Breakdown & Retailer Settlement</h4>
        <table class="data-table" style="margin: 6px 0;">
            <tr><td>Total Invoice Value (MRP):</td><td class="text-right"><strong><?=money($data['product_price'])?></strong></td></tr>
            <tr><td>Less: Down Payment Cleared by Borrower:</td><td class="text-right" style="color: #059669;"><strong>- <?=money($data['down_payment'])?></strong></td></tr>
            <tr style="background: #f1f5f9; font-weight: 800;">
                <td>Net Financed Amount Disbursed to Retail Partner:</td>
                <td class="text-right" style="color: #2563eb; font-size: 14px;"><?=money($data['finance_amount'])?></td>
            </tr>
            <tr><td>Disbursement Destination:</td><td class="text-right"><?=e($data['shop_name'])?> (Retail Counter Store)</td></tr>
            <tr><td>First Monthly EMI Due Date:</td><td class="text-right"><strong><?=e($data['first_emi_date'])?></strong></td></tr>
        </table>
    </div>
<?php }

function render_receipt_specifics($data) { 
    $pay = $data['target_payment'];
    if (!$pay) {
        echo "<div class='box'><p>No specific payment record found.</p></div>";
        return;
    }
    $words = get_amount_in_words($pay['amount']);
    ?>
    <div class="box" style="margin-bottom: 20px; background: #ecfdf5; border-color: #a7f3d0;">
        <h4 style="color: #065f46;">3. Payment Transaction Receipt Acknowledgement</h4>
        <table class="info-table" style="font-size: 13px;">
            <tr><td class="lbl">Receipt Reference No:</td><td class="val" style="font-family: monospace;"><?=e($pay['reference_no'] ?: 'MAN' . $pay['id'])?></td></tr>
            <tr><td class="lbl">Payment Date & Time:</td><td class="val"><?=date('d M Y, h:i A', strtotime($pay['paid_at']))?></td></tr>
            <tr><td class="lbl">Payment Method:</td><td class="val"><span style="background: #059669; color: #fff; padding: 2px 8px; border-radius: 4px; font-size: 11px;"><?=strtoupper($pay['payment_method'] ?: 'Cash')?></span></td></tr>
            <tr><td class="lbl">Amount Received:</td><td class="val" style="font-size: 18px; color: #059669;"><?=money($pay['amount'])?></td></tr>
            <tr><td class="lbl">Amount in Words:</td><td class="val" style="font-style: italic; color: #065f46;"><?=e($words)?></td></tr>
            <tr><td class="lbl">Payment Purpose:</td><td class="val"><?=e($pay['remarks'] ?: 'Monthly EMI Installment')?></td></tr>
            <tr><td class="lbl">Remaining Loan Outstanding:</td><td class="val" style="color: #dc2626;"><?=money($data['total_outstanding_due'])?></td></tr>
        </table>
    </div>
<?php }

function render_soa_specifics($data) { ?>
    <h4 style="margin: 15px 0 6px 0; font-size: 12px; font-weight: 800; text-transform: uppercase; color: #0f172a;">3. Chronological Statement of Loan Account (Ledger)</h4>
    <table class="data-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Particulars / Transaction Description</th>
                <th>Mode / Ref</th>
                <th class="text-right">Debit (₹)</th>
                <th class="text-right">Credit (₹)</th>
                <th class="text-right">Balance Outstanding (₹)</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $runBal = $data['finance_amount'];
            ?>
            <tr>
                <td><?=date('d M Y', strtotime($data['created_at']))?></td>
                <td>Loan Facility Disbursed for <?=e($data['product_name'])?></td>
                <td>ORIGINATION</td>
                <td class="text-right"><?=money($data['finance_amount'])?></td>
                <td class="text-right">-</td>
                <td class="text-right"><strong><?=money($runBal)?></strong></td>
            </tr>
            <?php foreach ($data['payments'] as $pItem): 
                $pAmt = floatval($pItem['amount']);
                $runBal = max(0, $runBal - $pAmt);
            ?>
                <tr>
                    <td><?=date('d M Y', strtotime($pItem['paid_at']))?></td>
                    <td><?=e($pItem['remarks'] ?: 'Payment Received')?></td>
                    <td><code><?=e($pItem['reference_no'] ?: $pItem['payment_method'])?></code></td>
                    <td class="text-right">-</td>
                    <td class="text-right" style="color: #059669;"><strong><?=money($pAmt)?></strong></td>
                    <td class="text-right"><strong><?=money($runBal)?></strong></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php }

function render_outstanding_specifics($data) { ?>
    <div class="box" style="margin-bottom: 20px;">
        <h4>3. Detailed Outstanding Balance & Dues Itemization</h4>
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; text-align: center; margin-top: 10px;">
            <div style="background: #fff; padding: 12px; border-radius: 6px; border: 1px solid #cbd5e1;">
                <div style="font-size: 11px; color: #64748b;">Principal Balance</div>
                <div style="font-size: 16px; font-weight: 800; color: #0f172a;"><?=money($data['principal_outstanding'])?></div>
            </div>
            <div style="background: #fff; padding: 12px; border-radius: 6px; border: 1px solid #cbd5e1;">
                <div style="font-size: 11px; color: #64748b;">Unpaid Installments</div>
                <div style="font-size: 16px; font-weight: 800; color: #d97706;"><?=$data['unpaid_emis_count']?> Month(s)</div>
            </div>
            <div style="background: #fff; padding: 12px; border-radius: 6px; border: 1px solid #cbd5e1;">
                <div style="font-size: 11px; color: #64748b;">Total Outstanding Due</div>
                <div style="font-size: 18px; font-weight: 800; color: #dc2626;"><?=money($data['total_outstanding_due'])?></div>
            </div>
        </div>
    </div>
<?php }

function render_foreclosure_specifics($data) { ?>
    <div class="box" style="margin-bottom: 20px; background: #fffbeb; border-color: #fde68a;">
        <h4 style="color: #92400e;">3. Loan Foreclosure Settlement Calculation</h4>
        <table class="data-table" style="margin: 6px 0;">
            <tr><td>Total Scheduled Loan Liability:</td><td class="text-right"><?=money($data['total_payable'])?></td></tr>
            <tr><td>Less: Total Amount Paid to Date:</td><td class="text-right" style="color: #059669;">- <?=money($data['total_paid'])?></td></tr>
            <tr><td>Pre-closure Charges / Processing:</td><td class="text-right">₹0.00 (Waived)</td></tr>
            <tr style="background: #fef3c7; font-weight: 800; font-size: 14px;">
                <td style="color: #92400e;">Net Foreclosure Settlement Payable (100% Clearance):</td>
                <td class="text-right" style="color: #b45309;"><?=money($data['foreclosure_payable'])?></td>
            </tr>
            <tr><td>Settlement Quote Valid Until:</td><td class="text-right"><strong><?=e($data['foreclosure_valid_until'])?></strong></td></tr>
        </table>
    </div>
<?php }

function render_noc_specifics($data) { ?>
    <div class="box" style="margin-bottom: 20px; background: #ecfdf5; border-color: #a7f3d0; text-align: center; padding: 20px;">
        <h3 style="color: #065f46; margin: 0 0 8px 0; font-size: 18px;">🎓 OFFICIAL LOAN NO-DUE CERTIFICATION</h3>
        <p style="margin: 0; color: #047857; font-size: 14px; line-height: 1.6;">
            All <strong><?=$data['total_emis_count']?> monthly installment EMIs</strong> for this loan have been <strong>100% Cleared and Repaid</strong>.<br>
            Current Outstanding Balance: <strong style="font-size: 16px;">₹0.00 (ZERO DUES)</strong>.<br>
            Hypothecation and lien on <strong><?=e($data['product_name'])?></strong> is hereby fully revoked and released.
        </p>
    </div>
<?php }

function render_closure_specifics($data) { ?>
    <div class="box" style="margin-bottom: 20px; background: #eff6ff; border-color: #bfdbfe; text-align: center; padding: 20px;">
        <h3 style="color: #1e3a8a; margin: 0 0 8px 0; font-size: 18px;">🏆 CERTIFICATE OF FINAL LOAN CLOSURE</h3>
        <p style="margin: 0; color: #1e40af; font-size: 14px; line-height: 1.6;">
            We hereby certify that Loan Account <strong>#<?=e($data['application_no'])?></strong> has reached full maturity and successful closure.<br>
            Total Amount Repaid: <strong><?=money($data['total_paid'])?></strong> | Final Balance: <strong>₹0.00</strong><br>
            Account status in our central credit bureau registry has been marked as <strong>CLOSED / FULLY REPAID</strong>.
        </p>
    </div>
<?php }

function render_agreement_specifics($data) { 
    render_schedule_specifics($data);
}
