<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'warehouse', 'production', 'manager');
$pdo = getDBConnection();

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? (string)$_GET['month'] : '';
$customerId = (int)($_GET['customer_id'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));

$where = ['1=1'];
$params = [];
if ($month !== '') { $where[] = "DATE_FORMAT(r.received_date, '%Y-%m') = ?"; $params[] = $month; }
if ($customerId > 0) { $where[] = 'r.customer_id = ?'; $params[] = $customerId; }
if (in_array($status, ['open','in_production','done'], true)) { $where[] = 'r.status = ?'; $params[] = $status; }

$customers = fetchAllSafe($pdo, 'SELECT id, customer_name FROM customers WHERE is_active = 1 ORDER BY customer_name');
$receivers = fetchAllSafe($pdo, 'SELECT id, full_name FROM users WHERE is_active = 1 ORDER BY full_name');
$productCodes = fetchAllSafe($pdo, "SELECT pc.id, pc.product_code, pc.description, pc.unit,
                             GROUP_CONCAT(DISTINCT cp.customer_id) AS customer_ids
                             FROM product_codes pc
                             LEFT JOIN customer_prices cp ON cp.product_code_id = pc.id
                             WHERE pc.is_active = 1
                             GROUP BY pc.id
                             ORDER BY pc.product_code");

$receipts = fetchAllSafe($pdo, "SELECT r.id, r.receipt_no, r.received_date, r.status, r.note,
                               c.customer_name, u.full_name AS received_by_name,
                               COUNT(ri.id) AS item_count
                               FROM iqc_receipts r
                               LEFT JOIN customers c ON c.id = r.customer_id
                               LEFT JOIN users u ON u.id = r.received_by
                               LEFT JOIN iqc_receipt_items ri ON ri.receipt_id = r.id
                               WHERE " . implode(' AND ', $where) . "
                               GROUP BY r.id
                               ORDER BY r.id DESC", $params);

$statusMap = ['open' => ['warning','open'], 'in_production' => ['info','in_production'], 'done' => ['success','done']];
$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content"><div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div><h4 class="mb-1"><i class="fas fa-file-import me-2 text-primary"></i>Nhập kho IQC</h4><p class="text-muted mb-0">Danh sách phiếu nhập IQC và tạo mới.</p></div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCreateIqc"><i class="fas fa-plus me-1"></i>Tạo phiếu IQC</button>
    </div>

    <div class="card border-0 shadow-sm mb-3"><div class="card-body py-2"><form class="row g-2" method="get">
        <div class="col-md-2"><input type="month" name="month" class="form-control form-control-sm" value="<?= e($month) ?>"></div>
        <div class="col-md-3"><select name="customer_id" class="form-select form-select-sm"><option value="">-- Khách hàng --</option><?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $customerId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['customer_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">-- Trạng thái --</option><?php foreach (['open','in_production','done'] as $st): ?><option value="<?= e($st) ?>" <?= $status === $st ? 'selected' : '' ?>><?= e($st) ?></option><?php endforeach; ?></select></div>
        <div class="col-auto"><button class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Lọc</button></div>
        <div class="col-auto"><a href="/erp/modules/warehouse/iqc_list.php" class="btn btn-sm btn-outline-secondary">Reset</a></div>
    </form></div></div>

    <div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
        <thead class="table-dark"><tr><th>Số phiếu</th><th>Ngày nhận</th><th>Khách hàng</th><th class="text-center">Số mặt hàng</th><th>Trạng thái</th><th>Người nhận</th><th>Thao tác</th></tr></thead>
        <tbody>
        <?php if (!$receipts): ?><tr><td colspan="7" class="text-center text-muted py-4">Chưa có phiếu IQC</td></tr><?php endif; ?>
        <?php foreach ($receipts as $row): ?>
            <tr>
                <td class="fw-semibold"><?= e($row['receipt_no']) ?></td>
                <td><?= e(formatDate($row['received_date'])) ?></td>
                <td><?= e($row['customer_name'] ?? '—') ?></td>
                <td class="text-center"><?= e((string)$row['item_count']) ?></td>
                <td><span class="badge bg-<?= e($statusMap[$row['status']][0] ?? 'secondary') ?>"><?= e($row['status']) ?></span></td>
                <td><?= e($row['received_by_name'] ?? '—') ?></td>
                <td>
                    <button class="btn btn-sm btn-outline-primary btn-view-items" data-id="<?= (int)$row['id'] ?>" data-no="<?= e($row['receipt_no']) ?>" data-status="<?= e($row['status']) ?>"><i class="fas fa-eye"></i></button>
                    <?php if ($row['status'] === 'open'): ?><button class="btn btn-sm btn-outline-success btn-create-order" data-id="<?= (int)$row['id'] ?>"><i class="fas fa-industry"></i></button><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div></div>
</div></div>

<div class="modal fade" id="modalCreateIqc" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Tạo phiếu nhập IQC</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <form id="formCreateIqc">
        <div class="modal-body">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Khách hàng</label><select class="form-select" name="customer_id" id="iqcCustomer" required><option value="">-- Chọn --</option><?php foreach ($customers as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['customer_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><label class="form-label">Ngày nhận</label><input type="date" class="form-control" name="received_date" value="<?= e(date('Y-m-d')) ?>" required></div>
                <div class="col-md-3"><label class="form-label">Người nhận</label><select class="form-select" name="received_by" required><option value="0">-- Chọn người nhận --</option><?php foreach ($receivers as $u): ?><option value="<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-12"><label class="form-label">Ghi chú</label><textarea class="form-control" name="note" rows="2"></textarea></div>
            </div>
            <hr>
            <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">Danh sách hàng hóa</h6><button type="button" class="btn btn-sm btn-outline-primary" id="btnAddRow"><i class="fas fa-plus me-1"></i>Thêm dòng</button></div>
            <div class="table-responsive"><table class="table table-sm align-middle" id="iqcItemsTable"><thead class="table-light"><tr><th style="width:35%">Mã hàng</th><th>Tên hàng</th><th style="width:12%">SL</th><th style="width:12%">ĐVT</th><th>Ghi chú</th><th></th></tr></thead><tbody></tbody></table></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button><button class="btn btn-primary" type="submit">Lưu phiếu IQC</button></div>
    </form>
</div></div></div>

<div class="modal fade" id="modalIqcItems" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title">Chi tiết <span id="iqcDetailNo"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><div class="table-responsive"><table class="table table-sm"><thead><tr><th>Mã hàng</th><th>Tên hàng</th><th class="text-end">Số lượng</th><th>ĐVT</th></tr></thead><tbody id="iqcDetailBody"><tr><td colspan="4" class="text-center text-muted">Đang tải...</td></tr></tbody></table></div></div>
</div></div></div>

<script>
const escHtml = (val) => String(val ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));

let cachedCustomerId = null;
let cachedProducts = [];

function buildProductOptions(products, selectedId = '') {
  if (!products.length) {
    return '<option value="">-- Chưa có sản phẩm --</option>';
  }
  return '<option value="">-- Chọn mã hàng --</option>' + products.map(pc => {
    const sel = String(selectedId) === String(pc.id) ? 'selected' : '';
    return `<option value="${escHtml(pc.id)}" data-unit="${escHtml(pc.unit || 'cái')}" data-desc="${escHtml(pc.description || '')}" ${sel}>${escHtml(pc.product_code)} — ${escHtml(pc.description)}</option>`;
  }).join('');
}

async function fetchProducts(customerId) {
  if (!customerId) return [];
  if (String(customerId) === String(cachedCustomerId)) return cachedProducts;
  const res = await fetch(`/erp/api/warehouse/get_customer_products.php?customer_id=${encodeURIComponent(customerId)}`);
  const data = await res.json();
  if (data.ok) {
    cachedCustomerId = customerId;
    cachedProducts = data.products || [];
  }
  return data.products || [];
}

async function rebuildAllProductSelects(customerId) {
  const products = await fetchProducts(customerId);
  document.querySelectorAll('#iqcItemsTable tbody select[name="items[][product_code_id]"]').forEach(sel => {
    const currentVal = sel.value;
    sel.innerHTML = buildProductOptions(products, currentVal);
    const tr = sel.closest('tr');
    const opt = sel.options[sel.selectedIndex];
    if (tr) {
      tr.querySelector('.item-desc').textContent = opt?.dataset.desc || '';
      const unitInput = tr.querySelector('input[name="items[][unit]"]');
      if (unitInput) unitInput.value = opt?.dataset.unit || 'cái';
    }
  });
}

async function addItemRow() {
  const customerId = document.getElementById('iqcCustomer').value;
  if (!customerId) {
    alert('Vui lòng chọn khách hàng trước khi thêm hàng hoá.');
    return;
  }
  const products = await fetchProducts(customerId);
  const tbody = document.querySelector('#iqcItemsTable tbody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td><select class="form-select form-select-sm" name="items[][product_code_id]" required>
      ${buildProductOptions(products)}
    </select></td>
    <td><span class="item-desc text-muted small"></span></td>
    <td><input type="number" class="form-control form-control-sm" name="items[][qty]" min="0.01" step="0.01" required style="width:90px"></td>
    <td><input type="text" class="form-control form-control-sm" name="items[][unit]" value="cái" style="width:70px" readonly></td>
    <td><input type="text" class="form-control form-control-sm" name="items[][note]" placeholder="Ghi chú"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-row"><i class="fas fa-times"></i></button></td>
  `;
  tbody.appendChild(tr);
}

document.getElementById('btnAddRow').addEventListener('click', addItemRow);

document.getElementById('iqcCustomer').addEventListener('change', function () {
  rebuildAllProductSelects(this.value);
});

document.querySelector('#iqcItemsTable tbody').addEventListener('click', function (e) {
  if (e.target.closest('.btn-remove-row')) e.target.closest('tr').remove();
});

document.querySelector('#iqcItemsTable tbody').addEventListener('change', function (e) {
  if (e.target.matches('select[name="items[][product_code_id]"]')) {
    const opt = e.target.options[e.target.selectedIndex];
    const tr = e.target.closest('tr');
    tr.querySelector('.item-desc').textContent = opt?.dataset.desc || '';
    const unitInput = tr.querySelector('input[name="items[][unit]"]');
    if (unitInput) unitInput.value = opt?.dataset.unit || 'cái';
  }
});

document.getElementById('modalCreateIqc').addEventListener('hidden.bs.modal', function () {
  document.querySelector('#iqcItemsTable tbody').innerHTML = '';
  document.getElementById('formCreateIqc').reset();
  cachedCustomerId = null;
  cachedProducts = [];
});

document.getElementById('formCreateIqc').addEventListener('submit', async function (e) {
  e.preventDefault();
  const receivedBy = document.querySelector('[name="received_by"]').value;
  if (!receivedBy || receivedBy === '0') { alert('Vui lòng chọn người nhận hàng.'); return; }
  const rows = document.querySelectorAll('#iqcItemsTable tbody tr');
  if (!rows.length) { alert('Vui lòng thêm ít nhất 1 mặt hàng.'); return; }

  const btn = this.querySelector('[type="submit"]');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';

  const fd = new FormData(this);
  try {
    const res = await fetch('/erp/api/warehouse/save_iqc.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      bootstrap.Modal.getInstance(document.getElementById('modalCreateIqc')).hide();
      const alert = document.createElement('div');
      alert.className = 'alert alert-success alert-dismissible fade show position-fixed bottom-0 end-0 m-3';
      alert.style.zIndex = 9999;
      alert.innerHTML = `✅ Đã lưu phiếu <strong>${escHtml(data.receipt_no)}</strong> — Lệnh SX: <strong>${escHtml(data.order_no)}</strong> <button type="button" class="btn-close" data-bs-dismiss="alert"></button>`;
      document.body.appendChild(alert);
      setTimeout(() => location.reload(), 2000);
    } else {
      alert('Lỗi: ' + (data.msg || 'Không thể lưu phiếu IQC'));
    }
  } catch (err) {
    alert('Lỗi kết nối: ' + err.message);
  } finally {
    btn.disabled = false;
    btn.innerHTML = 'Lưu phiếu IQC';
  }
});

document.querySelectorAll('.btn-view-items').forEach(btn=>btn.addEventListener('click', async function(){
  document.getElementById('iqcDetailNo').textContent = this.dataset.no;
  const body = document.getElementById('iqcDetailBody');
  body.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Đang tải...</td></tr>';
  const res = await fetch('/erp/api/warehouse/get_iqc_items.php?receipt_id='+this.dataset.id);
  const data = await res.json();
  if(data.ok && data.items.length){
    body.innerHTML = data.items.map(it=>`<tr><td>${escHtml(it.product_code)}</td><td>${escHtml(it.description)}</td><td class="text-end">${Number(it.qty).toLocaleString('vi-VN')}</td><td>${escHtml(it.unit)}</td></tr>`).join('');
  }else{
    body.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Không có dữ liệu</td></tr>';
  }
  new bootstrap.Modal(document.getElementById('modalIqcItems')).show();
}));

document.querySelectorAll('.btn-create-order').forEach(btn=>btn.addEventListener('click', function(){
  alert('Lệnh sản xuất đã được tạo tự động khi lưu IQC.');
}));
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
