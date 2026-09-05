<?php
require_once __DIR__ . '/../includes/layout.php';
role('superadmin');

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys = [
        'cms_feat1_title', 'cms_feat1_desc',
        'cms_feat2_title', 'cms_feat2_desc',
        'cms_feat3_title', 'cms_feat3_desc',
        'cms_feat4_title', 'cms_feat4_desc'
    ];

    foreach ($keys as $k) {
        if (isset($_POST[$k])) {
            set_setting($k, trim($_POST[$k]));
        }
    }
    $msg = 'Features & Why Choose Us content updated successfully!';
}

start('CMS · Features & Why Choose Us');
?>

<div class="card" style="margin-bottom: 20px;">
    <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 6px;">4. Why Choose GO4FIN Feature Cards</h3>
    <p class="muted">Edit the 4 main value propositions / feature cards displayed on the homepage and about page.</p>
</div>

<?php if ($msg): ?>
    <div style="background: rgba(16,185,129,0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;">
        ✓ <?=$msg?>
    </div>
<?php endif; ?>

<form class="form" method="POST">
    <div class="card" style="margin-bottom: 20px;">
        <h4 style="font-weight: 700; font-size: 0.95rem; margin-bottom: 16px; color: var(--primary);">⭐ Feature Card 1</h4>
        <div class="form-grid">
            <div class="field full">
                <label>Feature Title *</label>
                <input name="cms_feat1_title" required value="<?=e(get_setting('cms_feat1_title', 'Zero Cash-Lending Model'))?>">
            </div>
            <div class="field full">
                <label>Feature Description *</label>
                <textarea name="cms_feat1_desc" rows="2" required><?=e(get_setting('cms_feat1_desc', '100% store product financing with transparent pricing. We do not provide cash loans, ensuring safe and responsible store credit.'))?></textarea>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 20px;">
        <h4 style="font-weight: 700; font-size: 0.95rem; margin-bottom: 16px; color: var(--primary);">⚡ Feature Card 2</h4>
        <div class="form-grid">
            <div class="field full">
                <label>Feature Title *</label>
                <input name="cms_feat2_title" required value="<?=e(get_setting('cms_feat2_desc', '15-Minute Counter Approval'))?>">
            </div>
            <div class="field full">
                <label>Feature Description *</label>
                <textarea name="cms_feat2_desc" rows="2" required><?=e(get_setting('cms_feat2_desc', 'Get instant credit check & approval right at our partner store counter with digital Aadhaar verification.'))?></textarea>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 20px;">
        <h4 style="font-weight: 700; font-size: 0.95rem; margin-bottom: 16px; color: var(--primary);">💳 Feature Card 3</h4>
        <div class="form-grid">
            <div class="field full">
                <label>Feature Title *</label>
                <input name="cms_feat3_title" required value="<?=e(get_setting('cms_feat3_title', 'Flexible 3 to 12 Month Tenures'))?>">
            </div>
            <div class="field full">
                <label>Feature Description *</label>
                <textarea name="cms_feat3_desc" rows="2" required><?=e(get_setting('cms_feat3_desc', 'Choose monthly EMI plans tailored to your budget with auto-debit bank mandate via eNACH or UPI AutoPay.'))?></textarea>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 20px;">
        <h4 style="font-weight: 700; font-size: 0.95rem; margin-bottom: 16px; color: var(--primary);">📄 Feature Card 4</h4>
        <div class="form-grid">
            <div class="field full">
                <label>Feature Title *</label>
                <input name="cms_feat4_title" required value="<?=e(get_setting('cms_feat4_title', 'Minimal & Paperless Documentation'))?>">
            </div>
            <div class="field full">
                <label>Feature Description *</label>
                <textarea name="cms_feat4_desc" rows="2" required><?=e(get_setting('cms_feat4_desc', 'Just provide PAN Card, Aadhaar Card, Bank account details and witness reference to walk out with your new product.'))?></textarea>
            </div>
        </div>
    </div>

    <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--primary), #2563eb); font-weight: 800; padding: 12px 24px;">
        💾 Save Features Section Changes
    </button>
</form>

<?php render_end(); ?>
