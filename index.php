<?php
$pageTitle = 'Home';
require_once __DIR__ . '/includes/header.php';
?>

<!-- HERO SECTION -->
<section class="hero-section">
    <div>
        <div class="hero-badge-tag">
            <i class="fa-solid fa-shield-halved"></i> Certified Product Finance Partner · <?=e(get_setting('cms_stat_customers', '25,000+'))?> Happy Customers
        </div>
        <h1 class="hero-heading">
            <?=e(get_setting('cms_hero_title', 'Empowering Your Store Purchases with Affordable Installments'))?>
        </h1>
        <p class="hero-subtext">
            <?=e(get_setting('cms_hero_subtitle', 'GO4FIN (Go4 Finance Private Limited) makes latest mobiles, ACs, refrigerators & home electronics affordable with low down payment and flexible monthly repayments.'))?>
        </p>
        <div class="hero-buttons">
            <a href="apply.php" class="btn-cta btn-primary" style="padding: 14px 28px; font-size: 1rem;">
                <i class="fa-solid fa-paper-plane"></i> <?=e(get_setting('cms_cta_btn_text', 'Apply For Installment Now'))?>
            </a>
            <a href="calculator.php" class="btn-cta btn-outline" style="padding: 14px 28px; font-size: 1rem;">
                <i class="fa-solid fa-calculator"></i> Calculate Monthly EMI
            </a>
        </div>
    </div>


    <!-- HERO DISPLAY CARD -->
    <div class="hero-card-box">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <div>
                <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Product Finance Model</span>
                <h3 style="font-size: 1.25rem; font-weight: 800; color: #fff; margin-top: 2px;">Easy Store Purchase Example</h3>
            </div>
            <span class="badge badge-success" style="background: rgba(16,185,129,0.15); color: var(--accent); padding: 6px 12px; border-radius: 20px; font-weight: 700; font-size: 0.8rem;">
                ✓ 24hr Quick Approval
            </span>
        </div>

        <div style="background: rgba(15, 23, 42, 0.8); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 20px; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; font-size: 0.9rem; padding: 8px 0; border-bottom: 1px solid var(--border-color);">
                <span style="color: var(--text-muted);">Selected Product:</span>
                <strong style="color: #fff;">Smart Refrigerator / Mobile</strong>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.9rem; padding: 8px 0; border-bottom: 1px solid var(--border-color);">
                <span style="color: var(--text-muted);">Product Price:</span>
                <strong style="color: #fff;">₹40,000</strong>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.9rem; padding: 8px 0; border-bottom: 1px solid var(--border-color);">
                <span style="color: var(--text-muted);">Store Cash Down Payment:</span>
                <strong style="color: var(--accent);">₹15,000</strong>
            </div>
            <div style="display: flex; justify-content: space-between; font-size: 0.95rem; padding: 10px 0 0 0;">
                <span style="color: var(--text-muted);">Monthly EMI (6 Months):</span>
                <strong style="color: var(--primary); font-size: 1.1rem;">₹4,166 / mo</strong>
            </div>
        </div>

        <a href="apply.php" class="btn-cta btn-primary" style="width: 100%; justify-content: center;">
            <i class="fa-solid fa-bolt"></i> Get Instant Store Eligibility
        </a>
    </div>
</section>

<!-- ACHIEVEMENTS & STATS BAR -->
<div class="stats-bar">
    <div class="stats-grid">
        <div>
            <div class="stat-num">1,000+</div>
            <div class="stat-desc">Happy Borrowers</div>
        </div>
        <div>
            <div class="stat-num">₹1.5 Cr+</div>
            <div class="stat-desc">Products Disbursed</div>
        </div>
        <div>
            <div class="stat-num">24 Hours</div>
            <div class="stat-desc">Fast Paperless Approval</div>
        </div>
        <div>
            <div class="stat-num">100%</div>
            <div class="stat-desc">Transparent & Secure</div>
        </div>
    </div>
</div>

<!-- WHY CHOOSE SECTION -->
<div class="section-wrapper">
    <div class="section-header">
        <h2>Why Choose <span>GO4FIN</span></h2>
        <p>We eliminate cash lending risks and provide direct store product financing with complete transparency.</p>
    </div>

    <div class="products-grid">
        <div class="product-card">
            <div class="product-icon"><i class="fa-solid fa-bolt"></i></div>
            <h3 class="product-title">Instant Approval</h3>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Get instant bureau credit evaluation & store loan approval within 24 hours with minimal paperless KYC.</p>
        </div>

        <div class="product-card">
            <div class="product-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <h3 class="product-title">100% Secure & Bank-Grade</h3>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Encrypted data protection and verified store network ensuring 100% safety of your personal details.</p>
        </div>

        <div class="product-card">
            <div class="product-icon"><i class="fa-solid fa-calendar-check"></i></div>
            <h3 class="product-title">Flexible EMI Tenures</h3>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Select from 3 to 12 months repayment terms tailored to your budget and monthly cashflow.</p>
        </div>

        <div class="product-card">
            <div class="product-icon"><i class="fa-solid fa-headset"></i></div>
            <h3 class="product-title">24/7 Merchant Support</h3>
            <p style="color: var(--text-muted); font-size: 0.9rem;">Our store finance specialists are available online and offline to guide you through every installment.</p>
        </div>
    </div>
