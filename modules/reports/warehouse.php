<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant', 'manager', 'warehouse');

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">

  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
      <h4 class="mb-1"><i class="fas fa-warehouse me-2 text-info"></i>Báo cáo Kho vật tư</h4>
      <p class="text-muted mb-0">Tổng quan tồn kho và cảnh báo vật tư</p>
    </div>
    <button class="btn btn-info btn-sm text-white" onclick="loadAll()">
      <i class="fas fa-sync-alt me-1"></i>Làm mới
    </button>
  </div>

  <!-- KPI Cards -->
  <div class="row g-3 mb-4">
    <?php
    $kpis = [
      ['id'=>'kpiTotal',    'label'=>'Tổng mặt hàng vật tư',   'icon'=>'fa-list-alt',       'color'=>'primary'],
      ['id'=>'kpiImport',   'label'=>'Nhập hôm nay',            'icon'=>'fa-file-import',    'color'=>'success'],
      ['id'=>'kpiExport',   'label'=>'Xuất hôm nay',            'icon'=>'fa-file-export',    'color'=>'warning'],
      ['id'=>'kpiLow',      'label'=>'Vật tư sắp hết',          'icon'=>'fa-exclamation-triangle','color'=>'danger'],
      ['id'=>'kpiWaiting',  'label'=>'Thành phẩm chờ giao',     'icon'=>'fa-truck',          'color'=>'info'],
      ['id'=>'kpiSlow',     'label'=>'Hàng tồn lâu (>30 ngày)', 'icon'=>'fa-clock',          'color'=>'secondary'],
    ];
    foreach ($kpis as $k): ?>
    <div class="col-6 col-md-4 col-lg-2">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small mb-1"><?= $k['label'] ?></div>
          <div class="fs-4 fw-bold text-<?= $k['color'] ?>" id="<?= $k['id'] ?>Val">—</div>
          <div class="mt-2 rounded-circle bg-<?= $k['color'] ?> bg-opacity-10 p-2 d-inline-block">
            <i class="fas <?= $k['icon'] ?> text-<?= $k['color'] ?>"></i>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3 mb-4">
    <!-- Nhập/Xuất theo ngày -->
    <div class="col-md-8">
      <div class="card border-0 shadow-sm">
        <div class="card-body">
          <h6 class="card-title mb-3"><i class="fas fa-chart-bar me-2 text-info"></i>Nhập / Xuất kho 30 ngày gần nhất</h6>
          <canvas id="chartDaily" height="90"></canvas>
        </div>
      </div>
    </div>
    <!-- Top vật tư tồn nhiều -->
    <div class="col-md-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <h6 class="card-title mb-3"><i class="fas fa-boxes me-2 text-primary"></i>Top vật tư tồn nhiều</h6>
          <canvas id="chartTopStock" height="220"></canvas>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-4">
    <!-- Vật tư dưới Min -->
    <div class="col-md-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <h6 class="card-title mb-2 text-danger"><i class="fas fa-exclamation-circle me-2"></i>Vật tư dưới Min</h6>
          <div id="lowStockList" class="overflow-auto" style="max-height:300px;">
            <div class="text-center text-muted py-3">Đang tải...</div>
          </div>
        </div>
      </div>
    </div>
    <!-- Thành phẩm tồn lâu -->
    <div class="col-md-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <h6 class="card-title mb-2 text-warning"><i class="fas fa-clock me-2"></i>Thành phẩm tồn lâu (&gt;30 ngày)</h6>
          <div id="oldFinishedList" class="overflow-auto" style="max-height:300px;">
            <div class="text-center text-muted py-3">Đang tải...</div>
          </div>
        </div>
      </div>
    </div>
    <!-- Vật tư không giao dịch 30 ngày -->
    <div class="col-md-4">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <h6 class="card-title mb-2 text-secondary"><i class="fas fa-minus-circle me-2"></i>Không giao dịch 30 ngày</h6>
          <div id="inactiveList" class="overflow-auto" style="max-height:300px;">
            <div class="text-center text-muted py-3">Đang tải...</div>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
let chartDailyInstance = null;
let chartStockInstance = null;

