<?php
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/directors_init.php';
role('superadmin');

ensureDirectorsTable();
$db = db();
$msg = '';
$error = '';
$uploadDir = realpath(__DIR__ . '/../uploads/directors') ?: (__DIR__ . '/../uploads/directors');

// Handle Delete
if (isset($_GET['action']) && $_GET['action'] === 'delete' && !empty($_GET['id'])) {
    $delId = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT photo FROM directors WHERE id = ?");
    $stmt->execute([$delId]);
    $oldPhoto = $stmt->fetchColumn();

    if ($oldPhoto && file_exists($uploadDir . '/' . $oldPhoto)) {
        @unlink($uploadDir . '/' . $oldPhoto);
    }

    $delStmt = $db->prepare("DELETE FROM directors WHERE id = ?");
    $delStmt->execute([$delId]);
    header("Location: " . url('/admin/cms-directors.php?msg=deleted'));
    exit;
}

// Handle Status Toggle
if (isset($_GET['action']) && $_GET['action'] === 'toggle_status' && !empty($_GET['id'])) {
    $toggleId = (int)$_GET['id'];
    $curr = $db->prepare("SELECT status FROM directors WHERE id = ?");
    $curr->execute([$toggleId]);
    $currStatus = $curr->fetchColumn();
    $newStatus = ($currStatus === 'active') ? 'inactive' : 'active';
    
    $upStmt = $db->prepare("UPDATE directors SET status = ? WHERE id = ?");
    $upStmt->execute([$newStatus, $toggleId]);
    header("Location: " . url('/admin/cms-directors.php?msg=status_updated'));
    exit;
}

// Handle Remove Photo Only
if (isset($_GET['action']) && $_GET['action'] === 'remove_photo' && !empty($_GET['id'])) {
    $remId = (int)$_GET['id'];
    $stmt = $db->prepare("SELECT photo FROM directors WHERE id = ?");
    $stmt->execute([$remId]);
    $oldPhoto = $stmt->fetchColumn();

    if ($oldPhoto && file_exists($uploadDir . '/' . $oldPhoto)) {
        @unlink($uploadDir . '/' . $oldPhoto);
    }

    $upStmt = $db->prepare("UPDATE directors SET photo = NULL WHERE id = ?");
    $upStmt->execute([$remId]);
    header("Location: " . url('/admin/cms-directors.php?edit=' . $remId . '&msg=photo_removed'));
    exit;
}

