<?php
require_once __DIR__ . '/../includes/layout.php';
role('superadmin');

$db = db();
$msg = '';
$error = '';

// Handle Delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && !empty($_GET['id'])) {
    $delId = (int)$_GET['id'];
    $delStmt = $db->prepare("DELETE FROM reviews WHERE id = ?");
    $delStmt->execute([$delId]);
    header("Location: " . url('/admin/cms-reviews.php?msg=deleted'));
    exit;
}

// Handle Status Toggle
if (isset($_GET['action']) && $_GET['action'] === 'toggle_status' && !empty($_GET['id'])) {
    $toggleId = (int)$_GET['id'];
    $curr = $db->prepare("SELECT status FROM reviews WHERE id = ?");
    $curr->execute([$toggleId]);
    $currStatus = $curr->fetchColumn();
    $newStatus = ($currStatus === 'active') ? 'inactive' : 'active';
    
    $upStmt = $db->prepare("UPDATE reviews SET status = ? WHERE id = ?");
    $upStmt->execute([$newStatus, $toggleId]);
    header("Location: " . url('/admin/cms-reviews.php?msg=status_updated'));
    exit;
}

// Handle Add / Edit POST
$editId = 0;
$editReview = null;
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $eStmt = $db->prepare("SELECT * FROM reviews WHERE id = ?");
    $eStmt->execute([$editId]);
    $editReview = $eStmt->fetch();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $saveId = (int)($_POST['review_id'] ?? 0);
    $customerName = trim($_POST['customer_name'] ?? '');
    $customerRole = trim($_POST['customer_role'] ?? 'Verified Buyer');
    $productName = trim($_POST['product_name'] ?? 'Mobile Phone Finance');
    $rating = floatval($_POST['rating'] ?? 5.0);
    $reviewText = trim($_POST['review_text'] ?? '');
    $status = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $avatar = trim($_POST['customer_avatar'] ?? '');

    if (empty($customerName) || empty($reviewText)) {
        $error = 'Customer Name and Review Testimonial are required fields.';
    } else {
        if ($saveId > 0) {
            // Update
            $uStmt = $db->prepare("UPDATE reviews SET customer_name = ?, customer_role = ?, product_name = ?, rating = ?, review_text = ?, customer_avatar = ?, status = ?, sort_order = ? WHERE id = ?");
            $uStmt->execute([$customerName, $customerRole, $productName, $rating, $reviewText, $avatar, $status, $sortOrder, $saveId]);
            header("Location: " . url('/admin/cms-reviews.php?msg=updated'));
            exit;
        } else {
            // Insert
            $iStmt = $db->prepare("INSERT INTO reviews (customer_name, customer_role, product_name, rating, review_text, customer_avatar, status, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $iStmt->execute([$customerName, $customerRole, $productName, $rating, $reviewText, $avatar, $status, $sortOrder]);
            header("Location: " . url('/admin/cms-reviews.php?msg=added'));
            exit;
        }
    }
}

// Check URL feedback messages
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'added') $msg = 'New Customer Review added successfully!';
    elseif ($_GET['msg'] === 'updated') $msg = 'Customer Review updated successfully!';
    elseif ($_GET['msg'] === 'deleted') $msg = 'Customer Review deleted successfully!';
    elseif ($_GET['msg'] === 'status_updated') $msg = 'Review publication status updated!';
}

// Fetch stats
$totalReviews = (int)$db->query("SELECT COUNT(*) FROM reviews")->fetchColumn();
$activeReviews = (int)$db->query("SELECT COUNT(*) FROM reviews WHERE status = 'active'")->fetchColumn();
$avgRating = $db->query("SELECT AVG(rating) FROM reviews WHERE status = 'active'")->fetchColumn();
$avgRatingFormatted = $avgRating ? number_format(floatval($avgRating), 1) : '5.0';

// Fetch all reviews
$allReviews = $db->query("SELECT * FROM reviews ORDER BY sort_order ASC, id DESC")->fetchAll();

