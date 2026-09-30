<?php
require_once __DIR__ . '/../config/config.php';

/**
 * Ensures database columns for Per-Shop POS activation and Cashfree integration
 */
function ensurePosSystemTable() {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $p = db();

        // 1. Add POS activation columns to shops table if missing
        $shopCols = [];
        $colStmt = $p->query("SHOW COLUMNS FROM shops");
        while ($col = $colStmt->fetch()) {
            $shopCols[] = $col['Field'];
        }

        if (!in_array('pos_active', $shopCols)) {
            $p->exec("ALTER TABLE shops ADD COLUMN pos_active TINYINT(1) NOT NULL DEFAULT 0 AFTER wallet_balance");
        }
        if (!in_array('pos_price', $shopCols)) {
            $p->exec("ALTER TABLE shops ADD COLUMN pos_price DECIMAL(10,2) NOT NULL DEFAULT 1999.00 AFTER pos_active");
        }
        if (!in_array('pos_activated_at', $shopCols)) {
            $p->exec("ALTER TABLE shops ADD COLUMN pos_activated_at DATETIME NULL AFTER pos_price");
        }
        if (!in_array('pos_order_id', $shopCols)) {
            $p->exec("ALTER TABLE shops ADD COLUMN pos_order_id VARCHAR(100) NULL AFTER pos_activated_at");
        }
        if (!in_array('pos_payment_ref', $shopCols)) {
            $p->exec("ALTER TABLE shops ADD COLUMN pos_payment_ref VARCHAR(100) NULL AFTER pos_order_id");
        }

        // 2. Ensure gateway_orders has shop_id and order_type columns
        $gwCols = [];
        $gwStmt = $p->query("SHOW COLUMNS FROM gateway_orders");
        while ($col = $gwStmt->fetch()) {
            $gwCols[] = $col['Field'];
        }

        if (!in_array('shop_id', $gwCols)) {
            $p->exec("ALTER TABLE gateway_orders ADD COLUMN shop_id INT NULL AFTER emi_id");
        }
        if (!in_array('order_type', $gwCols)) {
            $p->exec("ALTER TABLE gateway_orders ADD COLUMN order_type VARCHAR(50) DEFAULT 'EMI' AFTER gateway");
        }

        // Allow finance_id to be NULL for non-loan orders (such as POS addon activations)
        try {
            $p->exec("ALTER TABLE gateway_orders MODIFY COLUMN finance_id INT NULL DEFAULT NULL");
        } catch (Exception $ex) {}

        // 3. Set default POS price in settings if not configured
        if (get_setting('pos_activation_price', '') === '') {
            set_setting('pos_activation_price', '1999');
        }

    } catch (Exception $e) {
        error_log('ensurePosSystemTable Error: ' . $e->getMessage());
    }
}

/**
 * Check whether POS terminal is unlocked/active for a given user & shop.
 * Superadmin always has free and unrestricted access.
 * Shop accounts require pos_active = 1 in shops table.
 *
 * @param int|null $shopId
 * @param array|null $user
 * @return bool
 */
function is_pos_unlocked($shopId = null, $user = null) {
    if ($user === null) {
        $user = function_exists('u') ? u() : ($_SESSION['user'] ?? null);
    }

    // Superadmin is always 100% free!
    if (($user['role'] ?? '') === 'superadmin') {
        return true;
    }

    if ($shopId === null || $shopId <= 0) {
        $shopId = (int)($user['shop_id'] ?? 0);
    }

    if ($shopId <= 0) {
        return false;
    }

    try {
        $p = db();
        $stmt = $p->prepare("SELECT pos_active FROM shops WHERE id = ? LIMIT 1");
        $stmt->execute([$shopId]);
        $res = $stmt->fetchColumn();
        return ((int)$res === 1);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Activate POS for a shop
 */
function activate_shop_pos($shopId, $orderId = '', $paymentRef = '') {
    try {
        $p = db();
        $stmt = $p->prepare("UPDATE shops SET pos_active = 1, pos_activated_at = NOW(), pos_order_id = ?, pos_payment_ref = ? WHERE id = ?");
        return $stmt->execute([$orderId, $paymentRef, (int)$shopId]);
    } catch (Exception $e) {
        error_log('activate_shop_pos Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Deactivate POS for a shop (Superadmin manual control)
 */
function deactivate_shop_pos($shopId) {
    try {
        $p = db();
        $stmt = $p->prepare("UPDATE shops SET pos_active = 0 WHERE id = ?");
        return $stmt->execute([(int)$shopId]);
    } catch (Exception $e) {
        error_log('deactivate_shop_pos Error: ' . $e->getMessage());
        return false;
    }
}
