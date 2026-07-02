<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director','accountant','manager');

$pdo = getDBConnection();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$inv = $pdo->prepare("
    SELECT i.*, c.customer_name, c.customer_code, c.address, c.phone,
           u.full_name AS created_by_name
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    LEFT JOIN users u     ON i.created_by  = u.id
    WHERE i.id = ?
");
$inv->execute([$id]);
$inv = $inv->fetch(PDO::FETCH_ASSOC);
if (!$inv) { header('Location: index.php'); exit; }

$items = $pdo->prepare("
    SELECT ii.*, pc.product_code
    FROM invoice_items ii
    JOIN product_codes pc ON ii.product_code_id = pc.id
    WHERE ii.invoice_id = ?
    ORDER BY ii.id
");
$items->execute([$id]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

// Lịch sử thanh toán từ debt_payments
$payments = $pdo->prepare("
    SELECT dp.*, u.full_name AS created_by_name
    FROM debt_payments dp
    LEFT JOIN users u ON dp.created_by = u.id
    WHERE dp.invoice_id = ?
    ORDER BY dp.payment_date DESC
");
$payments->execute([$id]);
$payments = $payments->fetchAll(PDO::FETCH_ASSOC);

$totalPaid = array_sum(array_column($payments, 'amount'));
$debt      = $inv['total_amount'] - $totalPaid;

// debt_tracking
$dt = $pdo->prepare("SELECT * FROM debt_tracking WHERE invoice_id = ?");
$dt->execute([$id]);
$dt = $dt->fetch(PDO::FETCH_ASSOC);

$csrf = generateCSRF();
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <a href="index.php" class="btn btn-sm btn-outline-secondary me-2">
                <i class="fas fa-arrow-left"></i>
            </a>
            <span class="fs-5 fw-bold">
                <i class="fas fa-file-invoice me-2 text-primary"></i>
                <?= htmlspecialchars($inv['invoice_no']) ?>
            </span>
        </div>
        <div class="d-flex gap-2">
            <a href="/erp/api/invoice/print_invoice.php?id=<?= $id ?>"
               target="_blank" class="btn btn-outline-secondary">
                <i class="fas fa-print me-1"></i>In hoá đơn
            </a>
            <button class="btn btn-outline-warning btn-vat-detail"
                    data-id="<?= $id ?>"
                    data-bkav="<?= htmlspecialchars($inv['bkav_invoice_no'] ?? '') ?>"
                    title="Xuất hoá đơn VAT BKAV">
                <?php if (!empty($inv['bkav_invoice_no'])): ?>
                    <i class="fas fa-check-circle text-success me-1"></i>BKAV: <?= htmlspecialchars($inv['bkav_invoice_no']) ?>
                <?php else: ?>
                    <i class="fas fa-file-invoice-dollar me-1"></i>Xuất VAT BKAV
                <?php endif; ?>
            </button>
            <?php if ($debt > 0): ?>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalPay">
                <i class="fas fa-money-bill-wave me-1"></i>Thu tiền
            </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3">
        <!-- Thông tin HĐ -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-info-circle me-2 text-primary"></i>Thông tin hoá đơn
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><td class="text-muted w-40">Số HĐ</td>
                            <td class="fw-bold text-primary"><?= htmlspecialchars($inv['invoice_no']) ?></td></tr>
                        <tr><td class="text-muted">Ngày HĐ</td>
                            <td><?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></td></tr>
                        <tr><td class="text-muted">Hạn TT</td>
                            <td><?= $inv['due_date'] ? date('d/m/Y', strtotime($inv['due_date'])) : '—' ?></td></tr>
                        <tr><td class="text-muted">Trạng thái</td>
                            <td><?php
                                $st = ['draft'=>['secondary','Nháp'],'confirmed'=>['success','Đã xác nhận'],'cancelled'=>['danger','Huỷ']];
                                $s  = $st[$inv['status']] ?? ['secondary','?'];
                                echo "<span class='badge bg-{$s[0]}'>{$s[1]}</span>";
                            ?></td></tr>
                        <tr><td class="text-muted">Người tạo</td>
                            <td><?= htmlspecialchars($inv['created_by_name'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Ghi chú</td>
                            <td><?= htmlspecialchars($inv['note'] ?? '—') ?></td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Thông tin KH + Công nợ -->
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-user me-2 text-success"></i>Khách hàng & Công nợ
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><td class="text-muted w-40">Tên KH</td>
                            <td class="fw-bold"><?= htmlspecialchars($inv['customer_name'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Mã KH</td>
                            <td><?= htmlspecialchars($inv['customer_code'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Địa chỉ</td>
                            <td><?= htmlspecialchars($inv['address'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">SĐT</td>
                            <td><?= htmlspecialchars($inv['phone'] ?? '—') ?></td></tr>
                        <tr><td class="text-muted">Tổng tiền</td>
                            <td class="fw-bold"><?= number_format($inv['total_amount']) ?> đ</td></tr>
                        <tr><td class="text-muted">Đã thu</td>
                            <td class="text-success fw-bold"><?= number_format($totalPaid) ?> đ</td></tr>
                        <tr><td class="text-muted">Còn nợ</td>
                            <td class="fw-bold <?= $debt > 0 ? 'text-danger' : 'text-success' ?>">
                                <?= number_format($debt) ?> đ
                            </td></tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Chi tiết SP -->
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-list me-2 text-warning"></i>Chi tiết sản phẩm
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>Mã SP</th>
                                    <th>Mô tả</th>
                                    <th>ĐVT</th>
                                    <th class="text-end">SL</th>
                                    <th class="text-end">Đơn giá</th>
                                    <th class="text-end">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php $i=1; foreach ($items as $it): ?>
                            <tr>
                                <td class="text-muted"><?= $i++ ?></td>
                                <td><span class="badge bg-primary"><?= htmlspecialchars($it['product_code']) ?></span></td>
                                <td><?= htmlspecialchars($it['description']) ?></td>
                                <td><?= htmlspecialchars($it['unit']) ?></td>
                                <td class="text-end fw-bold"><?= number_format($it['quantity']) ?></td>
                                <td class="text-end"><?= number_format($it['unit_price']) ?> đ</td>
                                <td class="text-end fw-bold text-success"><?= number_format($it['total_price']) ?> đ</td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="4" class="text-end fw-bold">Tổng cộng:</td>
                                    <td class="text-end fw-bold">
                                        <?= number_format(array_sum(array_column($items,'quantity'))) ?>
                                    </td>
                                    <td></td>
                                    <td class="text-end fw-bold text-success fs-6">
                                        <?= number_format($inv['total_amount']) ?> đ
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lịch sử thanh toán -->
        <?php if (!empty($payments)): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-bold">
                    <i class="fas fa-history me-2 text-success"></i>Lịch sử thanh toán
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="table-dark">
                                <tr>
                                    <th>Ngày thu</th>
                                    <th class="text-end">Số tiền</th>
                                    <th>Hình thức</th>
                                    <th>Số CT</th>
                                    <th>Người thu</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($payments as $pay): ?>
                            <tr>
                                <td><?= date('d/m/Y', strtotime($pay['payment_date'])) ?></td>
                                <td class="text-end fw-bold text-success">
                                    <?= number_format($pay['amount']) ?> đ
                                </td>
                                <td>
                                    <?php
                                    $pm = ['cash'=>['secondary','Tiền mặt'],'transfer'=>['info','Chuyển khoản'],'other'=>['warning','Khác']];
                                    $m  = $pm[$pay['payment_method']] ?? ['secondary','?'];
                                    echo "<span class='badge bg-{$m[0]}'>{$m[1]}</span>";
                                    ?>
                                </td>
                                <td class="text-muted"><?= htmlspecialchars($pay['reference_no'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($pay['created_by_name'] ?? '—') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- Modal thu tiền -->
<div class="modal fade" id="modalPay" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="fas fa-money-bill-wave me-2"></i>Ghi nhận thanh toán
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formPay">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="invoice_id" value="<?= $id ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Ngày thu <span class="text-danger">*</span></label>
                        <input type="date" name="payment_date" class="form-control"
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Số tiền thu <span class="text-danger">*</span></label>
                        <input type="number" name="amount" class="form-control"
                               value="<?= $debt ?>" min="1" max="<?= $debt ?>" required>
                        <div class="form-text text-danger">
                            Còn nợ: <?= number_format($debt) ?> đ
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Hình thức</label>
                        <select name="payment_method" class="form-select">
                            <option value="cash">Tiền mặt</option>
                            <option value="transfer">Chuyển khoản</option>
                            <option value="other">Khác</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Số chứng từ / Ghi chú</label>
                        <input type="text" name="note" class="form-control"
                               placeholder="Số chứng từ, diễn giải...">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Huỷ</button>
                <button type="button" class="btn btn-success" id="btnSavePay">
                    <i class="fas fa-save me-1"></i>Lưu
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?= json_encode($csrf) ?>;

document.getElementById('btnSavePay')?.addEventListener('click', () => {
    const form = document.getElementById('formPay');
    if (!form.checkValidity()) { form.reportValidity(); return; }
    const btn = document.getElementById('btnSavePay');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang lưu...';
    fetch('/erp/api/invoice/save_payment.php', { method:'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            bootstrap.Modal.getInstance(document.getElementById('modalPay')).hide();
            location.reload();
        } else { alert('Lỗi: ' + res.msg); }
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save me-1"></i>Lưu';
    });
});

document.querySelector('.btn-vat-detail').addEventListener('click', async function() {
    const invId  = this.dataset.id;
    const bkavNo = this.dataset.bkav;
    if (bkavNo) {
        if (!confirm(`Hoá đơn này đã xuất BKAV: ${bkavNo}\nXuất lại?`)) return;
    }
    try {
        const res = await fetch(`/erp/api/invoice/push_invoice.php?preview=1&invoice_id=${invId}`).then(r => r.json());
        if (!res.success) { alert('Lỗi: ' + res.message); return; }
        renderVatPreviewDetail(res.preview, invId);
        new bootstrap.Modal(document.getElementById('modalVAT')).show();
    } catch (err) {
        alert('Lỗi kết nối khi tải preview.');
    }
});

function renderVatPreviewDetail(p, invId) {
    document.getElementById('vatInvoiceNo').textContent     = p.invoice_no || '';
    document.getElementById('vatInvoiceDate').textContent   = fmtDate(p.invoice_date);
    document.getElementById('vatSellerName').textContent    = p.seller_name || '';
    document.getElementById('vatSellerTax').textContent     = p.seller_tax  || '';
    document.getElementById('vatSellerAddress').textContent = p.seller_address || '';
    document.getElementById('vatBuyerName').textContent     = p.contact_person || p.customer_name || '';
    document.getElementById('vatBuyerUnit').textContent     = p.customer_name  || '';
    document.getElementById('vatBuyerAddress').textContent  = p.address     || '';
    document.getElementById('vatBuyerTax').textContent      = p.tax_code    || '';
    document.getElementById('vatBuyerBank').textContent     = p.bank_account ? `${p.bank_account} – ${p.bank_name}` : '';

    let rowsHtml = '';
    (p.items || []).forEach((it, i) => {
        rowsHtml += `<tr>
            <td>${i + 1}</td>
            <td>${esc(it.description)}</td>
            <td class="text-center">${esc(it.unit)}</td>
            <td class="text-end">${fmtNum(it.quantity)}</td>
            <td class="text-end">${fmtMoney(it.unit_price)}</td>
            <td class="text-end fw-bold">${fmtMoney(it.total_price)}</td>
        </tr>`;
    });
    document.getElementById('vatItemsBody').innerHTML = rowsHtml;

    document.getElementById('vatSubtotal').textContent  = fmtMoney(p.subtotal);
    document.getElementById('vatVatRate').textContent   = (p.vat_rate || 0) + '%';
    document.getElementById('vatVatAmount').textContent = fmtMoney(p.vat_amount);
    document.getElementById('vatTotal').textContent     = fmtMoney(p.total_amount);
    document.getElementById('vatWords').textContent     = p.total_words || '';

    document.getElementById('btnConfirmVAT').dataset.invId = invId;
}

async function pushToBAKV(invId) {
    const btn = document.getElementById('btnConfirmVAT');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Đang gửi BKAV...';
    try {
        const res = await fetch('/erp/api/invoice/push_invoice.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ invoice_id: parseInt(invId), csrf_token: CSRF_TOKEN })
        }).then(r => r.json());
        if (res.success) {
            bootstrap.Modal.getInstance(document.getElementById('modalVAT')).hide();
            alert('✅ Xuất hoá đơn thành công! Số HĐ BKAV: ' + res.invoiceNo);
            location.reload();
        } else {
            alert('❌ Lỗi: ' + res.message);
        }
    } catch (err) {
        alert('Lỗi kết nối khi gửi BKAV.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Xác nhận xuất BKAV';
    }
}

function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
}
function fmtNum(v) {
    return Number(v || 0).toLocaleString('vi-VN');
}
function fmtMoney(v) {
    return Number(v || 0).toLocaleString('vi-VN') + ' đ';
}
function fmtDate(d) {
    if (!d) return '';
    const [y, m, day] = d.split('-');
    return `${day}/${m}/${y}`;
}
</script>

<!-- ============ MODAL VAT BKAV ============ -->
<div class="modal fade" id="modalVAT" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <h5 class="modal-title">
                    <i class="fas fa-file-invoice-dollar me-2"></i>Preview xuất hoá đơn VAT BKAV
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <span class="text-muted small">Số HĐ nội bộ:</span>
                        <strong id="vatInvoiceNo" class="ms-1"></strong>
                    </div>
                    <div class="col-md-6">
                        <span class="text-muted small">Ngày HĐ:</span>
                        <strong id="vatInvoiceDate" class="ms-1"></strong>
                    </div>
                </div>
                <div class="card border-0 bg-light mb-3">
                    <div class="card-body py-2">
                        <div class="fw-bold small text-uppercase text-muted mb-1">Người bán</div>
                        <div><strong id="vatSellerName"></strong></div>
                        <div class="small text-muted">MST: <span id="vatSellerTax"></span></div>
                        <div class="small text-muted"><span id="vatSellerAddress"></span></div>
                    </div>
                </div>
                <div class="card border-0 bg-light mb-3">
                    <div class="card-body py-2">
                        <div class="fw-bold small text-uppercase text-muted mb-1">Người mua</div>
                        <div><strong id="vatBuyerName"></strong> – <span id="vatBuyerUnit"></span></div>
                        <div class="small text-muted"><span id="vatBuyerAddress"></span></div>
                        <div class="small text-muted">MST: <span id="vatBuyerTax"></span></div>
                        <div class="small text-muted">TK: <span id="vatBuyerBank"></span></div>
                    </div>
                </div>
                <div class="table-responsive mb-3">
                    <table class="table table-bordered table-sm align-middle mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Tên hàng hoá / dịch vụ</th>
                                <th class="text-center">ĐVT</th>
                                <th class="text-end">Số lượng</th>
                                <th class="text-end">Đơn giá</th>
                                <th class="text-end">Thành tiền</th>
                            </tr>
                        </thead>
                        <tbody id="vatItemsBody"></tbody>
                    </table>
                </div>
                <div class="d-flex flex-column align-items-end gap-1 mb-2">
                    <div class="small">Tạm tính: <strong id="vatSubtotal"></strong></div>
                    <div class="small">VAT (<span id="vatVatRate"></span>): <strong id="vatVatAmount"></strong></div>
                    <div class="fs-6 fw-bold text-success">Tổng cộng: <span id="vatTotal"></span></div>
                </div>
                <div class="alert alert-light border py-2 small">
                    <i class="fas fa-info-circle me-1 text-muted"></i>
                    Bằng chữ: <em id="vatWords"></em>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Đóng
                </button>
                <button type="button" class="btn btn-warning" id="btnConfirmVAT"
                        onclick="pushToBAKV(this.dataset.invId)">
                    <i class="fas fa-paper-plane me-1"></i>Xác nhận xuất BKAV
                </button>
            </div>
        </div>
    </div>
</div>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>