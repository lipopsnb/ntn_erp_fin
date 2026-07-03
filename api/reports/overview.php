<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
header('Content-Type: application/json; charset=utf-8');
requireLogin();
requireRole('director', 'accountant', 'manager');

$pdo = getDBConnection();

$dateFrom   = $_GET['date_from']   ?? date('Y-m-01');
$dateTo     = $_GET['date_to']     ?? date('Y-m-d');
$customerId = (int)($_GET['customer_id'] ?? 0);

// Khoảng tháng trước
$prevFrom = date('Y-m-01', strtotime($dateFrom . ' -1 month'));
$prevTo   = date('Y-m-t',  strtotime($dateFrom . ' -1 month'));

function fmtAmount(float $n): string {
    if ($n >= 1_000_000_000) return round($n / 1_000_000_000, 1) . ' tỷ';
    if ($n >= 1_000_000)     return round($n / 1_000_000, 0)     . ' triệu';
    return number_format($n) . ' đ';
}

// ── KPI: Doanh thu tháng ───────────────────────────────────────────────────
$custWhere = $customerId ? ' AND i.customer_id = ?' : '';
$custParam = $customerId ? [$customerId] : [];

$stmtRev = $pdo->prepare("
    SELECT COALESCE(SUM(i.total_amount), 0)
    FROM invoices i
    WHERE i.invoice_date BETWEEN ? AND ?
      AND i.status != 'cancelled'
      AND i.status != 'draft'
      $custWhere
");
$stmtRev->execute(array_merge([$dateFrom, $dateTo], $custParam));
$revenue = (float)$stmtRev->fetchColumn();

$stmtRevPrev = $pdo->prepare("
    SELECT COALESCE(SUM(i.total_amount), 0)
    FROM invoices i
    WHERE i.invoice_date BETWEEN ? AND ?
      AND i.status != 'cancelled'
      AND i.status != 'draft'
      $custWhere
");
$stmtRevPrev->execute(array_merge([$prevFrom, $prevTo], $custParam));
$revenuePrev = (float)$stmtRevPrev->fetchColumn();

// ── KPI: Công nợ phải thu ─────────────────────────────────────────────────
$stmtDebt = $pdo->prepare("
    SELECT COALESCE(SUM(i.total_amount), 0) - COALESCE(SUM(p.paid), 0)
    FROM invoices i
    LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid FROM payments GROUP BY invoice_id) p
           ON p.invoice_id = i.id
    WHERE i.status IN ('unpaid', 'partial')
      $custWhere
");
$stmtDebt->execute($custParam);
$debt = (float)$stmtDebt->fetchColumn();

// ── KPI: Đơn hàng đang SX ─────────────────────────────────────────────────
$ordersInProgress = (int)$pdo->query(
    "SELECT COUNT(*) FROM production_orders WHERE status = 'in_progress'"
)->fetchColumn();

// ── KPI: Tỷ lệ hoàn thành SX ─────────────────────────────────────────────
$row = $pdo->query("
    SELECT COALESCE(SUM(qty_done), 0) AS done, COALESCE(SUM(qty_total), 0) AS total
    FROM production_items
")->fetch();
$completionRate = ($row['total'] > 0)
    ? round($row['done'] / $row['total'] * 100, 1)
    : 0;

// ── KPI: Tỷ lệ lỗi OQC ───────────────────────────────────────────────────
$rowOqc = $pdo->query("
    SELECT COALESCE(SUM(qty_error), 0) AS err, COALESCE(SUM(qty_total), 0) AS total
    FROM production_items
    WHERE qty_total > 0
")->fetch();
$oqcErrorRate = ($rowOqc['total'] > 0)
    ? round($rowOqc['err'] / $rowOqc['total'] * 100, 2)
    : 0;

// ── KPI: Tồn kho vật tư (số mặt hàng còn tồn) ───────────────────────────
$stockItems = (int)$pdo->query("
    SELECT COUNT(*) FROM (
        SELECT item_id,
               SUM(CASE WHEN type = 'import' THEN qty ELSE -qty END) AS remaining
        FROM wa_transactions
        GROUP BY item_id
        HAVING remaining > 0
    ) t
")->fetchColumn();

// ── KPI: Đơn sắp giao (trong 3 ngày) ─────────────────────────────────────
$ordersUpcoming = (int)$pdo->query("
    SELECT COUNT(*)
    FROM production_orders
    WHERE expected_delivery_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
      AND status != 'done'
")->fetchColumn();

// ── KPI: Đơn bị trễ ──────────────────────────────────────────────────────
$ordersLate = (int)$pdo->query("
    SELECT COUNT(*)
    FROM production_orders
    WHERE expected_delivery_date < CURDATE()
      AND status != 'done'
      AND expected_delivery_date IS NOT NULL
")->fetchColumn();

// ── Biểu đồ: Doanh thu 12 tháng ──────────────────────────────────────────
$chartRevRows = $pdo->query("
    SELECT DATE_FORMAT(invoice_date, '%Y-%m') AS month,
           SUM(total_amount) AS amount
    FROM invoices
    WHERE invoice_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
      AND status != 'cancelled'
      AND status != 'draft'
    GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
    ORDER BY month ASC
")->fetchAll();

// ── Bảng tiến độ đơn hàng ────────────────────────────────────────────────
$ordersProgressRows = $pdo->query("
    SELECT po.order_no,
           COALESCE(c.customer_name, '—') AS customer_name,
           po.expected_delivery_date,
           po.status,
           COALESCE(SUM(pi.qty_done), 0) AS qty_done,
           COALESCE(SUM(pi.qty_total), 0) AS qty_total
    FROM production_orders po
    LEFT JOIN customers c ON po.customer_id = c.id
    LEFT JOIN production_items pi ON pi.order_id = po.id
    WHERE po.status IN ('pending','in_progress')
    GROUP BY po.id
    ORDER BY po.expected_delivery_date ASC, po.id DESC
    LIMIT 20
")->fetchAll();

$ordersProgress = array_map(function($r) {
    $pct = ($r['qty_total'] > 0)
        ? round($r['qty_done'] / $r['qty_total'] * 100, 1)
        : 0;
    return [
        'order_no'               => $r['order_no'],
        'customer_name'          => $r['customer_name'],
        'expected_delivery_date' => $r['expected_delivery_date'],
        'progress_pct'           => $pct,
        'status'                 => $r['status'],
    ];
}, $ordersProgressRows);

// ── Top 5 khách hàng ─────────────────────────────────────────────────────
$topCustomers = $pdo->query("
    SELECT c.customer_name, SUM(i.total_amount) AS total_amount
    FROM invoices i
    JOIN customers c ON c.id = i.customer_id
    WHERE i.status != 'cancelled' AND i.status != 'draft'
    GROUP BY i.customer_id
    ORDER BY total_amount DESC
    LIMIT 5
")->fetchAll();

// ── Cảnh báo ─────────────────────────────────────────────────────────────
$alerts = [];

// Đơn hàng quá hạn
$lateOrders = $pdo->query("
    SELECT order_no, expected_delivery_date
    FROM production_orders
    WHERE expected_delivery_date < CURDATE()
      AND status != 'done'
      AND expected_delivery_date IS NOT NULL
    LIMIT 10
")->fetchAll();
foreach ($lateOrders as $lo) {
    $alerts[] = [
        'type'    => 'late_order',
        'message' => 'Đơn hàng ' . $lo['order_no'] . ' quá hạn giao (' . $lo['expected_delivery_date'] . ')',
        'level'   => 'danger',
    ];
}

// Vật tư dưới tồn kho tối thiểu
$lowStock = $pdo->query("
    SELECT wi.item_name, wi.min_stock,
           COALESCE(SUM(CASE WHEN wt.type='import' THEN wt.qty ELSE -wt.qty END),0) AS current_stock
    FROM wa_items wi
    LEFT JOIN wa_transactions wt ON wt.item_id = wi.id
    WHERE wi.min_stock > 0
    GROUP BY wi.id
    HAVING current_stock < wi.min_stock
    LIMIT 10
")->fetchAll();
foreach ($lowStock as $ls) {
    $alerts[] = [
        'type'    => 'low_stock',
        'message' => 'Vật tư ' . $ls['item_name'] . ' dưới tồn tối thiểu (còn ' . $ls['current_stock'] . ', min ' . $ls['min_stock'] . ')',
        'level'   => 'warning',
    ];
}

// Công nợ quá hạn
$overdueInv = $pdo->query("
    SELECT i.invoice_no, c.customer_name, i.due_date
    FROM invoices i
    JOIN customers c ON c.id = i.customer_id
    WHERE i.due_date < CURDATE()
      AND i.status IN ('unpaid','partial')
    LIMIT 10
")->fetchAll();
foreach ($overdueInv as $oi) {
    $alerts[] = [
        'type'    => 'overdue_debt',
        'message' => 'Công nợ quá hạn: ' . $oi['customer_name'] . ' (HĐ ' . $oi['invoice_no'] . ', hạn ' . $oi['due_date'] . ')',
        'level'   => 'danger',
    ];
}

// Thành phẩm chờ giao
$waitingItems = (int)$pdo->query(
    "SELECT COUNT(*) FROM warehouse_items WHERE status = 'waiting'"
)->fetchColumn();
if ($waitingItems > 0) {
    $alerts[] = [
        'type'    => 'waiting_delivery',
        'message' => $waitingItems . ' lô thành phẩm đang chờ giao hàng',
        'level'   => 'warning',
    ];
}

echo json_encode([
    'ok'  => true,
    'kpi' => [
        'revenue'            => $revenue,
        'revenue_fmt'        => fmtAmount($revenue),
        'revenue_prev'       => $revenuePrev,
        'revenue_prev_fmt'   => fmtAmount($revenuePrev),
        'debt'               => $debt,
        'debt_fmt'           => fmtAmount($debt),
        'orders_in_progress' => $ordersInProgress,
        'completion_rate'    => $completionRate,
        'oqc_error_rate'     => $oqcErrorRate,
        'stock_items'        => $stockItems,
        'orders_upcoming'    => $ordersUpcoming,
        'orders_late'        => $ordersLate,
    ],
    'chart_revenue'   => $chartRevRows,
    'orders_progress' => $ordersProgress,
    'top_customers'   => $topCustomers,
    'alerts'          => $alerts,
], JSON_UNESCAPED_UNICODE);
