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

// ── KPI: Doanh thu theo kỳ ───────────────────────────────────────────────
$stmtRev = $pdo->prepare("
    SELECT COALESCE(SUM(total_amount), 0)
    FROM invoices
    WHERE invoice_date BETWEEN ? AND ?
      AND status NOT IN ('cancelled','draft')
");
$stmtRev->execute([$dateFrom, $dateTo]);
$revenue = (float)$stmtRev->fetchColumn();

// ── KPI: Chi phí hành chính (expense_requests) ────────────────────────────
$stmtExp = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM expense_requests
    WHERE status = 'approved'
      AND DATE(COALESCE(expense_date, created_at)) BETWEEN ? AND ?
");
$stmtExp->execute([$dateFrom, $dateTo]);
$expenseAdmin = (float)$stmtExp->fetchColumn();

// ── KPI: Chi phí lương (payroll_slips) ────────────────────────────────────
// Chi phí lương thực tế = gross_salary + si_company (BHXH DN đóng thêm 21.5%)
// Lấy theo period_from/period_to nằm trong khoảng dateFrom-dateTo
$stmtPayroll = $pdo->prepare("
    SELECT
        COALESCE(SUM(ps.gross_salary), 0)                                           AS tong_gross,
        COALESCE(SUM(ps.si_company), 0)                                             AS tong_bhxh_cty,
        COALESCE(SUM(ps.gross_salary + ps.si_company), 0)                           AS tong_chi_phi_luong,
        COALESCE(SUM(ps.net_salary), 0)                                             AS tong_net,
        COALESCE(SUM(ps.ot_weekday_amount
                   + ps.ot_weekend_amount
                   + ps.ot_holiday_amount
                   + COALESCE(ps.ot_night_amount, 0)), 0)                           AS tong_ot,
        COUNT(ps.id)                                                                AS so_nhan_vien
    FROM payroll_slips ps
    JOIN payroll_periods pp ON pp.id = ps.period_id
    WHERE pp.status IN ('approved','locked')
      AND pp.period_from <= ?
      AND pp.period_to   >= ?
");
$stmtPayroll->execute([$dateTo, $dateFrom]);
$payrollRow = $stmtPayroll->fetch(PDO::FETCH_ASSOC);
$expensePayroll   = (float)($payrollRow['tong_chi_phi_luong'] ?? 0);
$payrollGross     = (float)($payrollRow['tong_gross']         ?? 0);
$payrollSiCompany = (float)($payrollRow['tong_bhxh_cty']      ?? 0);
$payrollNet       = (float)($payrollRow['tong_net']            ?? 0);
$payrollOT        = (float)($payrollRow['tong_ot']             ?? 0);
$payrollHeadcount = (int)  ($payrollRow['so_nhan_vien']        ?? 0);

$expenseTotal = $expenseAdmin + $expensePayroll;
$profit       = $revenue - $expenseTotal;

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

// ── KPI: Đã thu trong kỳ ────────────────────────────────────────────────
$stmtPaid = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM payments
    WHERE DATE(payment_date) BETWEEN ? AND ?
");
$stmtPaid->execute([$dateFrom, $dateTo]);
$paidMonth = (float)$stmtPaid->fetchColumn();

// ── Biểu đồ: Doanh thu 12 tháng ──────────────────────────────────────────
$chartRevenue = $pdo->query("
    SELECT DATE_FORMAT(invoice_date, '%Y-%m') AS month,
           SUM(total_amount) AS amount
    FROM invoices
    WHERE invoice_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
      AND status NOT IN ('cancelled','draft')
    GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
    ORDER BY month ASC
")->fetchAll();

// ── Biểu đồ: Chi phí lương 12 tháng ─────────────────────────────────────
$chartPayroll = $pdo->query("
    SELECT CONCAT(pp.period_year, '-', LPAD(pp.period_month, 2, '0')) AS month,
           SUM(ps.gross_salary + ps.si_company) AS tong_chi_phi
    FROM payroll_slips ps
    JOIN payroll_periods pp ON pp.id = ps.period_id
    WHERE pp.status IN ('approved','locked')
      AND pp.period_from >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
    GROUP BY pp.period_year, pp.period_month
    ORDER BY month ASC
")->fetchAll();

// ── Biểu đồ: Chi phí hành chính 12 tháng ────────────────────────────────
$chartExpenseAdmin = $pdo->query("
    SELECT DATE_FORMAT(COALESCE(expense_date, created_at), '%Y-%m') AS month,
           SUM(amount) AS tong_chi_phi
    FROM expense_requests
    WHERE status = 'approved'
      AND DATE(COALESCE(expense_date, created_at)) >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
    GROUP BY DATE_FORMAT(COALESCE(expense_date, created_at), '%Y-%m')
    ORDER BY month ASC
")->fetchAll();

// ── Biểu đồ: Chi phí hành chính theo nhóm ───────────────────────────────
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
        'revenue'              => $revenue,
        'revenue_fmt'          => fmtAmount($revenue),
        'expense_admin'        => $expenseAdmin,
        'expense_admin_fmt'    => fmtAmount($expenseAdmin),
        'expense_payroll'      => $expensePayroll,
        'expense_payroll_fmt'  => fmtAmount($expensePayroll),
        'expense_total'        => $expenseTotal,
        'expense_total_fmt'    => fmtAmount($expenseTotal),
        'profit'               => $profit,
        'profit_fmt'           => fmtAmount($profit),
        'debt'                 => $debt,
        'debt_fmt'             => fmtAmount($debt),
        'unpaid_count'         => $unpaidCount,
        'paid_month'           => $paidMonth,
        'paid_month_fmt'       => fmtAmount($paidMonth),
        'payroll_gross'        => $payrollGross,
        'payroll_gross_fmt'    => fmtAmount($payrollGross),
        'payroll_si_company'   => $payrollSiCompany,
        'payroll_si_company_fmt' => fmtAmount($payrollSiCompany),
        'payroll_net'          => $payrollNet,
        'payroll_net_fmt'      => fmtAmount($payrollNet),
        'payroll_ot'           => $payrollOT,
        'payroll_ot_fmt'       => fmtAmount($payrollOT),
        'payroll_headcount'    => $payrollHeadcount,
    ],
    'chart_revenue'      => $chartRevenue,
    'chart_payroll'      => $chartPayroll,
    'chart_expense_admin'=> $chartExpenseAdmin,
    'chart_expense'      => $chartExpense,
    'chart_debt'         => $chartDebt,
    'top_debtors'        => $topDebtors,
], JSON_UNESCAPED_UNICODE);
