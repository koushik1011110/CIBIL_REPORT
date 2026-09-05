<?php
require_once __DIR__ . '/../includes/layout.php';
role('superadmin');

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys = [
        'cms_about_heading', 'cms_about_text', 'cms_vision', 'cms_mission',
        'cms_ceo_name', 'cms_ceo_title',
        'cms_stat_customers', 'cms_stat_stores', 'cms_stat_approval'
    ];

    foreach ($keys as $k) {
        if (isset($_POST[$k])) {
            set_setting($k, trim($_POST[$k]));
        }
    }
    $msg = 'About section content updated successfully!';
}

start('CMS · About Company & Stats');
?>

<div class="card" style="margin-bottom: 20px;">
    <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 6px;">2. About Company & Statistics Section</h3>
    <p class="muted">Edit company story, vision, founder details, and key statistics cards displayed on the About page.</p>
</div>

<?php if ($msg): ?>
    <div style="background: rgba(16,185,129,0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;">
        ✓ <?=$msg?>
    </div>
<?php endif; ?>

<form class="form" method="POST">
    <div class="card" style="margin-bottom: 20px;">
        <h4 style="font-weight: 700; font-size: 0.95rem; margin-bottom: 16px; color: var(--primary);">🏢 Company Story & Vision</h4>
        <div class="form-grid">
            <div class="field full">
                <label>About Heading *</label>
                <input name="cms_about_heading" required value="<?=e(get_setting('cms_about_heading', 'About Go4 Finance Private Limited'))?>">
            </div>
            <div class="field full">
                <label>Company Description & Overview *</label>
                <textarea name="cms_about_text" rows="4" required><?=e(get_setting('cms_about_text', 'Go4 Finance Private Limited is a technology-driven consumer durable financing company based in Assam, India. We empower retail customers to purchase essential home appliances and mobile phones with zero cash-lending model and transparent monthly installments.'))?></textarea>
            </div>
            <div class="field">
                <label>Company Vision Statement *</label>
                <textarea name="cms_vision" rows="3" required><?=e(get_setting('cms_vision', 'To make high-quality lifestyle appliances accessible and affordable to every Indian household through transparent non-cash store financing.'))?></textarea>
            </div>
            <div class="field">
                <label>Company Mission Statement *</label>
                <textarea name="cms_mission" rows="3" required><?=e(get_setting('cms_mission', 'To partner with top retail electronic stores, streamline Aadhaar/CIBIL credit checks, and deliver seamless 15-minute counter approvals.'))?></textarea>
            </div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 20px;">
        <h4 style="font-weight: 700; font-size: 0.95rem; margin-bottom: 16px; color: var(--primary);">👤 Leadership & Key Statistics</h4>
        <div class="form-grid">
            <div class="field">
                <label>Founder / CEO Name *</label>
                <input name="cms_ceo_name" required value="<?=e(get_setting('cms_ceo_name', 'Wazid Hoque'))?>">
            </div>
            <div class="field">
                <label>Designation *</label>
                <input name="cms_ceo_title" required value="<?=e(get_setting('cms_ceo_title', 'Founder & Chief Executive Officer'))?>">
            </div>
            <div class="field">
                <label>Stat 1: Happy Customers *</label>
                <input name="cms_stat_customers" required value="<?=e(get_setting('cms_stat_customers', '25,000+'))?>">
            </div>
            <div class="field">
                <label>Stat 2: Partner Stores *</label>
                <input name="cms_stat_stores" required value="<?=e(get_setting('cms_stat_stores', '150+ Retail Shops'))?>">
            </div>
            <div class="field">
                <label>Stat 3: Instant Approval Rate *</label>
                <input name="cms_stat_approval" required value="<?=e(get_setting('cms_stat_approval', '98% Instant Approval'))?>">
            </div>
        </div>
    </div>

    <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--primary), #2563eb); font-weight: 800; padding: 12px 24px;">
        💾 Save About Section Changes
    </button>
</form>

<?php render_end(); ?>