</div>

<!-- PRODUCT FINANCING CATEGORIES -->
<div class="section-wrapper" style="padding-top: 0;">
    <div class="section-header">
        <h2>Financed Products <span>We Offer</span></h2>
        <p>Low down payments, minimal processing fees, and zero hidden charges across all electronics categories.</p>
    </div>

    <div class="products-grid">
        <div class="product-card">
            <div class="product-icon"><i class="fa-solid fa-mobile-screen-button"></i></div>
            <h3 class="product-title">Mobile Phone EMI</h3>
            <div class="product-spec"><span>Finance Limit:</span> <strong>Up to ₹25,000</strong></div>
            <div class="product-spec"><span>Brands:</span> <strong>Oppo, Vivo, Samsung, Realme</strong></div>
            <div class="product-spec"><span>Tenure:</span> <strong>Up to 6 Months</strong></div>
            <a href="apply.php" class="btn-cta btn-primary" style="margin-top: 20px; justify-content: center; font-size: 0.85rem;">Apply Now</a>
        </div>

        <div class="product-card">
            <div class="product-icon"><i class="fa-solid fa-snowflake"></i></div>
            <h3 class="product-title">Air Conditioner EMI</h3>
            <div class="product-spec"><span>Finance Limit:</span> <strong>Up to ₹35,000</strong></div>
            <div class="product-spec"><span>Brands:</span> <strong>Voltas, Daikin, LG, Lloyd</strong></div>
            <div class="product-spec"><span>Tenure:</span> <strong>Up to 9 Months</strong></div>
            <a href="apply.php" class="btn-cta btn-primary" style="margin-top: 20px; justify-content: center; font-size: 0.85rem;">Apply Now</a>
        </div>

        <div class="product-card">
            <div class="product-icon"><i class="fa-solid fa-box"></i></div>
            <h3 class="product-title">Refrigerator EMI</h3>
            <div class="product-spec"><span>Finance Limit:</span> <strong>Up to ₹40,000</strong></div>
            <div class="product-spec"><span>Brands:</span> <strong>Samsung, Whirlpool, Godrej</strong></div>
            <div class="product-spec"><span>Tenure:</span> <strong>Up to 12 Months</strong></div>
            <a href="apply.php" class="btn-cta btn-primary" style="margin-top: 20px; justify-content: center; font-size: 0.85rem;">Apply Now</a>
        </div>

        <div class="product-card">
            <div class="product-icon"><i class="fa-solid fa-wind"></i></div>
            <h3 class="product-title">Air Cooler EMI</h3>
            <div class="product-spec"><span>Finance Limit:</span> <strong>Up to ₹20,000</strong></div>
            <div class="product-spec"><span>Brands:</span> <strong>Crompton, Symphony, Bajaj</strong></div>
            <div class="product-spec"><span>Tenure:</span> <strong>Up to 6 Months</strong></div>
            <a href="apply.php" class="btn-cta btn-primary" style="margin-top: 20px; justify-content: center; font-size: 0.85rem;">Apply Now</a>
        </div>
    </div>
</div>

