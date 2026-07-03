<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
header('Content-Type: application/json; charset=utf-8');
requireLogin();
requireRole('director', 'accountant', 'manager', 'warehouse');

$pdo = getDBConnection();

$today = date('Y-m-d');

// ── KPI: Tổng mặt hàng vật tư ────────────────────────────────────────────
$totalItems = (int)$pdo->query("SELECT COUNT(*) FROM wa_items WHERE is_active = 1")->fetchColumn();

// ── KPI: Nhập / Xuất hôm nay ─────────────────────────────────────────────
$stmtImport = $pdo->prepare("SELECT COALESCE(SUM(qty), 0) FROM wa_transactions WHERE type = 'import' AND DATE(transacted_at) = ?");
$stmtImport->execute([$today]);
$importToday = (float)$stmtImport->fetchColumn();

$stmtExport = $pdo->prepare("SELECT COALESCE(SUM(qty), 0) FROM wa_transactions WHERE type = 'export' AND DATE(transacted_at) = ?");
$stmtExport->execute([$today]);
$exportToday = (float)$stmtExport->fetchColumn();

// ── KPI: Vật tư sắp hết (tồn < min_stock) ───────────────────────────────
$lowStockCount = (int)$pdo->query("
    SELECT COUNT(*) FROM (
        SELECT wi.id,
               COALESCE(SUM(CASE WHEN wt.type='import' THEN wt.qty ELSE -wt.qty END), 0) AS remaining,
               wi.min_stock
        FROM wa_items wi
        LEFT JOIN wa_transactions wt ON wt.item_id = wi.id
        WHERE wi.min_stock > 0
        GROUP BY wi.id
        HAVING remaining < wi.min_stock
    ) t
")->fetchColumn();

// ── KPI: Thành phẩm chờ giao ─────────────────────────────────────────────
$waitingDelivery = (int)$pdo->query(
    "SELECT COUNT(*) FROM warehouse_items WHERE status = 'waiting'"
)->fetchColumn();

// ── KPI: Hàng tồn lâu (import > 30 ngày không có export) ────────────────
$slowMoving = (int)$pdo->query("
    SELECT COUNT(*) FROM (
        SELECT item_id
        FROM wa_transactions
        WHERE type = 'import'
          AND transacted_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
          AND item_id NOT IN (
              SELECT DISTINCT item_id FROM wa_transactions
              WHERE type = 'export' AND transacted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          )
        GROUP BY item_id
    ) t
")->fetchColumn();

// ── Biểu đồ nhập/xuất 30 ngày ────────────────────────────────────────────
$chartDaily = $pdo->query("
    SELECT DATE(transacted_at) AS day,
           SUM(CASE WHEN type = 'import' THEN qty ELSE 0 END) AS import_qty,
           SUM(CASE WHEN type = 'export' THEN qty ELSE 0 END) AS export_qty
    FROM wa_transactions
    WHERE transacted_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(transacted_at)
    ORDER BY day ASC
")->fetchAll();

// ── Top 10 vật tư tồn nhiều ──────────────────────────────────────────────
$topStock = $pdo->query("
    SELECT wi.item_name,
           COALESCE(SUM(CASE WHEN wt.type='import' THEN wt.qty ELSE -wt.qty END), 0) AS remaining
    FROM wa_items wi
    LEFT JOIN wa_transactions wt ON wt.item_id = wi.id
    GROUP BY wi.id
    HAVING remaining > 0
    ORDER BY remaining DESC
    LIMIT 10
")->fetchAll();

// ── Cảnh báo vật tư dưới min ─────────────────────────────────────────────
$lowStockList = $pdo->query("
    SELECT wi.item_name, wi.min_stock,
           COALESCE(SUM(CASE WHEN wt.type='import' THEN wt.qty ELSE -wt.qty END), 0) AS current_stock
    FROM wa_items wi
    LEFT JOIN wa_transactions wt ON wt.item_id = wi.id
    WHERE wi.min_stock > 0
    GROUP BY wi.id
    HAVING current_stock < wi.min_stock
    ORDER BY current_stock ASC
    LIMIT 20
")->fetchAll();

// ── Thành phẩm tồn lâu (> 30 ngày) ──────────────────────────────────────
$oldFinished = $pdo->query("
    SELECT lot_no, product_code, qty, created_at
    FROM warehouse_items
    WHERE status = 'waiting'
      AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ORDER BY created_at ASC
    LIMIT 20
")->fetchAll();

// ── Vật tư không giao dịch 30 ngày ──────────────────────────────────────
$inactiveItems = $pdo->query("
    SELECT wi.item_name,
           MAX(wt.transacted_at) AS last_transaction
    FROM wa_items wi
    LEFT JOIN wa_transactions wt ON wt.item_id = wi.id
    WHERE wi.is_active = 1
    GROUP BY wi.id
    HAVING last_transaction < DATE_SUB(NOW(), INTERVAL 30 DAY) OR last_transaction IS NULL
    ORDER BY last_transaction ASC
    LIMIT 20
")->fetchAll();

echo json_encode([
    'ok'  => true,
    'kpi' => [
        'total_items'      => $totalItems,
        'import_today'     => $importToday,
        'export_today'     => $exportToday,
        'low_stock_count'  => $lowStockCount,
        'waiting_delivery' => $waitingDelivery,
        'slow_moving'      => $slowMoving,
    ],
    'chart_daily'     => $chartDaily,
    'top_stock'       => $topStock,
    'low_stock_list'  => $lowStockList,
    'old_finished'    => $oldFinished,
    'inactive_items'  => $inactiveItems,
], JSON_UNESCAPED_UNICODE);
