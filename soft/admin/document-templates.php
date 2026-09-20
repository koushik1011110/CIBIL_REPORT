<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/document_engine.php';

role('superadmin');

$p = db();
$msg = '';
$err = '';

$sigUploadDir = __DIR__ . '/../uploads/signatures/';
if (!file_exists($sigUploadDir)) {
    @mkdir($sigUploadDir, 0777, true);
}

// 1. Handle Global Company Stamp & Signatory Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_global_signatory') {
    $signName = trim($_POST['company_signatory_name'] ?? '');
    $signTitle = trim($_POST['company_signatory_title'] ?? '');

    if (!empty($signName)) {
        set_setting('company_signatory_name', $signName);
    }
    if (!empty($signTitle)) {
        set_setting('company_signatory_title', $signTitle);
    }

    // Handle Remove Stamp
    if (isset($_POST['remove_stamp']) && $_POST['remove_stamp'] == '1') {
        $oldStamp = get_setting('company_stamp_signature', '');
        if (!empty($oldStamp) && file_exists($sigUploadDir . $oldStamp)) {
            @unlink($sigUploadDir . $oldStamp);
        }
        set_setting('company_stamp_signature', '');
        $msg = "Official stamp & signature removed. Fallback digital seal will now be displayed.";
    }

    // Handle File Upload
    if (isset($_FILES['stamp_image']) && $_FILES['stamp_image']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['stamp_image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'webp'];

        if (in_array($ext, $allowed)) {
            $newFileName = 'stamp_go4fin_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
            $targetPath = $sigUploadDir . $newFileName;

            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                // Delete old file if exists
                $oldStamp = get_setting('company_stamp_signature', '');
                if (!empty($oldStamp) && file_exists($sigUploadDir . $oldStamp)) {
                    @unlink($sigUploadDir . $oldStamp);
                }

                set_setting('company_stamp_signature', $newFileName);
                $msg = "Official stamp & signature uploaded and signatory details updated successfully!";
            } else {
                $err = "Failed to move uploaded stamp file to server.";
            }
        } else {
            $err = "Invalid image format. Please upload PNG, JPG, JPEG, or WEBP.";
        }
    } else {
        if (empty($err) && empty($msg)) {
            $msg = "Signatory details updated successfully!";
        }
    }

    log_audit('Company Stamp Updated', 'Documents', "Superadmin updated GO4FIN stamp signature and signatory name: {$signName}", u()['id']);
}