<!-- INTERACTIVE EMI CALCULATOR SECTION -->
<div class="section-wrapper" style="padding-top: 0;">
    <div class="section-header">
        <h2>Interactive <span>EMI Calculator</span></h2>
        <p>Calculate your exact monthly EMI repayment in real-time before visiting your nearest store.</p>
    </div>

    <div class="calc-card">
        <div>
            <h3 style="font-size: 1.25rem; font-weight: 800; color: #fff; margin-bottom: 24px;"><i class="fa-solid fa-calculator" style="color:var(--primary);"></i> EMI Calculator Sliders</h3>
            
            <div class="range-group">
                <label><span>Product Price / Loan Amount:</span> <span id="loanAmtVal" style="color:var(--primary);">₹30,000</span></label>
                <input type="range" id="loanAmt" min="5000" max="100000" step="1000" value="30000">
            </div>

            <div class="range-group">
                <label><span>Annual Interest Rate:</span> <span id="interestRateVal" style="color:var(--secondary);">12% p.a.</span></label>
                <input type="range" id="interestRate" min="0" max="24" step="0.5" value="12">
            </div>

            <div class="range-group">
                <label><span>Loan Tenure (Months):</span> <span id="tenureVal" style="color:var(--accent);">6 Months</span></label>
                <input type="range" id="tenureMonths" min="3" max="24" step="1" value="6">
            </div>
        </div>

        <div class="calc-display">
            <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Your Estimated Monthly EMI</span>
            <div class="calc-emi-num" id="emiResultDisplay">₹5,178</div>
            <span style="font-size: 0.82rem; color: var(--text-muted);">per month</span>

            <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border-color); text-align: left; font-size: 0.85rem;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span style="color: var(--text-muted);">Principal Amount:</span>
                    <strong id="principalDisplay" style="color: #fff;">₹30,000</strong>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 6px;">
                    <span style="color: var(--text-muted);">Total Interest:</span>
                    <strong id="interestDisplay" style="color: var(--warning);">₹1,068</strong>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--text-muted);">Total Payable:</span>
                    <strong id="totalPayableDisplay" style="color: var(--accent);">₹31,068</strong>
                </div>
            </div>

            <a href="apply.php" class="btn-cta btn-primary" style="width: 100%; justify-content: center; margin-top: 20px;">
                <i class="fa-solid fa-paper-plane"></i> Apply for This Plan
            </a>
        </div>
    </div>
</div>

<!-- HOW IT WORKS -->
<div class="section-wrapper" style="padding-top: 0;">
    <div class="section-header">
        <h2>How Store Financing <span>Works</span></h2>
        <p>Get your favorite product home in 4 simple steps.</p>
    </div>

    <div class="steps-grid">
        <div class="step-card">
            <div class="step-badge">1</div>
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 8px;">Select Product & Store</h3>
            <p style="color: var(--text-muted); font-size: 0.88rem;">Visit any GO4FIN partner merchant store or apply online for store eligibility.</p>
        </div>

        <div class="step-card">
            <div class="step-badge">2</div>
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 8px;">Credit & Bureau Check</h3>
            <p style="color: var(--text-muted); font-size: 0.88rem;">Store agent performs paperless KYC & credit score evaluation within 5 minutes.</p>
        </div>

        <div class="step-card">
            <div class="step-badge">3</div>
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 8px;">Pay Cash Down Payment</h3>
            <p style="color: var(--text-muted); font-size: 0.88rem;">Pay initial cash down payment at counter and get application approved instantly.</p>
        </div>

        <div class="step-card">
            <div class="step-badge">4</div>
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff; margin-bottom: 8px;">Take Product & Pay EMIs</h3>
            <p style="color: var(--text-muted); font-size: 0.88rem;">Take home your electronics product and pay monthly EMIs online via PayU or cash.</p>
        </div>
    </div>
</div>

<?php
// Fetch Board of Directors
require_once __DIR__ . '/soft/includes/directors_init.php';
ensureDirectorsTable();

$directorsDb = db();
$siteDirectors = [];
try {
    $dirStmt = $directorsDb->query("SELECT * FROM directors WHERE status = 'active' ORDER BY sort_order ASC, id ASC");
    $siteDirectors = $dirStmt ? $dirStmt->fetchAll() : [];
} catch (Exception $e) {
    $siteDirectors = [];
}
?>

