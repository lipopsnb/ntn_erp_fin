<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
header('Content-Type: application/json; charset=utf-8');
requireLogin();
requireRole('director', 'accountant', 'manager', 'production', 'warehouse');

$pdo = getDBConnection();

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');

// ── KPI ───────────────────────────────────────────────────────────────────
$rowKpi = $pdo->prepare("
    SELECT
        COALESCE(SUM(pi.qty_total), 0) AS qty_target,
        COALESCE(SUM(pi.qty_done),  0) AS qty_done,
        COALESCE(SUM(pi.qty_error), 0) AS qty_error
    FROM production_items pi
    JOIN production_orders po ON po.id = pi.order_id
    WHERE (pi.updated_at BETWEEN ? AND ? OR pi.created_at BETWEEN ? AND ?)
");
$rowKpi->execute([$dateFrom, $dateTo, $dateFrom, $dateTo]);
$kpiRow = $rowKpi->fetch();

$qtyTarget = (float)$kpiRow['qty_target'];
$qtyDone   = (float)$kpiRow['qty_done'];
$qtyError  = (float)$kpiRow['qty_error'];
$completionRate = ($qtyTarget > 0) ? round($qtyDone / $qtyTarget * 100, 1) : 0;
$errorRate      = ($qtyTarget > 0) ? round($qtyError / $qtyTarget * 100, 2) : 0;

$ordersInProgress = (int)$pdo->query(
    "SELECT COUNT(*) FROM production_orders WHERE status = 'in_progress'"
)->fetchColumn();

$ordersDone = (int)$pdo->query(
    "SELECT COUNT(*) FROM production_orders WHERE status = 'done'"
)->fetchColumn();

// ── Biểu đồ sản lượng theo ngày (30 ngày) ────────────────────────────────
$chartDaily = $pdo->query("
    SELECT DATE(updated_at) AS day, SUM(qty_done) AS qty_done
    FROM production_items
    WHERE updated_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY DATE(updated_at)
    ORDER BY day ASC
")->fetchAll();

// ── Bảng tiến độ từng đơn hàng ───────────────────────────────────────────
$orderRows = $pdo->query("
    SELECT po.id, po.order_no,
           COALESCE(c.customer_name, '—') AS customer_name,
           po.expected_delivery_date, po.status,
           po.qty_target AS order_qty_target,
           COALESCE(SUM(pi.qty_done),  0) AS qty_done,
           COALESCE(SUM(pi.qty_error), 0) AS qty_error,
           COALESCE(SUM(pi.qty_total), 0) AS qty_total
    FROM production_orders po
    LEFT JOIN customers c ON c.id = po.customer_id
    LEFT JOIN production_items pi ON pi.order_id = po.id
    WHERE po.status IN ('pending','in_progress','done')
    GROUP BY po.id
    ORDER BY po.expected_delivery_date ASC, po.id DESC
    LIMIT 30
")->fetchAll();

$orders = array_map(function($r) {
    $pct = ($r['qty_total'] > 0) ? round($r['qty_done'] / $r['qty_total'] * 100, 1) : 0;
    return array_merge($r, ['progress_pct' => $pct]);
}, $orderRows);

// ── Đơn sắp trễ (7 ngày) ────────────────────────────────────────────────
$soonLate = $pdo->query("
    SELECT po.order_no,
           COALESCE(c.customer_name, '—') AS customer_name,
           po.expected_delivery_date, po.status,
           COALESCE(SUM(pi.qty_done), 0)  AS qty_done,
           COALESCE(SUM(pi.qty_total), 0) AS qty_total
    FROM production_orders po
    LEFT JOIN customers c ON c.id = po.customer_id
    LEFT JOIN production_items pi ON pi.order_id = po.id
    WHERE po.expected_delivery_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      AND po.status != 'done'
    GROUP BY po.id
    ORDER BY po.expected_delivery_date ASC
    LIMIT 20
")->fetchAll();

echo json_encode([
    'ok'  => true,
    'kpi' => [
        'qty_target'        => $qtyTarget,
        'qty_done'          => $qtyDone,
        'qty_error'         => $qtyError,
        'completion_rate'   => $completionRate,
        'error_rate'        => $errorRate,
        'orders_in_progress'=> $ordersInProgress,
        'orders_done'       => $ordersDone,
    ],
    'chart_daily' => $chartDaily,
    'orders'      => $orders,
    'soon_late'   => $soonLate,
], JSON_UNESCAPED_UNICODE);