// Handle Add / Edit POST
$editId = 0;
$editDirector = null;
if (isset($_GET['edit']) && !empty($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $eStmt = $db->prepare("SELECT * FROM directors WHERE id = ?");
    $eStmt->execute([$editId]);
    $editDirector = $eStmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $saveId      = (int)($_POST['director_id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $designation = trim($_POST['designation'] ?? 'Director');
    $dinNo       = trim($_POST['din_no'] ?? '');
    $bio         = trim($_POST['bio'] ?? '');
    $message     = trim($_POST['message'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $linkedin    = trim($_POST['linkedin'] ?? '');
    $status      = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';
    $sortOrder   = (int)($_POST['sort_order'] ?? 0);

    if (empty($name) || empty($designation)) {
        $error = 'Director Name and Designation are required fields.';
    } else {
        $photoName = null;
        if ($saveId > 0) {
            $existing = $db->prepare("SELECT photo FROM directors WHERE id = ?");
            $existing->execute([$saveId]);
            $photoName = $existing->fetchColumn();
        }

        // Handle Photo Upload
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $fileTmp  = $_FILES['photo']['tmp_name'];
            $fileName = $_FILES['photo']['name'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'webp'];

            if (!in_array($ext, $allowed)) {
                $error = 'Invalid image format. Allowed formats: JPG, PNG, WEBP.';
            } else {
                if (!file_exists($uploadDir)) {
                    @mkdir($uploadDir, 0777, true);
                }

                $newPhotoName = 'director_' . time() . '_' . rand(100, 999) . '.' . $ext;
                $targetPath = $uploadDir . '/' . $newPhotoName;

                if (move_uploaded_file($fileTmp, $targetPath)) {
                    // Remove previous photo if replaced
                    if ($photoName && file_exists($uploadDir . '/' . $photoName)) {
                        @unlink($uploadDir . '/' . $photoName);
                    }
                    $photoName = $newPhotoName;
                } else {
                    $error = 'Failed to upload photo file. Please check folder permissions.';
                }
            }
        }

        if (empty($error)) {
            if ($saveId > 0) {
                // Update
                $uStmt = $db->prepare("UPDATE directors SET 
                    name = ?, designation = ?, photo = ?, bio = ?, message = ?, 
                    din_no = ?, phone = ?, email = ?, linkedin = ?, status = ?, sort_order = ? 
                    WHERE id = ?");
                $uStmt->execute([
                    $name, $designation, $photoName, $bio, $message,
                    $dinNo, $phone, $email, $linkedin, $status, $sortOrder, $saveId
                ]);
                header("Location: " . url('/admin/cms-directors.php?msg=updated'));
                exit;
            } else {
                // Insert
                $iStmt = $db->prepare("INSERT INTO directors 
                    (name, designation, photo, bio, message, din_no, phone, email, linkedin, status, sort_order) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $iStmt->execute([
                    $name, $designation, $photoName, $bio, $message,
                    $dinNo, $phone, $email, $linkedin, $status, $sortOrder
                ]);
                header("Location: " . url('/admin/cms-directors.php?msg=added'));
                exit;
            }
        }
    }
}

// URL Feedback Messages
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'added') $msg = 'New Director profile created successfully!';
    elseif ($_GET['msg'] === 'updated') $msg = 'Director profile updated successfully!';
    elseif ($_GET['msg'] === 'deleted') $msg = 'Director profile deleted successfully!';
    elseif ($_GET['msg'] === 'status_updated') $msg = 'Director publication status updated!';
    elseif ($_GET['msg'] === 'photo_removed') $msg = 'Director photo removed successfully.';
}

// Fetch Metrics
$totalDirectors = (int)$db->query("SELECT COUNT(*) FROM directors")->fetchColumn();
$activeDirectors = (int)$db->query("SELECT COUNT(*) FROM directors WHERE status = 'active'")->fetchColumn();
$photosUploaded = (int)$db->query("SELECT COUNT(*) FROM directors WHERE photo IS NOT NULL AND photo != ''")->fetchColumn();

// Fetch All Directors
$allDirectors = $db->query("SELECT * FROM directors ORDER BY sort_order ASC, id ASC")->fetchAll();

start('CMS · Board of Directors & Leadership');
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
    <div>
        <h2 style="font-size: 1.35rem; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 10px;">
            <i data-lucide="users" style="color: var(--primary);"></i> Board of Directors Management
        </h2>
        <p class="muted" style="margin-top: 4px;">Upload director photographs, designation titles, and bio messages for frontend website display</p>
    </div>
    <div>
        <?php if ($editDirector): ?>
            <a href="<?=url('/admin/cms-directors.php')?>" class="btn" style="background: rgba(255,255,255,0.08); color: #fff; font-size: 0.85rem; padding: 10px 18px;">
                ← Cancel Editing & Add New
            </a>
        <?php else: ?>
            <a href="#directorFormCard" class="btn" style="background: linear-gradient(135deg, var(--primary), #2563eb); font-weight: 700; font-size: 0.85rem; padding: 10px 18px;">
                <i data-lucide="user-plus"></i> Add New Director
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- METRIC STATS -->
<div class="metrics-grid" style="grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); margin-bottom: 24px;">
    <div class="metric-card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 18px;">
        <div class="muted" style="font-size: 0.82rem; font-weight: 600;">Total Board Members</div>
        <div class="metric" style="color: #fff; font-size: 1.8rem; font-weight: 800; margin-top: 4px;"><?=$totalDirectors?></div>
        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">Registered in system</div>
    </div>
    <div class="metric-card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 18px;">
        <div class="muted" style="font-size: 0.82rem; font-weight: 600;">Live on Website</div>
        <div class="metric" style="color: var(--success, #10b981); font-size: 1.8rem; font-weight: 800; margin-top: 4px;"><?=$activeDirectors?></div>
        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">Active publication status</div>
    </div>
    <div class="metric-card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 18px;">
        <div class="muted" style="font-size: 0.82rem; font-weight: 600;">Photos Uploaded</div>
        <div class="metric" style="color: #60a5fa; font-size: 1.8rem; font-weight: 800; margin-top: 4px;"><?=$photosUploaded?> / <?=$totalDirectors?></div>
        <div style="font-size: 0.75rem; color: var(--text-muted); margin-top: 4px;">Custom portrait images</div>
    </div>
</div>

<?php if ($msg): ?>
    <div style="background: rgba(16,185,129,0.15); border: 1px solid var(--success, #10b981); color: var(--success, #10b981); padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: 600;">
        ✓ <?=$msg?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div style="background: rgba(239,68,68,0.15); border: 1px solid var(--danger, #ef4444); color: var(--danger, #ef4444); padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; font-weight: 600;">
        ✕ <?=$error?>
    </div>
<?php endif; ?>

<!-- ADD / EDIT DIRECTOR FORM -->
<div class="card" id="directorFormCard" style="margin-bottom: 30px; border: 1px solid rgba(59,130,246,0.3); background: var(--bg-card); box-shadow: 0 10px 25px rgba(0,0,0,0.2);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; border-bottom: 1px solid var(--border-color); padding-bottom: 14px;">
        <h3 style="font-size: 1.15rem; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="<?=$editDirector ? 'edit-3' : 'user-plus'?>" style="color: var(--primary);"></i>
            <?=$editDirector ? 'Edit Director: ' . htmlspecialchars($editDirector['name']) : 'Add New Board Director'?>
        </h3>
        <?php if ($editDirector): ?>
            <span style="background: rgba(59,130,246,0.2); color: #60a5fa; padding: 4px 10px; border-radius: 6px; font-size: 0.78rem; font-weight: 700;">
                Editing ID #<?=$editDirector['id']?>
            </span>
        <?php endif; ?>
    </div>

    <form method="POST" action="<?=url('/admin/cms-directors.php' . ($editDirector ? '?edit='.$editDirector['id'] : ''))?>" enctype="multipart/form-data">
        <input type="hidden" name="director_id" value="<?=$editDirector['id'] ?? 0?>">

        <div class="form-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px;">
            <!-- Name -->
            <div class="field">
                <label style="font-size: 0.85rem; font-weight: 700; margin-bottom: 6px; display: block;">Full Name *</label>
                <input type="text" name="name" required value="<?=e($editDirector['name'] ?? '')?>" placeholder="e.g. Wazid Hoque" style="width: 100%; padding: 10px 14px; border-radius: 8px;">
            </div>

            <!-- Designation -->
            <div class="field">
                <label style="font-size: 0.85rem; font-weight: 700; margin-bottom: 6px; display: block;">Designation / Title *</label>
                <input type="text" name="designation" required value="<?=e($editDirector['designation'] ?? 'Director')?>" placeholder="e.g. Managing Director & CEO" style="width: 100%; padding: 10px 14px; border-radius: 8px;">
            </div>

            <!-- DIN No -->
            <div class="field">
                <label style="font-size: 0.85rem; font-weight: 700; margin-bottom: 6px; display: block;">DIN Number (Director ID No - Optional)</label>
                <input type="text" name="din_no" value="<?=e($editDirector['din_no'] ?? '')?>" placeholder="e.g. DIN: 09876543" style="width: 100%; padding: 10px 14px; border-radius: 8px;">
            </div>

            <!-- Phone -->
            <div class="field">
                <label style="font-size: 0.85rem; font-weight: 700; margin-bottom: 6px; display: block;">Contact Phone (Optional)</label>
                <input type="text" name="phone" value="<?=e($editDirector['phone'] ?? '')?>" placeholder="e.g. +91 60005 47615" style="width: 100%; padding: 10px 14px; border-radius: 8px;">
            </div>

            <!-- Email -->
            <div class="field">
                <label style="font-size: 0.85rem; font-weight: 700; margin-bottom: 6px; display: block;">Official Email (Optional)</label>
                <input type="email" name="email" value="<?=e($editDirector['email'] ?? '')?>" placeholder="e.g. director@go4fin.com" style="width: 100%; padding: 10px 14px; border-radius: 8px;">
            </div>

            <!-- LinkedIn -->
            <div class="field">
                <label style="font-size: 0.85rem; font-weight: 700; margin-bottom: 6px; display: block;">LinkedIn Profile URL (Optional)</label>
                <input type="url" name="linkedin" value="<?=e($editDirector['linkedin'] ?? '')?>" placeholder="e.g. https://linkedin.com/in/director" style="width: 100%; padding: 10px 14px; border-radius: 8px;">
            </div>

            <!-- Status -->
            <div class="field">
                <label style="font-size: 0.85rem; font-weight: 700; margin-bottom: 6px; display: block;">Website Publication Status *</label>
                <select name="status" style="width: 100%; padding: 10px 14px; border-radius: 8px;">
                    <option value="active" <?=($editDirector['status'] ?? 'active') === 'active' ? 'selected' : ''?>>🟢 Active (Visible on Frontend)</option>
                    <option value="inactive" <?=($editDirector['status'] ?? '') === 'inactive' ? 'selected' : ''?>>🟡 Inactive (Hidden)</option>
                </select>
            </div>

            <!-- Sort Order -->
            <div class="field">
                <label style="font-size: 0.85rem; font-weight: 700; margin-bottom: 6px; display: block;">Display Sort Order</label>
                <input type="number" name="sort_order" value="<?=(int)($editDirector['sort_order'] ?? 0)?>" placeholder="0" style="width: 100%; padding: 10px 14px; border-radius: 8px;">
                <span style="font-size: 0.74rem; color: var(--text-muted);">Lower numbers (e.g. 1, 2, 3) display first.</span>
            </div>

            <!-- Photo Upload with Preview -->
            <div class="field" style="grid-column: 1 / -1; background: rgba(255,255,255,0.02); border: 1px dashed var(--border-color); border-radius: 12px; padding: 16px;">
                <label style="font-size: 0.88rem; font-weight: 700; margin-bottom: 6px; display: block; color: var(--primary);">
                    📷 Director Photograph / Portrait Upload
                </label>
                <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
                    <?php 
                    $currPhoto = $editDirector['photo'] ?? '';
                    $hasPhoto = !empty($currPhoto) && file_exists($uploadDir . '/' . $currPhoto);
                    ?>
                    <div style="text-align: center;">
                        <?php if ($hasPhoto): ?>
                            <img src="<?=url('/uploads/directors/' . $currPhoto)?>" alt="Current Director Photo" style="width: 90px; height: 90px; border-radius: 50%; object-fit: cover; border: 3px solid var(--primary); box-shadow: 0 4px 15px rgba(0,0,0,0.3);">
                            <div style="margin-top: 6px;">
                                <a href="<?=url('/admin/cms-directors.php?action=remove_photo&id='.$editDirector['id'])?>" onclick="return confirm('Remove current photo?')" style="color: #f87171; font-size: 0.75rem; text-decoration: none;">
                                    🗑️ Remove Photo
                                </a>
                            </div>
                        <?php else: ?>
                            <div style="width: 90px; height: 90px; border-radius: 50%; background: linear-gradient(135deg, rgba(59,130,246,0.2), rgba(147,51,234,0.2)); border: 2px dashed var(--border-color); display: flex; align-items: center; justify-content: center; font-size: 2rem; color: var(--primary);">
                                <?=!empty($editDirector['name']) ? strtoupper(substr($editDirector['name'], 0, 1)) : '👤'?>
                            </div>
                            <span style="font-size: 0.72rem; color: var(--text-muted); display: block; margin-top: 4px;">No photo uploaded</span>
                        <?php endif; ?>
                    </div>

                    <div style="flex: 1; min-width: 240px;">
                        <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.2); border: 1px solid var(--border-color); border-radius: 8px;">
                        <p style="font-size: 0.75rem; color: var(--text-muted); margin-top: 6px;">
                            Supported formats: <strong>JPG, JPEG, PNG, WEBP</strong>. Recommended size: 400x400 px or square aspect ratio portrait.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Profile Bio -->
            <div class="field" style="grid-column: 1 / -1;">
                <label style="font-size: 0.85rem; font-weight: 700; margin-bottom: 6px; display: block;">Short Profile & Professional Bio</label>
                <textarea name="bio" rows="3" placeholder="Brief background, credentials, or corporate role in Go4 Finance..." style="width: 100%; padding: 10px 14px; border-radius: 8px;"><?=e($editDirector['bio'] ?? '')?></textarea>
            </div>

            <!-- Director's Vision Quote / Message -->
            <div class="field" style="grid-column: 1 / -1;">
                <label style="font-size: 0.85rem; font-weight: 700; margin-bottom: 6px; display: block;">Director's Quote / Vision Statement</label>
                <textarea name="message" rows="2" placeholder="e.g. At GO4FIN, our purpose is to empower every household with accessible non-cash store finance..." style="width: 100%; padding: 10px 14px; border-radius: 8px;"><?=e($editDirector['message'] ?? '')?></textarea>
            </div>
        </div>

        <div style="margin-top: 24px; display: flex; gap: 12px; align-items: center;">
            <button type="submit" class="btn" style="background: linear-gradient(135deg, var(--primary), #2563eb); font-weight: 800; padding: 12px 28px;">
                <i data-lucide="check"></i> <?=$editDirector ? 'Save Director Changes' : 'Publish New Director'?>
            </button>
            <?php if ($editDirector): ?>
                <a href="<?=url('/admin/cms-directors.php')?>" class="btn" style="background: rgba(255,255,255,0.08); color: #fff; padding: 12px 20px;">
                    Cancel
                </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- DIRECTORS DATA TABLE -->
<div class="card" style="background: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 24px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; flex-wrap: wrap; gap: 10px;">
        <h3 style="font-size: 1.15rem; font-weight: 800; color: #fff; display: flex; align-items: center; gap: 8px;">
            <i data-lucide="list"></i> Board of Directors List (<?=count($allDirectors)?>)
        </h3>
        <span style="font-size: 0.8rem; color: var(--text-muted);">
            Changes appear immediately on frontend website (Home & About pages)
        </span>
    </div>

    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.88rem; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border-color); color: var(--text-muted); font-size: 0.78rem; text-transform: uppercase;">
                    <th style="padding: 12px 14px;">Order</th>
                    <th style="padding: 12px 14px;">Photo</th>
                    <th style="padding: 12px 14px;">Director Details</th>
                    <th style="padding: 12px 14px;">Contact / DIN</th>
                    <th style="padding: 12px 14px;">Status</th>
                    <th style="padding: 12px 14px; text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($allDirectors)): ?>
                    <tr>
                        <td colspan="6" style="padding: 30px; text-align: center; color: var(--text-muted);">
                            No directors found. Use the form above to add your first board member.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($allDirectors as $dir): 
                    $photoFile = $dir['photo'] ?? '';
                    $photoExists = !empty($photoFile) && file_exists($uploadDir . '/' . $photoFile);
                    $initial = strtoupper(substr($dir['name'], 0, 1));
                ?>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05); transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='transparent'">
                        <!-- Sort Order -->
                        <td style="padding: 14px; color: var(--text-muted); font-weight: 700;">
                            #<?=$dir['sort_order']?>
                        </td>

                        <!-- Photo Thumbnail -->
                        <td style="padding: 14px;">
                            <?php if ($photoExists): ?>
                                <img src="<?=url('/uploads/directors/' . $photoFile)?>" alt="<?=e($dir['name'])?>" style="width: 54px; height: 54px; border-radius: 50%; object-fit: cover; border: 2px solid var(--primary); box-shadow: 0 2px 10px rgba(0,0,0,0.25);">
                            <?php else: ?>
                                <div style="width: 54px; height: 54px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--secondary)); display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.3rem; color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.2);">
                                    <?=$initial?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- Name & Title -->
                        <td style="padding: 14px;">
                            <div style="font-weight: 800; color: #fff; font-size: 0.95rem;"><?=e($dir['name'])?></div>
                            <div style="color: var(--primary); font-size: 0.8rem; font-weight: 600; margin-top: 2px;"><?=e($dir['designation'])?></div>
                            <?php if (!empty($dir['bio'])): ?>
                                <div style="color: var(--text-muted); font-size: 0.78rem; margin-top: 4px; max-width: 320px; line-height: 1.4;">
                                    <?=e(mb_strimwidth($dir['bio'], 0, 85, '...'))?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- Contact / DIN -->
                        <td style="padding: 14px;">
                            <?php if (!empty($dir['din_no'])): ?>
                                <div style="font-size: 0.78rem; color: #60a5fa; font-family: monospace; font-weight: 700;">
                                    🆔 <?=e($dir['din_no'])?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($dir['phone'])): ?>
                                <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 3px;">
                                    📞 <?=e($dir['phone'])?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($dir['email'])): ?>
                                <div style="font-size: 0.78rem; color: var(--text-muted); margin-top: 3px;">
                                    ✉️ <?=e($dir['email'])?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($dir['linkedin'])): ?>
                                <div style="margin-top: 4px;">
                                    <a href="<?=e($dir['linkedin'])?>" target="_blank" style="font-size: 0.75rem; color: #38bdf8; text-decoration: none;">
                                        🔗 LinkedIn Profile
                                    </a>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- Status Badge -->
                        <td style="padding: 14px;">
                            <?php if ($dir['status'] === 'active'): ?>
                                <span style="background: rgba(16,185,129,0.15); border: 1px solid rgba(16,185,129,0.4); color: var(--success, #10b981); padding: 4px 10px; border-radius: 20px; font-size: 0.74rem; font-weight: 700;">
                                    ✓ Live Active
                                </span>
                            <?php else: ?>
                                <span style="background: rgba(245,158,11,0.15); border: 1px solid rgba(245,158,11,0.4); color: var(--warning, #f59e0b); padding: 4px 10px; border-radius: 20px; font-size: 0.74rem; font-weight: 700;">
                                    ✕ Hidden
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- Action Buttons -->
                        <td style="padding: 14px; text-align: right;">
                            <div style="display: inline-flex; gap: 6px;">
                                <a href="<?=url('/admin/cms-directors.php?edit='.$dir['id'])?>" class="btn" style="padding: 6px 12px; font-size: 0.75rem; background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.4);" title="Edit Profile & Photo">
                                    <i data-lucide="edit-2" style="width: 13px; height: 13px;"></i> Edit
                                </a>
                                <a href="<?=url('/admin/cms-directors.php?action=toggle_status&id='.$dir['id'])?>" class="btn" style="padding: 6px 12px; font-size: 0.75rem; background: rgba(255,255,255,0.06); color: #cbd5e1; border: 1px solid var(--border-color);" title="Toggle Visibility">
                                    <i data-lucide="eye" style="width: 13px; height: 13px;"></i>
                                </a>
                                <a href="<?=url('/admin/cms-directors.php?action=delete&id='.$dir['id'])?>" class="btn" style="padding: 6px 12px; font-size: 0.75rem; background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.4);" onclick="return confirm('Are you sure you want to permanently delete <?=htmlspecialchars(addslashes($dir['name']))?>?')" title="Delete Director">
                                    <i data-lucide="trash-2" style="width: 13px; height: 13px;"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_end(); ?>
