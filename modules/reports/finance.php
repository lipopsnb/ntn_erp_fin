<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/erp/config/functions.php';
requireLogin();
requireRole('director', 'accountant');

include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/header.php';
include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/sidebar.php';
?>
<div class="main-content">
<div class="container-fluid py-4">

  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
      <h4 class="mb-1"><i class="fas fa-chart-line me-2 text-primary"></i>Báo cáo Tài chính</h4>
      <p class="text-muted mb-0">Doanh thu, chi phí, công nợ</p>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <input type="date" id="dateFrom" class="form-control form-control-sm" value="<?= date('Y-m-01') ?>" style="width:140px;">
      <input type="date" id="dateTo"   class="form-control form-control-sm" value="<?= date('Y-m-d') ?>"  style="width:140px;">
      <button class="btn btn-primary btn-sm" onclick="loadAll()">
        <i class="fas fa-sync-alt me-1"></i>Làm mới
      </button>
    </div>
  </div>

  <!-- KPI Cards -->
  <div class="row g-3 mb-4">
    <?php
    $kpis = [
      ['id'=>'kpiRevenue',  'label'=>'Doanh thu tháng',       'icon'=>'fa-dollar-sign',       'color'=>'primary'],
      ['id'=>'kpiExpense',  'label'=>'Chi phí tháng',          'icon'=>'fa-receipt',           'color'=>'warning'],
      ['id'=>'kpiProfit',   'label'=>'Lợi nhuận tạm tính',     'icon'=>'fa-chart-line',        'color'=>'success'],
      ['id'=>'kpiDebt',     'label'=>'Công nợ phải thu',       'icon'=>'fa-hand-holding-usd',  'color'=>'danger'],
      ['id'=>'kpiUnpaid',   'label'=>'HĐ chưa thanh toán',     'icon'=>'fa-file-invoice',      'color'=>'secondary'],
      ['id'=>'kpiPaid',     'label'=>'Đã thu tháng',           'icon'=>'fa-money-bill-wave',   'color'=>'info'],
    ];
    foreach ($kpis as $k): ?>
    <div class="col-6 col-md-4 col-lg-2">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <div class="text-muted small mb-1"><?= $k['label'] ?></div>
          <div class="fs-5 fw-bold text-<?= $k['color'] ?>" id="<?= $k['id'] ?>Val">—</div>
          <div class="mt-2 rounded-circle bg-<?= $k['color'] ?> bg-opacity-10 p-2 d-inline-block">
            <i class="fas <?= $k['icon'] ?> text-<?= $k['color'] ?>"></i>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="row g-3 mb-4">
    <!-- Doanh thu 12 tháng -->
    <div class="col-md-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <h6 class="card-title mb-3"><i class="fas fa-chart-line me-2 text-primary"></i>Doanh thu 12 tháng</h6>
          <canvas id="chartRevenue" height="130"></canvas>
        </div>
      </div>
    </div>
    <!-- Chi phí theo nhóm -->
    <div class="col-md-3">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <h6 class="card-title mb-3"><i class="fas fa-chart-pie me-2 text-warning"></i>Chi phí theo nhóm</h6>
          <canvas id="chartExpense" height="200"></canvas>
        </div>
      </div>
    </div>
    <!-- Công nợ theo KH -->
    <div class="col-md-3">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
          <h6 class="card-title mb-3"><i class="fas fa-chart-bar me-2 text-danger"></i>Công nợ theo khách hàng</h6>
          <canvas id="chartDebt" height="200"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Top 10 khách hàng còn nợ -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
      <h6 class="card-title mb-3"><i class="fas fa-list me-2 text-danger"></i>Top 10 khách hàng còn nợ</h6>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>#</th>
              <th>Khách hàng</th>
              <th class="text-end">Tổng nợ</th>
              <th>Hạn lâu nhất</th>
              <th>Quá hạn</th>
            </tr>
          </thead>
          <tbody id="topDebtorsBody">
            <tr><td colspan="5" class="text-center text-muted py-3">Đang tải...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
let chartRevInstance = null;
let chartExpInstance = null;
let chartDebtInstance = null;

function fmt(n) {
  n = parseFloat(n);
  if (n >= 1e9) return (n/1e9).toFixed(1) + ' tỷ';
  if (n >= 1e6) return Math.round(n/1e6) + ' triệu';
  return n.toLocaleString() + ' đ';
}

function buildParams() {
  return new URLSearchParams({
    date_from: document.getElementById('dateFrom').value,
    date_to:   document.getElementById('dateTo').value,
  }).toString();
}