start('CMS · Customer Reviews & Testimonials');
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h2 style="font-size: 1.3rem; font-weight: 800; color: #fff;">Customer Reviews Management</h2>
        <p class="muted" style="margin-top: 4px;">Manage client testimonials & ratings displayed on the frontend website</p>
    </div>
    <div>
        <?php if ($editReview): ?>
            <a href="<?=url('/admin/cms-reviews.php')?>" class="btn" style="background: rgba(255,255,255,0.08); color: #fff; font-size: 0.85rem; padding: 10px 18px;">
                ← Cancel Editing & Add New
            </a>
        <?php else: ?>
            <a href="#reviewFormBox" class="btn" style="background: linear-gradient(135deg, var(--primary), #2563eb); font-weight: 800; font-size: 0.85rem; padding: 10px 20px;">
                ➕ Add New Review
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- STATS CARDS -->
<div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card">
        <div class="muted">Total Reviews</div>
        <div class="metric" style="color: #fff;"><?=$totalReviews?></div>
        <small class="muted">In Database</small>
    </div>
    <div class="card">
        <div class="muted">Active on Website</div>
        <div class="metric" style="color: var(--success);"><?=$activeReviews?></div>
        <small class="muted">Live on Frontend</small>
    </div>
    <div class="card">
        <div class="muted">Average Rating</div>
        <div class="metric" style="color: #f59e0b;">⭐ <?=$avgRatingFormatted?> / 5.0</div>
        <small class="muted">Customer Satisfaction</small>
    </div>
</div>

<?php if ($msg): ?>
    <div style="background: rgba(16,185,129,0.15); border: 1px solid var(--success); color: var(--success); padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;">
        ✓ <?=e($msg)?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div style="background: rgba(239,68,68,0.15); border: 1px solid var(--danger); color: var(--danger); padding: 14px 18px; border-radius: 10px; margin-bottom: 20px;">
        ⚠️ <?=e($error)?>
    </div>
<?php endif; ?>