<!-- BOARD OF DIRECTORS & LEADERSHIP SECTION -->
<?php if (!empty($siteDirectors)): ?>
<div class="section-wrapper" style="padding-top: 0;">
    <div class="section-header">
        <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(59, 130, 246, 0.12); border: 1px solid rgba(59, 130, 246, 0.3); padding: 6px 16px; border-radius: 20px; color: #60a5fa; font-size: 0.85rem; font-weight: 700; margin-bottom: 12px;">
            <i class="fa-solid fa-users-gear"></i> Corporate Governance & Leadership
        </div>
        <h2>Board of <span>Directors</span></h2>
        <p>Meet the visionary leaders driving transparent non-cash store financing and retail empowerment across India.</p>
    </div>

    <div class="directors-grid">
        <?php foreach ($siteDirectors as $dir): 
            $photoFile = $dir['photo'] ?? '';
            $uploadDir = realpath(__DIR__ . '/soft/uploads/directors');
            $hasPhoto = !empty($photoFile) && file_exists($uploadDir . '/' . $photoFile);
            $initial = strtoupper(substr($dir['name'], 0, 1));
        ?>
            <div class="director-card">
                <div class="director-photo-wrap">
                    <div class="director-photo-ring"></div>
                    <?php if ($hasPhoto): ?>
                        <img src="<?=url('/uploads/directors/' . $photoFile)?>" alt="<?=e($dir['name'])?>" class="director-photo">
                    <?php else: ?>
                        <div class="director-avatar-fallback">
                            <?=$initial?>
                        </div>
                    <?php endif; ?>
                </div>

                <h3 class="director-name"><?=e($dir['name'])?></h3>
                <span class="director-badge-title"><?=e($dir['designation'])?></span>

                <?php if (!empty($dir['din_no'])): ?>
                    <div class="director-din-tag">
                        <i class="fa-solid fa-id-badge"></i> <?=e($dir['din_no'])?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($dir['bio'])): ?>
                    <p class="director-bio"><?=e($dir['bio'])?></p>
                <?php endif; ?>

                <?php if (!empty($dir['message'])): ?>
                    <div class="director-quote-box">
                        <i class="fa-solid fa-quote-left"></i> <?=e($dir['message'])?>
                    </div>
                <?php endif; ?>

                <div class="director-contact-bar">
                    <?php if (!empty($dir['phone'])): ?>
                        <a href="tel:<?=e($dir['phone'])?>" class="director-contact-btn" title="Call <?=e($dir['name'])?>">
                            <i class="fa-solid fa-phone"></i>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($dir['email'])): ?>
                        <a href="mailto:<?=e($dir['email'])?>" class="director-contact-btn" title="Email <?=e($dir['name'])?>">
                            <i class="fa-solid fa-envelope"></i>
                        </a>
                    <?php endif; ?>
                    <?php if (!empty($dir['linkedin'])): ?>
                        <a href="<?=e($dir['linkedin'])?>" target="_blank" rel="noopener noreferrer" class="director-contact-btn" title="LinkedIn Profile">
                            <i class="fa-brands fa-linkedin-in"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php
$reviewDb = db();
$siteReviews = [];
try {
    $revStmt = $reviewDb->query("SELECT * FROM reviews WHERE status = 'active' ORDER BY sort_order ASC, id DESC LIMIT 6");
    $siteReviews = $revStmt ? $revStmt->fetchAll() : [];
} catch (Exception $e) {
    $siteReviews = [];
}
?>

<!-- CUSTOMER REVIEWS & TESTIMONIALS SECTION -->
<?php if (!empty($siteReviews)): ?>
<div class="section-wrapper" style="padding-top: 0;">
    <div class="section-header">
        <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.3); padding: 6px 16px; border-radius: 20px; color: #f59e0b; font-size: 0.85rem; font-weight: 700; margin-bottom: 12px;">
            <i class="fa-solid fa-star"></i> 4.9/5 Rating from 1,200+ Store Customers · 100% Genuine Reviews
        </div>
        <h2>What Our <span>Customers Say</span></h2>
        <p>Real feedback from customers who financed their smartphones, air conditioners & home appliances with GO4FIN.</p>
    </div>

    <div class="reviews-grid">
        <?php foreach ($siteReviews as $rItem): 
            $initial = strtoupper(substr($rItem['customer_name'] ?? 'C', 0, 1));
            $ratingVal = floatval($rItem['rating'] ?? 5.0);
            $fullStars = floor($ratingVal);
            $hasHalf = ($ratingVal - $fullStars) >= 0.5;
        ?>
            <div class="review-card">
                <i class="fa-solid fa-quote-right review-quote-icon"></i>
                
                <div>
                    <div class="review-header">
                        <div class="review-stars">
                            <?php for ($i = 0; $i < $fullStars; $i++): ?>
                                <i class="fa-solid fa-star"></i>
                            <?php endfor; ?>
                            <?php if ($hasHalf): ?>
                                <i class="fa-solid fa-star-half-stroke"></i>
                            <?php endif; ?>
                            <?php for ($i = ($fullStars + ($hasHalf ? 1 : 0)); $i < 5; $i++): ?>
                                <i class="fa-regular fa-star"></i>
                            <?php endfor; ?>
                        </div>
                        <span class="review-verified-tag">
                            <i class="fa-solid fa-circle-check"></i> Verified Buyer
                        </span>
                    </div>

                    <p class="review-body">
                        "<?=e($rItem['review_text'])?>"
                    </p>
                </div>

                <div class="review-footer">
                    <div class="review-avatar">
                        <?=$initial?>
                    </div>
                    <div class="review-author-info">
                        <div class="review-author-name"><?=e($rItem['customer_name'])?></div>
                        <div class="review-author-meta"><?=e($rItem['customer_role'] ?: 'Verified Customer')?></div>
                        <?php if (!empty($rItem['product_name'])): ?>
                            <div class="review-product-tag">
                                <i class="fa-solid fa-tag"></i> <?=e($rItem['product_name'])?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
