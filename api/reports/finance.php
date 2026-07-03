<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
header('Content-Type: application/json; charset=utf-8');
requireLogin();
requireRole('director', 'accountant');

$pdo = getDBConnection();

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');

function fmtAmount(float $n): string {
    return number_format($n, 0, ',', '.') . ' đ';
}

// ── KPI: Doanh thu tháng ─────────────────────────────────────────────────
$stmtRev = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM invoices
    WHERE invoice_date BETWEEN ? AND ?
      AND status NOT IN ('cancelled','draft')
");
$stmtRev->execute([$dateFrom, $dateTo]);
$revenue = (float)$stmtRev->fetchColumn();

// ── KPI: Chi phí tháng ────────────────────────────────────────────────────
$stmtExp = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM expense_requests
    WHERE status = 'approved'
      AND DATE(created_at) BETWEEN ? AND ?
");
$stmtExp->execute([$dateFrom, $dateTo]);
$expense = (float)$stmtExp->fetchColumn();

$profit = $revenue - $expense;

// ── KPI: Công nợ phải thu ─────────────────────────────────────────────────
$debt = (float)$pdo->query("
    SELECT COALESCE(SUM(i.total_amount), 0) - COALESCE(SUM(p.paid), 0)
    FROM invoices i
    LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid FROM payments GROUP BY invoice_id) p
           ON p.invoice_id = i.id
    WHERE i.status IN ('unpaid','partial')
")->fetchColumn();

// ── KPI: Hoá đơn chưa thanh toán ─────────────────────────────────────────
$unpaidCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid','partial')"
)->fetchColumn();

// ── KPI: Đã thu tháng ────────────────────────────────────────────────────
$stmtPaid = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM payments
    WHERE DATE(payment_date) BETWEEN ? AND ?
");
$stmtPaid->execute([$dateFrom, $dateTo]);
$paidMonth = (float)$stmtPaid->fetchColumn();

// ── Biểu đồ: Doanh thu 12 tháng ──────────────────────────────────────────
$chartRevenue = $pdo->query("
    SELECT DATE_FORMAT(invoice_date, '%Y-%m') AS month, SUM(total_amount) AS amount
    FROM invoices
    WHERE invoice_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
      AND status NOT IN ('cancelled','draft')
    GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
    ORDER BY month ASC
")->fetchAll();

// ── Biểu đồ: Chi phí theo nhóm ───────────────────────────────────────────
$chartExpense = $pdo->query("
    SELECT COALESCE(ec.category_name, 'Khác') AS category_name,
           SUM(er.amount) AS total
    FROM expense_requests er
    LEFT JOIN expense_categories ec ON ec.id = er.category_id
    WHERE er.status = 'approved'
    GROUP BY ec.id
    ORDER BY total DESC
    LIMIT 10
")->fetchAll();

// ── Biểu đồ: Công nợ theo khách hàng ─────────────────────────────────────
$chartDebt = $pdo->query("
    SELECT c.customer_name,
           SUM(i.total_amount) - COALESCE(SUM(p.paid), 0) AS debt_amount
    FROM invoices i
    JOIN customers c ON c.id = i.customer_id
    LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid FROM payments GROUP BY invoice_id) p
           ON p.invoice_id = i.id
    WHERE i.status IN ('unpaid','partial')
    GROUP BY i.customer_id
    ORDER BY debt_amount DESC
    LIMIT 10
")->fetchAll();

// ── Top 10 khách hàng còn nợ ─────────────────────────────────────────────
$topDebtors = $pdo->query("
    SELECT c.customer_name,
           SUM(i.total_amount) - COALESCE(SUM(p.paid), 0) AS total_debt,
           MIN(i.due_date) AS oldest_due_date,
           GREATEST(0, DATEDIFF(CURDATE(), MIN(i.due_date))) AS overdue_days
    FROM invoices i
    JOIN customers c ON c.id = i.customer_id
    LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid FROM payments GROUP BY invoice_id) p
           ON p.invoice_id = i.id
    WHERE i.status IN ('unpaid','partial')
    GROUP BY i.customer_id
    ORDER BY total_debt DESC
    LIMIT 10
")->fetchAll();

echo json_encode([
    'ok'  => true,
    'kpi' => [
        'revenue'       => $revenue,
        'revenue_fmt'   => fmtAmount($revenue),
        'expense'       => $expense,
        'expense_fmt'   => fmtAmount($expense),
        'profit'        => $profit,
        'profit_fmt'    => fmtAmount($profit),
        'debt'          => $debt,
        'debt_fmt'      => fmtAmount($debt),
        'unpaid_count'  => $unpaidCount,
        'paid_month'    => $paidMonth,
        'paid_month_fmt'=> fmtAmount($paidMonth),
    ],
    'chart_revenue' => $chartRevenue,
    'chart_expense' => $chartExpense,
    'chart_debt'    => $chartDebt,
    'top_debtors'   => $topDebtors,
], JSON_UNESCAPED_UNICODE);