<!-- ADD / EDIT REVIEW FORM -->
<div class="card" id="reviewFormBox" style="margin-bottom: 28px; border: 1px solid <?=($editReview ? '#3b82f6' : 'var(--border-color)')?>; background: <?=($editReview ? 'rgba(59,130,246,0.04)' : 'var(--bg-card)')?>;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
        <div>
            <h3 style="font-size: 1.1rem; font-weight: 800; color: #fff;">
                <?=$editReview ? '✏️ Edit Customer Review #' . $editReview['id'] : '➕ Add New Customer Review'?>
            </h3>
            <p class="muted" style="font-size: 0.85rem; margin-top: 2px;">
                <?=$editReview ? 'Update review content, rating, or display status' : 'Add authentic buyer feedback to show on your homepage'?>
            </p>
        </div>
        <?php if ($editReview): ?>
            <span class="badge badge-info" style="font-size: 0.8rem;">Currently Editing ID: <?=$editReview['id']?></span>
        <?php endif; ?>
    </div>

    <form method="POST" action="<?=url('/admin/cms-reviews.php')?>">
        <input type="hidden" name="review_id" value="<?=$editReview['id'] ?? 0?>">

        <div class="form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-bottom: 16px;">
            <div class="field">
                <label style="font-weight: 700; color: #e2e8f0;">Customer Full Name *</label>
                <input type="text" name="customer_name" required placeholder="e.g. Koushik Deka" value="<?=e($editReview['customer_name'] ?? '')?>" style="width: 100%; padding: 10px; border-radius: 8px;">
            </div>

            <div class="field">
                <label style="font-weight: 700; color: #e2e8f0;">Location / Subtitle</label>
                <input type="text" name="customer_role" placeholder="e.g. Barpeta Road, Assam" value="<?=e($editReview['customer_role'] ?? 'Verified Buyer')?>" style="width: 100%; padding: 10px; border-radius: 8px;">
            </div>

            <div class="field">
                <label style="font-weight: 700; color: #e2e8f0;">Financed Product</label>
                <input type="text" name="product_name" placeholder="e.g. Apple iPhone 18 / Voltas Split AC" value="<?=e($editReview['product_name'] ?? 'Mobile Phone Finance')?>" style="width: 100%; padding: 10px; border-radius: 8px;">
            </div>

            <div class="field">
                <label style="font-weight: 700; color: #e2e8f0;">Star Rating *</label>
                <select name="rating" required style="width: 100%; padding: 10px; border-radius: 8px; background: #0f172a; color: #f59e0b; font-weight: 700;">
                    <?php 
                    $selectedRating = floatval($editReview['rating'] ?? 5.0);
                    $ratingsList = [
                        '5.0' => '⭐⭐⭐⭐⭐ 5.0 (Excellent)',
                        '4.8' => '⭐⭐⭐⭐⭐ 4.8 (Superb)',
                        '4.5' => '⭐⭐⭐⭐½ 4.5 (Very Good)',
                        '4.0' => '⭐⭐⭐⭐☆ 4.0 (Good)',
                        '3.5' => '⭐⭐⭐½☆ 3.5 (Average)',
                        '3.0' => '⭐⭐⭐☆☆ 3.0 (Satisfactory)'
                    ];
                    foreach ($ratingsList as $rVal => $rLabel): ?>
                        <option value="<?=$rVal?>" <?=abs($selectedRating - floatval($rVal)) < 0.05 ? 'selected' : ''?>>
                            <?=$rLabel?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label style="font-weight: 700; color: #e2e8f0;">Display Status</label>
                <select name="status" style="width: 100%; padding: 10px; border-radius: 8px; background: #0f172a; color: #fff;">
                    <option value="active" <?=($editReview['status'] ?? 'active') === 'active' ? 'selected' : ''?>>🟢 Active (Show on Website)</option>
                    <option value="inactive" <?=($editReview['status'] ?? '') === 'inactive' ? 'selected' : ''?>>⚪ Inactive (Hide)</option>
                </select>
            </div>

            <div class="field">
                <label style="font-weight: 700; color: #e2e8f0;">Sort Priority Order</label>
                <input type="number" name="sort_order" value="<?=intval($editReview['sort_order'] ?? 0)?>" style="width: 100%; padding: 10px; border-radius: 8px;" title="Lower numbers appear first">
            </div>
        </div>

        <div class="field" style="margin-bottom: 20px;">
            <label style="font-weight: 700; color: #e2e8f0;">Review Testimonial Content *</label>
            <textarea name="review_text" rows="3" required placeholder="Write customer feedback, experience with store finance, EMI process, etc." style="width: 100%; padding: 12px; border-radius: 8px; font-size: 0.9rem; line-height: 1.5;"><?=e($editReview['review_text'] ?? '')?></textarea>
        </div>

        <div style="display: flex; gap: 12px; align-items: center;">
            <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--primary), #2563eb); font-weight: 800; padding: 12px 28px; border-radius: 8px;">
                <?=$editReview ? '💾 Save & Update Review' : '➕ Publish New Review'?>
            </button>
            <?php if ($editReview): ?>
                <a href="<?=url('/admin/cms-reviews.php')?>" class="btn" style="background: rgba(255,255,255,0.08); color: #fff; padding: 12px 20px; border-radius: 8px;">
                    Cancel
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- REVIEWS DATA TABLE -->
<div class="card" style="padding: 0; overflow-x: auto;">
    <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
        <h3 style="font-size: 1.05rem; font-weight: 800; color: #fff;">Existing Reviews (<?=count($allReviews)?>)</h3>
        <span class="muted" style="font-size: 0.8rem;">Live sorted by display order</span>
    </div>

    <table class="table" style="width: 100%; border-collapse: collapse; font-size: 0.88rem;">
        <thead>
            <tr style="background: rgba(15,23,42,0.6); color: var(--text-muted); text-align: left;">
                <th style="padding: 12px 16px; width: 60px;">#ID</th>
                <th style="padding: 12px 16px;">Customer</th>
                <th style="padding: 12px 16px;">Product</th>
                <th style="padding: 12px 16px; width: 120px;">Rating</th>
                <th style="padding: 12px 16px;">Review Content</th>
                <th style="padding: 12px 16px; width: 100px;">Status</th>
                <th style="padding: 12px 16px; width: 150px; text-align: right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($allReviews)): ?>
                <tr>
                    <td colspan="7" style="padding: 30px; text-align: center; color: var(--text-muted);">
                        No reviews found in database. Use the form above to add your first customer review.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($allReviews as $rev): 
                    $initial = strtoupper(substr($rev['customer_name'] ?? 'C', 0, 1));
                    $isActive = ($rev['status'] === 'active');
                ?>
                    <tr style="border-bottom: 1px solid var(--border-color); vertical-align: top;">
                        <td style="padding: 14px 16px; font-weight: 700; color: var(--text-muted);">
                            #<?=$rev['id']?><br>
                            <span style="font-size: 0.72rem; color: #64748b;">Ord: <?=$rev['sort_order']?></span>
                        </td>
                        <td style="padding: 14px 16px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #3b82f6, #8b5cf6); color: #fff; font-weight: 800; display: flex; align-items: center; justify-content: center; font-size: 0.85rem; flex-shrink: 0;">
                                    <?=$initial?>
                                </div>
                                <div>
                                    <strong style="color: #fff; font-size: 0.92rem;"><?=e($rev['customer_name'])?></strong><br>
                                    <span style="font-size: 0.78rem; color: #94a3b8;"><?=e($rev['customer_role'] ?: 'Verified Buyer')?></span>
                                </div>
                            </div>
                        </td>
                        <td style="padding: 14px 16px;">
                            <span style="font-size: 0.85rem; color: #cbd5e1; font-weight: 600;">
                                <?=e($rev['product_name'] ?: 'Product Finance')?>
                            </span>
                        </td>
                        <td style="padding: 14px 16px; white-space: nowrap;">
                            <div style="color: #f59e0b; font-size: 0.88rem; font-weight: 700;">
                                <?php
                                $fullStars = floor($rev['rating']);
                                $halfStar = ($rev['rating'] - $fullStars) >= 0.5 ? 1 : 0;
                                for ($i = 0; $i < $fullStars; $i++) echo '★';
                                if ($halfStar) echo '½';
                                ?>
                            </div>
                            <span style="font-size: 0.75rem; color: #94a3b8;"><?=number_format($rev['rating'], 1)?> / 5.0</span>
                        </td>
                        <td style="padding: 14px 16px; max-width: 320px;">
                            <p style="margin: 0; color: #cbd5e1; font-size: 0.85rem; line-height: 1.5; font-style: italic;">
                                "<?=e($rev['review_text'])?>"
                            </p>
                        </td>
                        <td style="padding: 14px 16px;">
                            <?php if ($isActive): ?>
                                <span class="badge badge-success" style="font-size: 0.75rem; padding: 4px 10px;">🟢 Active</span>
                            <?php else: ?>
                                <span class="badge badge-warning" style="font-size: 0.75rem; padding: 4px 10px; background: rgba(148,163,184,0.2); color: #94a3b8;">⚪ Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 14px 16px; text-align: right;">
                            <div style="display: flex; gap: 6px; justify-content: flex-end; flex-wrap: wrap;">
                                <a href="<?=url('/admin/cms-reviews.php?edit='.$rev['id'])?>" class="btn" style="padding: 5px 10px; font-size: 0.75rem; background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.4);" title="Edit Review">
                                    ✏️ Edit
                                </a>
                                <a href="<?=url('/admin/cms-reviews.php?action=toggle_status&id='.$rev['id'])?>" class="btn" style="padding: 5px 10px; font-size: 0.75rem; background: rgba(255,255,255,0.06); color: #cbd5e1; border: 1px solid var(--border-color);" title="Toggle Status">
                                    <?=$isActive ? 'Hide' : 'Show'?>
                                </a>
                                <a href="<?=url('/admin/cms-reviews.php?action=delete&id='.$rev['id'])?>" class="btn" style="padding: 5px 10px; font-size: 0.75rem; background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.4);" onclick="return confirm('Are you sure you want to permanently delete this customer review?')" title="Delete Review">
                                    🗑️
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php render_end(); ?>
