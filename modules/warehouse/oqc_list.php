<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'warehouse', 'production', 'manager');
$pdo = getDBConnection();

$customerId = (int)($_GET['customer_id'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));
$where = ['(pi.qty_done > 0 OR pi.qty_error > 0)'];
$params = [];
if ($customerId > 0) { $where[] = 'r.customer_id = ?'; $params[] = $customerId; }
if (in_array($status, ['in_progress','done','error'], true)) { $where[] = 'pi.status = ?'; $params[] = $status; }

$customers = fetchAllSafe($pdo, 'SELECT id, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name');
$rows = fetchAllSafe($pdo, "SELECT pi.id, pi.qty_total, pi.qty_done, pi.qty_error, pi.status,
                            po.order_no, c.customer_name,
                            pc.product_code, pc.description,
                            (pi.qty_total - pi.qty_done - pi.qty_error) AS qty_pending
                            FROM production_items pi
                            JOIN production_orders po ON po.id = pi.order_id
                            JOIN iqc_receipt_items iri ON iri.id = pi.iqc_item_id
                            JOIN iqc_receipts r ON r.id = po.iqc_receipt_id
                            JOIN customers c ON c.id = r.customer_id
                            JOIN product_codes pc ON pc.id = iri.product_code_id
                            WHERE " . implode(' AND ', $where) . "
                            ORDER BY pi.id DESC", $params);

$badge = ['in_progress' => 'warning', 'done' => 'success', 'error' => 'danger'];
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content"><div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h4 class="mb-1"><i class="fas fa-boxes me-2 text-primary"></i>Kho thành phẩm OQC</h4><p class="text-muted mb-0">Danh sách sản phẩm hoàn thành/lỗi từ sản xuất.</p></div>
        <a href="/erp/modules/warehouse/oqc_delivery.php" class="btn btn-primary"><i class="fas fa-truck me-1"></i>Tạo phiếu xuất</a>
    </div>

    <div class="card border-0 shadow-sm mb-3"><div class="card-body py-2"><form class="row g-2" method="get">
        <div class="col-md-3"><select name="customer_id" class="form-select form-select-sm"><option value="">-- Khách hàng --</option><?php foreach($customers as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $customerId===(int)$c['id']?'selected':'' ?>><?= e($c['customer_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">-- Trạng thái --</option><?php foreach(['in_progress','done','error'] as $st): ?><option value="<?= e($st) ?>" <?= $status===$st?'selected':'' ?>><?= e($st) ?></option><?php endforeach; ?></select></div>
        <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button></div>
        <div class="col-auto"><a href="/erp/modules/warehouse/oqc_list.php" class="btn btn-sm btn-outline-secondary">Reset</a></div>
    </form></div></div>

    <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-dark"><tr><th>Lệnh SX</th><th>Khách hàng</th><th>Mã hàng</th><th>Tên hàng</th><th class="text-end text-success">SL HT</th><th class="text-end text-danger">SL Lỗi</th><th class="text-end text-warning">SL Chờ</th><th>Trạng thái</th><th>Thao tác</th></tr></thead>
        <tbody>
        <?php if(!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">Không có dữ liệu OQC</td></tr><?php endif; ?>
        <?php foreach($rows as $row): ?>
            <tr class="<?= (float)$row['qty_error'] > 0 ? 'table-danger' : '' ?>">
                <td><?= e($row['order_no']) ?></td><td><?= e($row['customer_name']) ?></td><td><?= e($row['product_code']) ?></td><td><?= e($row['description']) ?></td>
                <td class="text-end text-success fw-semibold"><?= e(number_format((float)$row['qty_done'],2,',','.')) ?></td>
                <td class="text-end text-danger fw-semibold"><?= e(number_format((float)$row['qty_error'],2,',','.')) ?></td>
                <td class="text-end text-warning fw-semibold"><?= e(number_format(max((float)$row['qty_pending'],0),2,',','.')) ?></td>
                <td><span class="badge bg-<?= e($badge[$row['status']] ?? 'secondary') ?>"><?= e($row['status']) ?></span></td>
                <td><a href="/erp/modules/warehouse/oqc_delivery.php?customer_id=<?= (int)$customerId ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-truck"></i></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div></div>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