// 2. Handle Individual Template Save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_template') {
    $docType = trim($_POST['doc_type'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $headerText = trim($_POST['header_text'] ?? '');
    $footerText = trim($_POST['footer_text'] ?? '');
    $signName = trim($_POST['authorized_signatory_name'] ?? '');
    $signTitle = trim($_POST['authorized_signatory_title'] ?? '');
    $content = trim($_POST['template_content'] ?? '');

    $templateSigImage = null;

    // Check remove template specific stamp
    if (isset($_POST['remove_template_stamp']) && $_POST['remove_template_stamp'] == '1') {
        $curTpl = $p->prepare("SELECT authorized_signature_image FROM document_templates WHERE doc_type = ?");
        $curTpl->execute([$docType]);
        $oldImg = $curTpl->fetchColumn();
        if (!empty($oldImg) && file_exists($sigUploadDir . $oldImg)) {
            @unlink($sigUploadDir . $oldImg);
        }
        $p->prepare("UPDATE document_templates SET authorized_signature_image = NULL WHERE doc_type = ?")->execute([$docType]);
    }

    // Handle template-specific stamp upload
    if (isset($_FILES['template_stamp']) && $_FILES['template_stamp']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['template_stamp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['png', 'jpg', 'jpeg', 'webp'];

        if (in_array($ext, $allowed)) {
            $tplFileName = 'stamp_' . $docType . '_' . time() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $sigUploadDir . $tplFileName)) {
                $templateSigImage = $tplFileName;
            }
        }
    }

    if (!empty($docType) && !empty($title)) {
        if ($templateSigImage !== null) {
            $stmt = $p->prepare("UPDATE document_templates SET 
                title = ?, header_text = ?, footer_text = ?, authorized_signatory_name = ?, authorized_signatory_title = ?, authorized_signature_image = ?, template_content = ?
                WHERE doc_type = ?");
            $stmt->execute([$title, $headerText, $footerText, $signName, $signTitle, $templateSigImage, $content, $docType]);
        } else {
            $stmt = $p->prepare("UPDATE document_templates SET 
                title = ?, header_text = ?, footer_text = ?, authorized_signatory_name = ?, authorized_signatory_title = ?, template_content = ?
                WHERE doc_type = ?");
            $stmt->execute([$title, $headerText, $footerText, $signName, $signTitle, $content, $docType]);
        }

        log_audit('Document Template Updated', 'Documents', "Superadmin updated document template for '{$docType}' ({$title})", u()['id']);
        $msg = "Template '{$title}' updated successfully!";
    } else {
        $err = "Please provide all required template fields.";
    }
}

// Fetch all templates
$templates = $p->query("SELECT * FROM document_templates ORDER BY id ASC")->fetchAll();
$selectedType = trim($_GET['edit'] ?? ($templates[0]['doc_type'] ?? 'loan_application'));

$selectedTemplate = null;
foreach ($templates as $t) {
    if ($t['doc_type'] === $selectedType) {
        $selectedTemplate = $t;
        break;
    }
}
if (!$selectedTemplate && !empty($templates)) {
    $selectedTemplate = $templates[0];
    $selectedType = $selectedTemplate['doc_type'];
}

// Current Global Stamp Settings
$currentStamp = get_setting('company_stamp_signature', '');
$currentStampUrl = (!empty($currentStamp) && file_exists($sigUploadDir . $currentStamp))
    ? url('/uploads/signatures/' . $currentStamp)
    : '';
$currentSignName = get_setting('company_signatory_name', 'Wazid Hoque');
$currentSignTitle = get_setting('company_signatory_title', 'Managing Director');

start('Document Template & Stamp Signature Management');
?>

<div class="card" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
    <div>
        <h3 style="font-size: 1.25rem; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="stamp" style="color: var(--primary);"></i> Document Stamp Signature & Template Manager
        </h3>
        <p class="muted" style="margin-top: 4px;">Upload company stamp signature for "FOR GO4 FINANCE PVT LTD", edit authorized signatory name, and customize all 11 document templates</p>
    </div>
    <a href="<?=url('/admin/documents.php')?>" class="btn" style="background: rgba(255,255,255,0.08); border: 1px solid var(--border-color);">
        ← Back to Documents
    </a>
</div>

<?php if ($msg): ?>
    <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;">
        <strong>✓ Success!</strong> <?=e($msg)?>
    </div>
<?php endif; ?>

<?php if ($err): ?>
    <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;">
        <strong>❌ Error:</strong> <?=e($err)?>
    </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- 1. OFFICIAL COMPANY STAMP & AUTHORIZED SIGNATORY (FOR GO4 FINANCE PVT LTD) -->
<!-- ========================================================================= -->
<div class="card" style="margin-bottom: 24px; border: 1px solid rgba(59,130,246,0.35); background: linear-gradient(180deg, rgba(30,41,59,0.8), rgba(15,23,42,0.9));">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
        <div>
            <h4 style="margin: 0; font-size: 1.1rem; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
                <i data-lucide="check-circle" style="color: #10b981;"></i> Official Company Stamp & Signature — "FOR GO4 FINANCE PVT LTD"
            </h4>
            <p class="muted" style="font-size: 0.8rem; margin: 4px 0 0 0;">
                This official stamp and signature image will automatically appear on all generated loan agreements, sanction letters, NOCs, receipts, and closure certificates.
            </p>
        </div>
        <span class="badge badge-primary" style="font-size: 0.78rem;">Company-Wide Default</span>
    </div>

    <form method="POST" enctype="multipart/form-data" style="display: grid; grid-template-columns: 1fr 280px; gap: 24px; align-items: flex-start;">
        <input type="hidden" name="action" value="save_global_signatory">

        <!-- FORM INPUTS -->
        <div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                <div class="field" style="margin: 0;">
                    <label style="color: #93c5fd; font-weight: 700;">Authorized Signatory Name *</label>
                    <input type="text" name="company_signatory_name" required value="<?=e($currentSignName)?>" placeholder="e.g. Wazid Hoque" style="padding: 10px; font-weight: 700;">
                    <span class="muted" style="font-size: 0.72rem;">Full name of Director / Authorized Signatory</span>
                </div>

                <div class="field" style="margin: 0;">
                    <label style="color: #93c5fd; font-weight: 700;">Authorized Designation / Title *</label>
                    <input type="text" name="company_signatory_title" required value="<?=e($currentSignTitle)?>" placeholder="e.g. Founder & Managing Director" style="padding: 10px;">
                    <span class="muted" style="font-size: 0.72rem;">Official designation in GO4 Finance Private Limited</span>
                </div>
            </div>

            <div class="field" style="margin-bottom: 18px;">
                <label style="color: #93c5fd; font-weight: 700;">Upload Stamp & Signature Image (PNG with transparent background recommended)</label>
                <input type="file" name="stamp_image" accept="image/png,image/jpeg,image/jpg,image/webp" style="padding: 8px; background: rgba(0,0,0,0.2); border: 1px dashed var(--border-color); border-radius: 6px; width: 100%;">
                <span class="muted" style="font-size: 0.72rem;">Supported formats: PNG, JPG, JPEG, WEBP. Max file size: 5MB.</span>
            </div>

            <div style="display: flex; gap: 12px; align-items: center;">
                <button type="submit" class="btn" style="background: linear-gradient(135deg, #2563eb, #1d4ed8); font-weight: 700; padding: 10px 22px;">
                    💾 Save Stamp & Signatory Details
                </button>

                <?php if (!empty($currentStampUrl)): ?>
                    <button type="submit" name="remove_stamp" value="1" class="btn" style="background: rgba(239,68,68,0.15); color: #ef4444; border: 1px solid #ef4444; padding: 10px 16px;" onclick="return confirm('Are you sure you want to remove the uploaded stamp image? Documents will use the default digital seal.')">
                        🗑️ Remove Stamp Image
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- LIVE PREVIEW BOX (Exact replica of document signature box) -->
        <div style="background: #fff; border: 2px dashed #94a3b8; border-radius: 8px; padding: 16px; text-align: center; color: #1e293b; box-shadow: 0 4px 15px rgba(0,0,0,0.15);">
            <div style="font-size: 10px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 6px; letter-spacing: 0.5px;">
                Live Document Box Preview
            </div>

            <div style="border: 1px dashed #cbd5e1; border-radius: 6px; padding: 12px; background: #fafafa; min-height: 120px; display: flex; flex-direction: column; justify-content: space-between;">
                <div style="font-size: 11px; font-weight: 800; color: #0f172a;">
                    FOR GO4 FINANCE PVT LTD
                </div>

                <div style="margin: 6px 0; min-height: 48px; display: flex; align-items: center; justify-content: center;">
                    <?php if (!empty($currentStampUrl)): ?>
                        <img src="<?=$currentStampUrl?>" alt="Uploaded Stamp" style="max-height: 52px; max-width: 140px; object-fit: contain;">
                    <?php else: ?>
                        <div style="border: 2px solid #2563eb; color: #2563eb; display: inline-block; padding: 3px 8px; border-radius: 50%; font-size: 9px; font-weight: 900; transform: rotate(-8deg);">
                            GO4FIN SEAL
                        </div>
                    <?php endif; ?>
                </div>

                <div style="font-size: 10px; color: #0f172a; font-weight: 800; border-top: 1px dashed #94a3b8; padding-top: 4px;">
                    <?=e($currentSignName)?><br>
                    <span style="font-size: 9px; color: #64748b; font-weight: normal;"><?=e($currentSignTitle)?></span>
                </div>
            </div>

            <div style="margin-top: 8px; font-size: 10.5px; color: <?=!empty($currentStampUrl) ? '#059669' : '#d97706'?>; font-weight: 700;">
                <?=!empty($currentStampUrl) ? '✓ Active Stamp Image Loaded' : 'ℹ️ Using Fallback Digital Seal'?>
            </div>
        </div>
    </form>
</div>

<!-- ========================================================================= -->
<!-- 2. TEMPLATE-SPECIFIC TEXT & LEGAL DECLARATION CUSTOMIZATION -->
<!-- ========================================================================= -->
<div style="display: grid; grid-template-columns: 280px 1fr; gap: 24px;">

    <!-- TEMPLATE LIST SIDEBAR -->
    <div class="card" style="padding: 12px;">
        <h4 style="font-size: 0.95rem; font-weight: 800; color: #fff; margin-bottom: 12px; padding: 0 8px;">
            11 Loan Documents
        </h4>
        <div style="display: flex; flex-direction: column; gap: 4px;">
            <?php foreach ($templates as $tpl): 
                $isActive = ($tpl['doc_type'] === $selectedType);
            ?>
                <a href="?edit=<?=urlencode($tpl['doc_type'])?>" style="display: block; padding: 10px 12px; border-radius: 6px; text-decoration: none; font-size: 0.84rem; font-weight: 700; transition: all 0.2s; background: <?=$isActive ? 'var(--primary)' : 'rgba(255,255,255,0.03)'?>; color: <?=$isActive ? '#fff' : 'var(--text-muted)'?>; border: 1px solid <?=$isActive ? 'var(--primary)' : 'transparent'?>;">
                    <?=e($tpl['title'])?>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- DYNAMIC PLACEHOLDERS REFERENCE BOX -->
        <div style="margin-top: 24px; padding: 12px; background: rgba(59,130,246,0.06); border: 1px solid rgba(59,130,246,0.2); border-radius: 8px;">
            <h5 style="margin: 0 0 8px 0; font-size: 0.8rem; font-weight: 800; color: #60a5fa; text-transform: uppercase;">
                📋 Dynamic Placeholders
            </h5>
            <p style="font-size: 0.72rem; color: var(--text-muted); margin-bottom: 8px;">
                Use these tags in the content below; they will automatically be replaced with live database values:
            </p>
            <div style="display: flex; flex-direction: column; gap: 3px; font-family: monospace; font-size: 0.72rem; color: #93c5fd;">
                <span>{{customer_name}}</span>
                <span>{{customer_id}}</span>
                <span>{{customer_mobile}}</span>
                <span>{{customer_pan}}</span>
                <span>{{customer_address}}</span>
                <span>{{loan_number}}</span>
                <span>{{loan_amount}}</span>
                <span>{{down_payment}}</span>
                <span>{{emi_amount}}</span>
                <span>{{tenure}}</span>
                <span>{{interest_rate}}</span>
                <span>{{first_emi_date}}</span>
                <span>{{last_emi_date}}</span>
                <span>{{outstanding_amount}}</span>
                <span>{{total_paid}}</span>
                <span>{{product_name}}</span>
                <span>{{shop_name}}</span>
                <span>{{company_name}}</span>
                <span>{{document_number}}</span>
                <span>{{document_date}}</span>
            </div>
        </div>
    </div>

    <!-- TEMPLATE EDIT FORM -->
    <div class="card">
        <?php if ($selectedTemplate): ?>
            <h3 style="font-size: 1.15rem; font-weight: 800; color: #fff; margin-bottom: 18px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">
                Editing Template: <?=e($selectedTemplate['title'])?>
                <span style="font-size: 0.75rem; font-family: monospace; color: #60a5fa; font-weight: normal; margin-left: 8px;">(doc_type: <?=e($selectedTemplate['doc_type'])?>)</span>
            </h3>

            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_template">
                <input type="hidden" name="doc_type" value="<?=e($selectedTemplate['doc_type'])?>">

                <div class="field" style="margin-bottom: 16px;">
                    <label>Document Title *</label>
                    <input type="text" name="title" required value="<?=e($selectedTemplate['title'])?>">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div class="field" style="margin: 0;">
                        <label>Header Subtitle Banner Text</label>
                        <input type="text" name="header_text" value="<?=e($selectedTemplate['header_text'])?>">
                    </div>
                    <div class="field" style="margin: 0;">
                        <label>Footer Disclosure Notice</label>
                        <input type="text" name="footer_text" value="<?=e($selectedTemplate['footer_text'])?>">
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px;">
                    <div class="field" style="margin: 0;">
                        <label>Authorized Signatory Name (Optional Template Override)</label>
                        <input type="text" name="authorized_signatory_name" value="<?=e($selectedTemplate['authorized_signatory_name'])?>" placeholder="<?=e($currentSignName)?>">
                        <span class="muted" style="font-size: 0.72rem;">Leave empty to inherit global signatory: <?=e($currentSignName)?></span>
                    </div>
                    <div class="field" style="margin: 0;">
                        <label>Authorized Signatory Designation / Title</label>
                        <input type="text" name="authorized_signatory_title" value="<?=e($selectedTemplate['authorized_signatory_title'])?>" placeholder="<?=e($currentSignTitle)?>">
                        <span class="muted" style="font-size: 0.72rem;">Leave empty to inherit global title: <?=e($currentSignTitle)?></span>
                    </div>
                </div>

                <div class="field" style="margin-bottom: 20px;">
                    <label>Document Body / Legal Terms & Conditions Content</label>
                    <textarea name="template_content" rows="10" style="width: 100%; padding: 12px; font-size: 0.85rem; line-height: 1.6;"><?=e($selectedTemplate['template_content'])?></textarea>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 12px;">
                    <button type="submit" class="btn" style="background: var(--primary); padding: 10px 24px; font-weight: 700;">
                        💾 Save Template Changes
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>

</div>

<?php render_end(); ?>
