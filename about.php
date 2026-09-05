<?php
$pageTitle = 'About Us';
require_once __DIR__ . '/includes/header.php';
?>

<div class="section-wrapper" style="padding-top: 130px;">
    <div class="section-header">
        <h2>About <span>Go4 Finance Private Limited</span></h2>
        <p>Your trusted partner for easy product financing, store installment solutions, and paperless EMI approvals.</p>
    </div>

    <div style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 40px; margin-bottom: 50px;">
        <h3 style="font-size: 1.4rem; font-weight: 800; color: #fff; margin-bottom: 16px;">Our Business Model — Zero Cash Lending</h3>
        <p style="color: var(--text-muted); font-size: 1rem; line-height: 1.8; margin-bottom: 20px;">
            We do not provide cash loans. We make electronic products, smartphones, air conditioners, refrigerators, and home appliances affordable through store monthly installment purchases.
        </p>
        <div style="background: rgba(59, 130, 246, 0.1); border-left: 4px solid var(--primary); padding: 18px; border-radius: 8px; color: #fff; font-size: 0.95rem;">
            <strong>Example:</strong> A customer buys a refrigerator for ₹40,000, pays ₹15,000 upfront cash down payment at counter, and pays the remaining ₹25,000 in convenient monthly installments.<br><br>
            Simply put: We sell the product first, and the customer pays the remaining purchase amount over time. It’s product sales on installments, not cash lending!
        </div>
    </div>

    <!-- BOARD OF DIRECTORS & LEADERSHIP -->
    <?php
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

    <?php if (!empty($siteDirectors)): ?>
    <div class="section-header" style="margin-bottom: 30px;">
        <div style="display: inline-flex; align-items: center; gap: 8px; background: rgba(59, 130, 246, 0.12); border: 1px solid rgba(59, 130, 246, 0.3); padding: 6px 16px; border-radius: 20px; color: #60a5fa; font-size: 0.85rem; font-weight: 700; margin-bottom: 12px;">
            <i class="fa-solid fa-users-gear"></i> Corporate Governance & Leadership
        </div>
        <h2>Board of <span>Directors</span></h2>
        <p>Experienced leadership dedicated to simplifying retail store credit and installment purchases.</p>
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
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
