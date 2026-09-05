<?php
$pageTitle = 'Apply';
require_once __DIR__ . '/includes/header.php';

$msg = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $mobile  = trim($_POST['mobile'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $product = trim($_POST['product'] ?? '');
    $price   = floatval($_POST['price'] ?? 0);
    $dp      = floatval($_POST['down_payment'] ?? 0);
    $tenure  = intval($_POST['tenure'] ?? 6);

    if (!empty($name) && !empty($mobile)) {
        $p = db();
        
        // 1. Insert into website_leads table
        $leadStmt = $p->prepare("INSERT INTO website_leads (name, mobile, email, product, price, down_payment, tenure, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'new')");
        $leadStmt->execute([$name, $mobile, $email, $product, $price, $dp, $tenure]);
        
        // 2. Ensure customer record exists
        $stmt = $p->prepare("INSERT INTO customers (name, mobile, email, status) VALUES (?, ?, ?, 'active') ON DUPLICATE KEY UPDATE name=VALUES(name)");
        $stmt->execute([$name, $mobile, $email]);
        
        $msg = 'success';
    } else {
        $msg = 'error';
    }

}
?>

<div class="section-wrapper" style="padding-top: 130px;">
    <div class="section-header">
        <h2>Apply for Product <span>Installment</span></h2>
        <p>Fill out the simple application form below to check store eligibility & book your product installment.</p>
    </div>

    <div style="max-width: 680px; margin: 0 auto;">
        <?php if($msg === 'success'): ?>
            <div style="background: rgba(16, 185, 129, 0.15); border: 1px solid var(--accent); color: var(--accent); padding: 18px 22px; border-radius: var(--radius-md); margin-bottom: 24px; text-align: center;">
                <i class="fa-solid fa-circle-check" style="font-size: 1.5rem; margin-bottom: 8px; display: block;"></i>
                <strong style="font-size: 1.1rem;">Application Submitted Successfully!</strong><br>
                Our store finance manager will contact you within 24 hours. You can also visit our nearest store for instant counter cash approval.
            </div>
        <?php elseif($msg === 'error'): ?>
            <div style="background: rgba(239, 68, 68, 0.15); border: 1px solid var(--danger); color: var(--danger); padding: 14px 18px; border-radius: var(--radius-md); margin-bottom: 24px; text-align: center;">
                Please provide your Name and Mobile Number.
            </div>
        <?php endif; ?>

        <form method="post" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 36px; box-shadow: 0 20px 40px rgba(0,0,0,0.4);">
            <div style="background: rgba(59, 130, 246, 0.12); border-left: 4px solid var(--primary); padding: 14px 18px; border-radius: var(--radius-sm); margin-bottom: 24px; color: #fff; font-size: 0.9rem; display: flex; align-items: center; gap: 12px;">
                <i class="fa-solid fa-headset" style="color: var(--primary); font-size: 1.3rem;"></i>
                <div>
                    <strong style="color: var(--primary); font-size: 0.95rem; display: block;">Quick Response Guaranteed</strong>
                    <span>After submitting your application, our finance team will contact you shortly.</span>
                </div>
            </div>

            <div style="margin-bottom: 18px;">

                <label style="display: block; font-weight: 700; font-size: 0.88rem; color: #fff; margin-bottom: 6px;">Full Name *</label>
                <input name="name" required placeholder="e.g. Rahul Sharma" style="width: 100%; padding: 12px; background: rgba(15,23,42,0.8); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: #fff;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px;">
                <div>
                    <label style="display: block; font-weight: 700; font-size: 0.88rem; color: #fff; margin-bottom: 6px;">Mobile Number *</label>
                    <input name="mobile" required placeholder="e.g. 9876543210" style="width: 100%; padding: 12px; background: rgba(15,23,42,0.8); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: #fff;">
                </div>
                <div>
                    <label style="display: block; font-weight: 700; font-size: 0.88rem; color: #fff; margin-bottom: 6px;">Email Address</label>
                    <input name="email" type="email" placeholder="customer@example.com" style="width: 100%; padding: 12px; background: rgba(15,23,42,0.8); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: #fff;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px;">
                <div>
                    <label style="display: block; font-weight: 700; font-size: 0.88rem; color: #fff; margin-bottom: 6px;">Product Category *</label>
                    <select name="product" required style="width: 100%; padding: 12px; background: rgba(15,23,42,0.8); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: #fff;">
                        <option value="Mobile Phone">Mobile Phone</option>
                        <option value="Air Conditioner">Air Conditioner (AC)</option>
                        <option value="Smart LED TV">Smart LED TV</option>
                        <option value="Refrigerator">Refrigerator</option>
                        <option value="Air Cooler">Air Cooler</option>
                        <option value="Laptop / Computer">Laptop / Computer</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-weight: 700; font-size: 0.88rem; color: #fff; margin-bottom: 6px;">Product Approx Price (₹)</label>
                    <input name="price" type="number" value="30000" style="width: 100%; padding: 12px; background: rgba(15,23,42,0.8); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: #fff;">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 24px;">
                <div>
                    <label style="display: block; font-weight: 700; font-size: 0.88rem; color: #fff; margin-bottom: 6px;">Expected Down Payment Cash (₹)</label>
                    <input name="down_payment" type="number" value="8000" style="width: 100%; padding: 12px; background: rgba(15,23,42,0.8); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: #fff;">
                </div>
                <div>
                    <label style="display: block; font-weight: 700; font-size: 0.88rem; color: #fff; margin-bottom: 6px;">Preferred Tenure</label>
                    <select name="tenure" style="width: 100%; padding: 12px; background: rgba(15,23,42,0.8); border: 1px solid var(--border-color); border-radius: var(--radius-sm); color: #fff;">
                        <option value="3">3 Months</option>
                        <option value="6" selected>6 Months</option>
                        <option value="9">9 Months</option>
                        <option value="12">12 Months</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn-cta btn-primary" style="width: 100%; justify-content: center; padding: 14px; font-size: 1rem;">
                <i class="fa-solid fa-paper-plane"></i> Submit Application Request
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
