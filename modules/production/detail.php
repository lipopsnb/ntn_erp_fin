<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'warehouse', 'production', 'manager');
$pdo = getDBConnection();

$id = (int)($_GET['id'] ?? 0);
$order = fetchOneSafe($pdo, "SELECT o.*, r.receipt_no, c.customer_name
                            FROM production_orders o
                            JOIN iqc_receipts r ON r.id = o.iqc_receipt_id
                            JOIN customers c ON c.id = r.customer_id
                            WHERE o.id = ?", [$id]);
if (!$order) { redirect('/erp/modules/production/index.php'); }

$items = fetchAllSafe($pdo, "SELECT pi.*, iri.unit, pc.product_code, pc.description
                           FROM production_items pi
                           JOIN iqc_receipt_items iri ON iri.id = pi.iqc_item_id
                           JOIN product_codes pc ON pc.id = iri.product_code_id
                           WHERE pi.order_id = ?
                           ORDER BY pi.id", [$id]);
$csrf = generateCSRF();
$badge = ['in_progress'=>'warning','done'=>'success','error'=>'danger'];
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content"><div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4"><div><h4 class="mb-1"><i class="fas fa-tasks me-2 text-primary"></i>Chi tiết tiến độ gia công</h4><p class="text-muted mb-0">Lệnh <?= e($order['order_no']) ?> | Khách hàng: <?= e($order['customer_name']) ?> | IQC: <?= e($order['receipt_no']) ?></p></div></div>

    <div class="card border-0 shadow-sm"><div class="card-body">
        <form id="formProgress">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="order_id" value="<?= (int)$id ?>">
            <div class="table-responsive"><table class="table table-hover align-middle">
                <thead class="table-dark"><tr><th>Mã hàng</th><th>Tên hàng</th><th>ĐVT</th><th class="text-end">SL Tổng</th><th class="text-end text-success">SL Hoàn thành</th><th class="text-end text-danger">SL Lỗi</th><th class="text-end text-warning">SL Chưa HT</th><th>Công đoạn</th><th>Ghi chú</th><th>Trạng thái</th></tr></thead>
                <tbody>
                <?php foreach($items as $idx => $it): $pending = (float)$it['qty_total'] - (float)$it['qty_done'] - (float)$it['qty_error']; ?>
                    <tr>
                        <td><?= e($it['product_code']) ?><input type="hidden" name="items[<?= $idx ?>][id]" value="<?= (int)$it['id'] ?>"></td>
                        <td><?= e($it['description']) ?></td>
                        <td><?= e($it['unit']) ?></td>
                        <td class="text-end qty-total"><?= e(number_format((float)$it['qty_total'],2,'.','')) ?></td>
                        <td><input type="number" min="0" step="0.01" class="form-control form-control-sm text-end qty-done" name="items[<?= $idx ?>][qty_done]" value="<?= e(number_format((float)$it['qty_done'],2,'.','')) ?>"></td>
                        <td><input type="number" min="0" step="0.01" class="form-control form-control-sm text-end qty-error" name="items[<?= $idx ?>][qty_error]" value="<?= e(number_format((float)$it['qty_error'],2,'.','')) ?>"></td>
                        <td class="text-end text-warning fw-semibold qty-pending"><?= e(number_format(max($pending,0),2,'.','')) ?></td>
                        <td><input type="text" class="form-control form-control-sm" name="items[<?= $idx ?>][stage]" value="<?= e($it['stage']) ?>"></td>
                        <td><input type="text" class="form-control form-control-sm" name="items[<?= $idx ?>][note]" value="<?= e($it['note']) ?>"></td>
                        <td>
                            <select name="items[<?= $idx ?>][status]" class="form-select form-select-sm">
                                <?php foreach(['in_progress','done','error'] as $st): ?><option value="<?= e($st) ?>" <?= $it['status']===$st?'selected':'' ?>><?= e($st) ?></option><?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <button class="btn btn-primary" type="submit"><i class="fas fa-save me-1"></i>Lưu tiến độ</button>
            <a href="/erp/modules/production/index.php" class="btn btn-outline-secondary">Quay lại</a>
        </form>
    </div></div>
</div></div>
<script>
function updateRowPending(tr){
  const total = Number(tr.querySelector('.qty-total').textContent || 0);
  const done = Number(tr.querySelector('.qty-done').value || 0);
  const err = Number(tr.querySelector('.qty-error').value || 0);
  const pending = total - done - err;
  tr.querySelector('.qty-pending').textContent = pending.toFixed(2);
  tr.classList.toggle('table-danger', err > 0);
}
document.querySelectorAll('tbody tr').forEach(tr=>{
  tr.querySelectorAll('.qty-done,.qty-error').forEach(inp=>inp.addEventListener('input', ()=>updateRowPending(tr)));
  updateRowPending(tr);
});
document.getElementById('formProgress').addEventListener('submit', async function(e){
  e.preventDefault();
  let valid = true;
  document.querySelectorAll('tbody tr').forEach(tr=>{
    const total = Number(tr.querySelector('.qty-total').textContent || 0);
    const done = Number(tr.querySelector('.qty-done').value || 0);
    const err = Number(tr.querySelector('.qty-error').value || 0);
    if (done + err > total) valid = false;
  });
  if(!valid){ alert('SL hoàn thành + lỗi không được vượt quá SL tổng'); return; }
  const res = await fetch('/erp/api/production/save_progress.php', {method:'POST', body:new FormData(this)});
  const data = await res.json();
  if(data.ok){ alert('Đã lưu tiến độ'); location.reload(); }
  else alert(data.msg || 'Không thể lưu');
});
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