function loadAll() {
  fetch('/erp/api/reports/finance.php?' + buildParams())
    .then(r => r.json())
    .then(d => {
      if (!d.ok) return;
      const k = d.kpi;
      document.getElementById('kpiRevenueVal').textContent = k.revenue_fmt;
      document.getElementById('kpiExpenseVal').textContent = k.expense_fmt;
      document.getElementById('kpiProfitVal').textContent  = k.profit_fmt;
      const profitEl = document.getElementById('kpiProfitVal');
      profitEl.textContent = k.profit_fmt;
      profitEl.className = 'fs-5 fw-bold ' + (parseFloat(k.profit) >= 0 ? 'text-success' : 'text-danger');
      document.getElementById('kpiDebtVal').textContent   = k.debt_fmt;
      document.getElementById('kpiUnpaidVal').textContent = k.unpaid_count + ' HĐ';
      document.getElementById('kpiPaidVal').textContent   = k.paid_month_fmt;

      // Chart doanh thu 12 tháng
      const revLabels  = d.chart_revenue.map(r => r.month);
      const revAmounts = d.chart_revenue.map(r => parseFloat(r.amount));
      if (chartRevInstance) chartRevInstance.destroy();
      chartRevInstance = new Chart(document.getElementById('chartRevenue'), {
        type: 'line',
        data: {
          labels: revLabels,
          datasets: [{
            label: 'Doanh thu',
            data: revAmounts,
            borderColor: '#3B82F6',
            backgroundColor: 'rgba(59,130,246,0.08)',
            fill: true,
            tension: 0.3,
            pointRadius: 4,
          }]
        },
        options: {
          responsive: true,
          plugins: { legend: { display: false } },
          scales: {
            y: { ticks: { callback: v => v >= 1e9 ? (v/1e9).toFixed(1)+'tỷ' : v >= 1e6 ? (v/1e6).toFixed(0)+'tr' : v } }
          }
        }
      });

      // Chart chi phí theo nhóm (Doughnut)
      const expLabels = d.chart_expense.map(r => r.category_name);
      const expAmts   = d.chart_expense.map(r => parseFloat(r.total));
      const expColors = ['#3B82F6','#22C55E','#FACC15','#EF4444','#8B5CF6','#F97316','#14B8A6','#EC4899','#94A3B8','#06B6D4'];
      if (chartExpInstance) chartExpInstance.destroy();
      chartExpInstance = new Chart(document.getElementById('chartExpense'), {
        type: 'doughnut',
        data: {
          labels: expLabels,
          datasets: [{ data: expAmts, backgroundColor: expColors }]
        },
        options: {
          responsive: true,
          plugins: { legend: { position: 'bottom', labels: { font: { size: 10 } } } }
        }
      });

      // Chart công nợ theo KH (Bar ngang)
      const debtLabels = d.chart_debt.map(r => r.customer_name);
      const debtAmts   = d.chart_debt.map(r => parseFloat(r.debt_amount));
      if (chartDebtInstance) chartDebtInstance.destroy();
      chartDebtInstance = new Chart(document.getElementById('chartDebt'), {
        type: 'bar',
        data: {
          labels: debtLabels,
          datasets: [{ label: 'Công nợ', data: debtAmts, backgroundColor: '#EF4444' }]
        },
        options: {
          indexAxis: 'y',
          responsive: true,
          plugins: { legend: { display: false } },
          scales: {
            x: { ticks: { callback: v => v >= 1e6 ? (v/1e6).toFixed(0)+'tr' : v } }
          }
        }
      });

      // Top debtors table
      const tbody = document.getElementById('topDebtorsBody');
      if (!d.top_debtors.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Không có công nợ</td></tr>';
      } else {
        tbody.innerHTML = d.top_debtors.map((r, i) => {
          const overdue = parseInt(r.overdue_days) || 0;
          const badge = overdue > 0
            ? `<span class="badge bg-danger">${overdue} ngày</span>`
            : `<span class="badge bg-success">Chưa quá hạn</span>`;
          return `
            <tr>
              <td>${i + 1}</td>
              <td>${r.customer_name}</td>
              <td class="text-end fw-bold">${fmt(r.total_debt)}</td>
              <td>${r.oldest_due_date || '—'}</td>
              <td>${badge}</td>
            </tr>
          `;
        }).join('');
      }
    })
    .catch(() => {});
}

document.addEventListener('DOMContentLoaded', loadAll);
</script>
<?php include $_SERVER['DOCUMENT_ROOT'] . '/erp/includes/footer.php'; ?>
