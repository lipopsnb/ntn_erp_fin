<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'warehouse', 'production', 'manager');
$pdo = getDBConnection();

$stats = [
    'iqc_open' => (int)fetchScalarSafe($pdo, "SELECT COUNT(*) FROM iqc_receipts WHERE status = 'open'", [], 0),
    'orders_running' => (int)fetchScalarSafe($pdo, "SELECT COUNT(*) FROM production_orders WHERE status IN ('pending','in_progress')", [], 0),
    'oqc_waiting' => (int)fetchScalarSafe($pdo, "SELECT COUNT(*) FROM production_items WHERE (qty_done > 0 OR qty_error > 0)", [], 0),
    'delivery_today' => (int)fetchScalarSafe($pdo, "SELECT COUNT(*) FROM oqc_deliveries WHERE delivery_date = CURDATE()", [], 0),
];

$recentIqc = fetchAllSafe($pdo, "SELECT r.id, r.receipt_no, r.received_date, c.customer_name, r.status,
                                 (SELECT COUNT(*) FROM iqc_receipt_items i WHERE i.receipt_id = r.id) AS item_count
                                 FROM iqc_receipts r
                                 LEFT JOIN customers c ON c.id = r.customer_id
                                 ORDER BY r.id DESC LIMIT 10");

$runningOrders = fetchAllSafe($pdo, "SELECT o.id, o.order_no, o.status, c.customer_name,
                                    SUM(pi.qty_total) AS qty_total,
                                    SUM(pi.qty_done) AS qty_done,
                                    SUM(pi.qty_error) AS qty_error
                                    FROM production_orders o
                                    JOIN iqc_receipts r ON r.id = o.iqc_receipt_id
                                    JOIN customers c ON c.id = r.customer_id
                                    LEFT JOIN production_items pi ON pi.order_id = o.id
                                    WHERE o.status = 'in_progress'
                                    GROUP BY o.id
                                    ORDER BY o.id DESC");

$statusMap = ['open' => 'warning', 'in_production' => 'info', 'done' => 'success'];
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h4 class="mb-1"><i class="fas fa-boxes me-2 text-primary"></i>Tổng quan kho sản xuất</h4>
                <p class="text-muted mb-0">Theo dõi IQC, lệnh sản xuất và OQC.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="/erp/modules/warehouse/iqc_list.php" class="btn btn-primary"><i class="fas fa-file-import me-1"></i>Nhập IQC mới</a>
                <a href="/erp/modules/production/index.php" class="btn btn-outline-primary"><i class="fas fa-tasks me-1"></i>Xem lệnh SX</a>
                <a href="/erp/modules/warehouse/oqc_list.php" class="btn btn-outline-secondary"><i class="fas fa-boxes me-1"></i>Kho OQC</a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">IQC đang mở</div><div class="fs-4 fw-bold text-warning"><?= e((string)$stats['iqc_open']) ?></div></div></div></div>
            <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Lệnh SX đang chạy</div><div class="fs-4 fw-bold text-info"><?= e((string)$stats['orders_running']) ?></div></div></div></div>
            <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Hàng chờ xuất OQC</div><div class="fs-4 fw-bold text-primary"><?= e((string)$stats['oqc_waiting']) ?></div></div></div></div>
            <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Phiếu xuất hôm nay</div><div class="fs-4 fw-bold text-success"><?= e((string)$stats['delivery_today']) ?></div></div></div></div>
        </div>

        <div class="row g-3">
            <div class="col-lg-7">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white"><h6 class="mb-0">Phiếu IQC gần đây</h6></div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-dark"><tr><th>Số phiếu</th><th>Ngày nhận</th><th>Khách hàng</th><th class="text-center">Mặt hàng</th><th>Trạng thái</th></tr></thead>
                            <tbody>
                            <?php if (!$recentIqc): ?><tr><td colspan="5" class="text-center text-muted py-4">Chưa có dữ liệu</td></tr><?php endif; ?>
                            <?php foreach ($recentIqc as $row): ?>
                                <tr>
                                    <td class="fw-semibold"><?= e($row['receipt_no']) ?></td>
                                    <td><?= e(formatDate($row['received_date'])) ?></td>
                                    <td><?= e($row['customer_name'] ?? '—') ?></td>
                                    <td class="text-center"><?= e((string)$row['item_count']) ?></td>
                                    <td><span class="badge bg-<?= e($statusMap[$row['status']] ?? 'secondary') ?>"><?= e($row['status']) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white"><h6 class="mb-0">Lệnh SX đang chạy</h6></div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-dark"><tr><th>Lệnh SX</th><th>Khách hàng</th><th class="text-end">Còn lại</th></tr></thead>
                            <tbody>
                            <?php if (!$runningOrders): ?><tr><td colspan="3" class="text-center text-muted py-4">Không có lệnh in_progress</td></tr><?php endif; ?>
                            <?php foreach ($runningOrders as $row): $pending = (float)$row['qty_total'] - (float)$row['qty_done'] - (float)$row['qty_error']; ?>
                                <tr>
                                    <td><a class="text-decoration-none" href="/erp/modules/production/detail.php?id=<?= (int)$row['id'] ?>"><?= e($row['order_no']) ?></a></td>
                                    <td><?= e($row['customer_name'] ?? '—') ?></td>
                                    <td class="text-end text-warning fw-semibold"><?= e(number_format(max($pending, 0), 2, ',', '.')) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
