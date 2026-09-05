<?php 
require_once __DIR__.'/../includes/layout.php';
role('customer');

$p = db();
$user = u();

// Robust Customer Lookup by Email, Mobile, extracted Mobile, or Name
$userEmail = $user['email'] ?? '';
$userName = $user['name'] ?? '';
$mobileFromEmail = str_replace('@customer.local', '', $userEmail);

$s = $p->prepare('
    SELECT * FROM customers 
    WHERE (email != "" AND email = ?) 
       OR (mobile != "" AND (mobile = ? OR mobile = ?)) 
       OR name = ? 
    LIMIT 1
');
$s->execute([$userEmail, $userEmail, $mobileFromEmail, $userName]);
$c = $s->fetch() ?: [];

$reportObj = null;
if (!empty($c['credit_report_json'])) {
    $reportObj = json_decode($c['credit_report_json'], true);
}

start('My Credit Bureau Report');
?>

<div class="card" style="margin-bottom: 24px;">
    <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff;">My Credit Bureau Assessment Report</h3>
    <p class="muted" style="margin-top: 4px;">Equifax / Experian official credit report details linked to your account</p>
</div>

<div class="card">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; align-items: center;">
        <div style="text-align: center; padding: 24px; background: rgba(15,23,42,0.6); border-radius: 14px; border: 1px solid var(--border-color);">
            <div style="font-size: 0.8rem; color: var(--text-muted); font-weight: 700; text-transform: uppercase;">Your Credit Score</div>
            <div style="font-size: 3.2rem; font-weight: 800; color: var(--primary); margin: 8px 0;"><?=!empty($c['credit_score']) ? $c['credit_score'] : 'N/A'?></div>
            <?php
            $score = (int)($c['credit_score'] ?? 0);
            if ($score >= 750) {
                echo '<span class="badge badge-success" style="padding: 6px 14px;">EXCELLENT RATING</span>';
            } elseif ($score >= 700) {
                echo '<span class="badge badge-info" style="padding: 6px 14px;">STANDARD RATING</span>';
            } elseif ($score > 0) {
                echo '<span class="badge badge-warning" style="padding: 6px 14px;">FAIR / LOW RATING</span>';
            } else {
                echo '<span class="badge badge-danger" style="padding: 6px 14px;">NO REPORT STORED</span>';
            }
            ?>
        </div>

        <div>
            <h4 style="color: #fff; font-size: 1.1rem; margin-bottom: 12px; font-weight: 800;">Customer Information</h4>
            <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 6px;"><strong style="color:#fff;">Name:</strong> <?=e(!empty($c['name']) ? $c['name'] : ($user['name'] ?? 'Customer'))?></p>
            <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 6px;"><strong style="color:#fff;">PAN Card:</strong> <?=e(!empty($c['pan']) ? $c['pan'] : 'N/A')?></p>
            <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 6px;"><strong style="color:#fff;">Mobile:</strong> <?=e(!empty($c['mobile']) ? $c['mobile'] : 'N/A')?></p>
            <p style="font-size: 0.9rem; color: var(--text-muted); margin-bottom: 16px;"><strong style="color:#fff;">DOB:</strong> <?=e(!empty($c['dob']) ? $c['dob'] : 'N/A')?></p>

            <?php
            $pdfUrl = null;
            if ($reportObj) {
                $pdfUrl = $reportObj['pdf_url'] ?? ($reportObj['data']['pdf_url'] ?? ($reportObj['data']['report_url'] ?? ($reportObj['report_url'] ?? null)));
            }
            ?>
            <?php if ($pdfUrl): ?>
                <a href="<?=e($pdfUrl)?>" target="_blank" class="btn" style="background: linear-gradient(135deg, var(--secondary), #0d9488); font-size: 0.9rem; padding: 12px 18px;">
                    📥 Open / Print Official PDF Bureau Report
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php render_end(); ?>