function loadAll() {
  fetch('/erp/api/reports/warehouse.php')
    .then(r => r.json())
    .then(d => {
      if (!d.ok) return;
      const k = d.kpi;
      document.getElementById('kpiTotalVal').textContent   = k.total_items + ' mặt hàng';
      document.getElementById('kpiImportVal').textContent  = parseFloat(k.import_today).toLocaleString();
      document.getElementById('kpiExportVal').textContent  = parseFloat(k.export_today).toLocaleString();
      document.getElementById('kpiLowVal').textContent     = k.low_stock_count + ' mặt hàng';
      document.getElementById('kpiWaitingVal').textContent = k.waiting_delivery + ' lô';
      document.getElementById('kpiSlowVal').textContent    = k.slow_moving + ' mặt hàng';

      // Chart Nhập/Xuất theo ngày
      const labels  = d.chart_daily.map(r => r.day);
      const imports = d.chart_daily.map(r => parseFloat(r.import_qty));
      const exports = d.chart_daily.map(r => parseFloat(r.export_qty));
      if (chartDailyInstance) chartDailyInstance.destroy();
      chartDailyInstance = new Chart(document.getElementById('chartDaily'), {
        type: 'bar',
        data: {
          labels,
          datasets: [
            { label: 'Nhập',  data: imports, backgroundColor: '#22C55E' },
            { label: 'Xuất',  data: exports, backgroundColor: '#EF4444' },
          ]
        },
        options: {
          responsive: true,
          plugins: { legend: { position: 'top' } },
        }
      });

      // Chart Top vật tư tồn nhiều
      const stockLabels = d.top_stock.map(r => r.item_name);
      const stockAmts   = d.top_stock.map(r => parseFloat(r.remaining));
      if (chartStockInstance) chartStockInstance.destroy();
      chartStockInstance = new Chart(document.getElementById('chartTopStock'), {
        type: 'bar',
        data: {
          labels: stockLabels,
          datasets: [{ label: 'Tồn kho', data: stockAmts, backgroundColor: '#3B82F6' }]
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          plugins: { legend: { display: false } },
        }
      });

      // Vật tư dưới min
      const lowDiv = document.getElementById('lowStockList');
      if (!d.low_stock_list.length) {
        lowDiv.innerHTML = '<div class="alert alert-success py-2"><i class="fas fa-check-circle me-2"></i>Không có vật tư dưới min</div>';
      } else {
        lowDiv.innerHTML = d.low_stock_list.map(r =>
          `<div class="d-flex justify-content-between align-items-center border-bottom py-2">
            <div><small class="fw-bold">${r.item_name}</small></div>
            <div class="text-end">
              <span class="badge bg-danger">${parseFloat(r.current_stock).toLocaleString()}</span>
              <small class="text-muted ms-1">/ min ${parseFloat(r.min_stock).toLocaleString()}</small>
            </div>
          </div>`
        ).join('');
      }

      // Thành phẩm tồn lâu
      const oldDiv = document.getElementById('oldFinishedList');
      if (!d.old_finished.length) {
        oldDiv.innerHTML = '<div class="alert alert-success py-2"><i class="fas fa-check-circle me-2"></i>Không có thành phẩm tồn lâu</div>';
      } else {
        oldDiv.innerHTML = d.old_finished.map(r =>
          `<div class="d-flex justify-content-between align-items-center border-bottom py-2">
            <div><small class="fw-bold">${r.lot_no || r.product_code}</small></div>
            <small class="text-muted">${r.created_at ? r.created_at.substring(0,10) : ''}</small>
          </div>`
        ).join('');
      }

      // Vật tư không giao dịch
      const inactDiv = document.getElementById('inactiveList');
      if (!d.inactive_items.length) {
        inactDiv.innerHTML = '<div class="alert alert-success py-2"><i class="fas fa-check-circle me-2"></i>Không có vật tư bất động</div>';
      } else {
        inactDiv.innerHTML = d.inactive_items.map(r =>
          `<div class="d-flex justify-content-between align-items-center border-bottom py-2">
            <small class="fw-bold">${r.item_name}</small>
            <small class="text-muted">${r.last_transaction ? r.last_transaction.substring(0,10) : 'Chưa GD'}</small>
          </div>`
        ).join('');
      }
    })
    .catch(() => {});
}

document.addEventListener('DOMContentLoaded', loadAll);
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
