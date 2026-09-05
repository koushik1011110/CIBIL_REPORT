<?php
require_once __DIR__ . '/../includes/layout.php';
role('superadmin');

$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys = [
        'cms_prod_mobile', 'cms_prod_ac', 'cms_prod_tv', 'cms_prod_fridge', 'cms_prod_cooler', 'cms_prod_laptop'
    ];

    foreach ($keys as $k) {
        if (isset($_POST[$k])) {
            set_setting($k, trim($_POST[$k]));
        }
    }
    $msg = 'Featured product categories updated successfully!';
}

start('CMS · Financed Products & Services');
?>

<div class="card" style="margin-bottom: 20px;">
    <h3 style="font-size: 1.15rem; font-weight: 800; margin-bottom: 6px;">3. Featured Financed Products & Categories</h3>
    <p class="muted">Edit descriptions and down payment terms for products featured on the Products & Services page.</p>
</div>

<?php if ($msg): ?>
    <div style="background: rgba(16,185,129,0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;">
        ✓ <?=$msg?>
    </div>
<?php endif; ?>

<form class="form" method="POST">
    <div class="card" style="margin-bottom: 20px;">
        <h4 style="font-weight: 700; font-size: 0.95rem; margin-bottom: 16px; color: var(--primary);">📱 Electronics & Home Appliance Categories</h4>
        <div class="form-grid">
            <div class="field">
                <label>1. Mobile Phone Installment Details *</label>
                <textarea name="cms_prod_mobile" rows="3" required><?=e(get_setting('cms_prod_mobile', 'Latest 5G smartphones from Apple, Samsung, Vivo, Oppo & OnePlus. Down payment starting at ₹3,999 with 3 to 12 months EMI.'))?></textarea>
            </div>
            <div class="field">
                <label>2. Air Conditioner (AC) Installment Details *</label>
                <textarea name="cms_prod_ac" rows="3" required><?=e(get_setting('cms_prod_ac', '1 Ton & 1.5 Ton Inverter ACs from Voltas, Daikin, LG & Blue Star. Low down payment with easy summer cooling EMIs.'))?></textarea>
            </div>
            <div class="field">
                <label>3. Smart LED TV Installment Details *</label>
                <textarea name="cms_prod_tv" rows="3" required><?=e(get_setting('cms_prod_tv', '32" to 65" 4K Smart TVs from Sony, Samsung, LG & Mi. Zero processing fee options available.'))?></textarea>
            </div>
            <div class="field">
                <label>4. Refrigerator Installment Details *</label>
                <textarea name="cms_prod_fridge" rows="3" required><?=e(get_setting('cms_prod_fridge', 'Single door & Double door refrigerators from Whirlpool, Godrej & Samsung.'))?></textarea>
            </div>
            <div class="field">
                <label>5. Air Cooler Installment Details *</label>
                <textarea name="cms_prod_cooler" rows="3" required><?=e(get_setting('cms_prod_cooler', 'Desert & Personal Air Coolers from Symphony & Bajaj with minimal down payment.'))?></textarea>
            </div>
            <div class="field">
                <label>6. Laptop / Computer Installment Details *</label>
                <textarea name="cms_prod_laptop" rows="3" required><?=e(get_setting('cms_prod_laptop', 'Student & Professional laptops from HP, Dell, Lenovo & Asus with easy monthly plans.'))?></textarea>
            </div>
        </div>
    </div>

    <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--primary), #2563eb); font-weight: 800; padding: 12px 24px;">
        💾 Save Product Category Changes
    </button>
</form>

<?php render_end(); ?>
