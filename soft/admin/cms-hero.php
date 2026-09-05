<?php
require_once __DIR__ . '/../includes/layout.php';
role('superadmin');

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys = [
        'cms_hero_title', 'cms_hero_subtitle', 'cms_cta_btn_text',
        'cms_phone', 'cms_email', 'cms_whatsapp', 'cms_address', 'cms_hours', 'cms_tan_no'
    ];

    foreach ($keys as $k) {
        if (isset($_POST[$k])) {
            set_setting($k, trim($_POST[$k]));
        }
    }
    $msg = 'Hero & Contact details updated successfully!';
}

start('CMS · Hero & General Contact Info');
?>

<div class="card" style="margin-bottom: 20px;">
    <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 6px;">1. Hero Banner & General Info Section</h3>
    <p class="muted">Edit main banner tagline, primary phone, email, address, and WhatsApp contact displayed on frontend website.</p>
</div>

<?php if ($msg): ?>
    <div style="background: rgba(16,185,129,0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;">
        ✓ <?=$msg?>
    </div>
<?php endif; ?>

<form class="form" method="POST">
    <div class="card" style="margin-bottom: 20px;">
        <h4 style="font-weight: 700; font-size: 0.95rem; margin-bottom: 16px; color: var(--primary);">🌐 Homepage Hero Banner Content</h4>
        <div class="form-grid">
            <div class="field full">
                <label>Hero Main Title / Tagline *</label>
                <input name="cms_hero_title" required value="<?=e(get_setting('cms_hero_title', 'Empowering Your Store Purchases with Affordable Installments'))?>">
            </div>
            <div class="field full">
                <label>Hero Subtitle / Description *</label>
                <textarea name="cms_hero_subtitle" rows="3" required><?=e(get_setting('cms_hero_subtitle', 'Buy your favorite Smartphones, Air Conditioners, Smart TVs & Refrigerators upfront with low down payment and flexible monthly repayments.'))?></textarea>
            </div>
            <div class="field">
                <label>Main CTA Button Text *</label>
                <input name="cms_cta_btn_text" required value="<?=e(get_setting('cms_cta_btn_text', 'Apply For Installment Now'))?>">
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 20px;">
        <h4 style="font-weight: 700; font-size: 0.95rem; margin-bottom: 16px; color: var(--primary);">📞 Contact Information & Location</h4>
        <div class="form-grid">
            <div class="field">
                <label>Helpline Phone Number *</label>
                <input name="cms_phone" required value="<?=e(get_setting('cms_phone', '+91 60005 47615'))?>">
            </div>
            <div class="field">
                <label>Contact Email Address *</label>
                <input name="cms_email" type="email" required value="<?=e(get_setting('cms_email', 'contact@go4fin.com'))?>">
            </div>
            <div class="field">
                <label>WhatsApp Number (With Country Code) *</label>
                <input name="cms_whatsapp" required value="<?=e(get_setting('cms_whatsapp', '916000547615'))?>">
            </div>
            <div class="field">
                <label>Working Hours *</label>
                <input name="cms_hours" required value="<?=e(get_setting('cms_hours', 'Monday – Saturday: 9:00 AM – 7:00 PM'))?>">
            </div>
            <div class="field">
                <label>Govt. Registered TAN Number</label>
                <input name="cms_tan_no" value="<?=e(get_setting('cms_tan_no', 'SHLG03876F'))?>" placeholder="e.g. SHLG03876F">
            </div>
            <div class="field full">
                <label>Registered Office Address *</label>
                <textarea name="cms_address" rows="2" required><?=e(get_setting('cms_address', 'Barpeta Road, Near Attis Academy of Excellence, New Manas Road, Domani Gaon, PO Khairabari, Assam - 781315'))?></textarea>
            </div>
        </div>
    </div>

    <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--primary), #2563eb); font-weight: 800; padding: 12px 24px;">
        💾 Save Hero & Contact Changes
    </button>
</form>

<?php render_end(); ?>
