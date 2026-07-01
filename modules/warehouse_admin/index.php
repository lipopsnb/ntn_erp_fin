<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'manager', 'warehouse');
$pdo = getDBConnection();

$totalItems = (int)fetchScalarSafe($pdo, 'SELECT COUNT(*) FROM wa_items', [], 0);
$lowStock = (int)fetchScalarSafe($pdo, "SELECT COUNT(*) FROM (
    SELECT i.id, COALESCE(SUM(CASE WHEN t.type='import' THEN t.qty ELSE -t.qty END),0) AS stock, i.min_stock
    FROM wa_items i LEFT JOIN wa_transactions t ON t.item_id = i.id GROUP BY i.id
) x WHERE x.stock < x.min_stock", [], 0);
$importMonth = (float)fetchScalarSafe($pdo, "SELECT COALESCE(SUM(qty),0) FROM wa_transactions WHERE type='import' AND DATE_FORMAT(transacted_at,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')", [], 0);
$exportMonth = (float)fetchScalarSafe($pdo, "SELECT COALESCE(SUM(qty),0) FROM wa_transactions WHERE type='export' AND DATE_FORMAT(transacted_at,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')", [], 0);

$stocks = fetchAllSafe($pdo, "SELECT i.item_code, i.item_name, i.unit, i.min_stock, c.name AS category_name,
                         COALESCE(SUM(CASE WHEN t.type='import' THEN t.qty ELSE -t.qty END),0) AS stock
                         FROM wa_items i
                         LEFT JOIN wa_categories c ON c.id = i.category_id
                         LEFT JOIN wa_transactions t ON t.item_id = i.id
                         GROUP BY i.id
                         ORDER BY stock ASC
                         LIMIT 20");
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content"><div class="container-fluid py-4">
    <div class="mb-4"><h4 class="mb-1"><i class="fas fa-warehouse me-2 text-primary"></i>Tổng quan kho vật tư</h4><p class="text-muted mb-0">Theo dõi tồn kho và nhập/xuất vật tư hành chính.</p></div>
    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Tổng vật tư</div><div class="fs-4 fw-bold text-primary"><?= e((string)$totalItems) ?></div></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Vật tư thiếu hàng</div><div class="fs-4 fw-bold text-danger"><?= e((string)$lowStock) ?></div></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Nhập tháng này</div><div class="fs-4 fw-bold text-success"><?= e(number_format($importMonth,2,',','.')) ?></div></div></div></div>
        <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Xuất tháng này</div><div class="fs-4 fw-bold text-warning"><?= e(number_format($exportMonth,2,',','.')) ?></div></div></div></div>
    </div>

    <div class="card border-0 shadow-sm"><div class="card-header bg-white"><h6 class="mb-0">Tồn kho nhanh (Top 20)</h6></div><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-dark"><tr><th>Mã vật tư</th><th>Tên</th><th>Nhóm</th><th>ĐVT</th><th class="text-end">Tồn hiện tại</th><th class="text-end">Tồn tối thiểu</th><th>Trạng thái</th></tr></thead>
        <tbody>
        <?php if(!$stocks): ?><tr><td colspan="7" class="text-center text-muted py-4">Chưa có dữ liệu</td></tr><?php endif; ?>
        <?php foreach($stocks as $s): $isLow=(float)$s['stock']<(float)$s['min_stock']; ?>
            <tr class="<?= $isLow?'table-danger':'' ?>"><td><?= e($s['item_code']) ?></td><td><?= e($s['item_name']) ?></td><td><?= e($s['category_name']) ?></td><td><?= e($s['unit']) ?></td><td class="text-end"><?= e(number_format((float)$s['stock'],2,',','.')) ?></td><td class="text-end"><?= e(number_format((float)$s['min_stock'],2,',','.')) ?></td><td><span class="badge bg-<?= $isLow?'danger':'success' ?>"><?= $isLow?'Thiếu':'Đủ' ?></span></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div></div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
