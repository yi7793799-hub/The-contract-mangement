<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('dashboard.view');

$pdo = db();
$admin = current_admin() ?? [];
$ownOnly = mf_own_contract_only_enabled($pdo) && (($admin['role'] ?? 'normal') !== 'super');
$currentAdminId = (int) ($admin['id'] ?? 0);
$ownWhere = $ownOnly ? ' WHERE created_by = ?' : '';
$ownParams = $ownOnly ? [$currentAdminId] : [];

// 读取到期提醒天数配置（与 report.php 同步）
$remindDays = 15;
try {
    $st = $pdo->query('SELECT remind_days FROM contract_settings WHERE id = 1 LIMIT 1');
    $remindDays = max(1, (int) (($st ? $st->fetchColumn() : 15) ?: 15));
} catch (Throwable $e) {
    $remindDays = 15;
}

$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$yearStart = date('Y-01-01');

$st = $pdo->prepare('SELECT COUNT(*) FROM contracts' . $ownWhere);
$st->execute($ownParams);
$totalContracts = (int) $st->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE payment_type = 'receipt'" . ($ownOnly ? ' AND created_by = ?' : ''));
$st->execute($ownParams);
$receiptContracts = (int) $st->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE payment_type = 'payment'" . ($ownOnly ? ' AND created_by = ?' : ''));
$st->execute($ownParams);
$paymentContracts = (int) $st->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE is_archived = 0 AND status = 'ongoing'" . ($ownOnly ? ' AND created_by = ?' : ''));
$st->execute($ownParams);
$ongoingContracts = (int) $st->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE is_archived = 0 AND (status = 'expiring' OR (expiry_date IS NOT NULL AND DATEDIFF(expiry_date, CURDATE()) BETWEEN 0 AND ?))" . ($ownOnly ? ' AND created_by = ?' : ''));
$st->execute($ownOnly ? [$remindDays, $currentAdminId] : [$remindDays]);
$expiringContracts = (int) $st->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE is_archived = 0 AND status = 'terminated'" . ($ownOnly ? ' AND created_by = ?' : ''));
$st->execute($ownParams);
$terminatedContracts = (int) $st->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE is_archived = 0 AND status = 'completed'" . ($ownOnly ? ' AND created_by = ?' : ''));
$st->execute($ownParams);
$completedContracts = (int) $st->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE is_archived = 1" . ($ownOnly ? ' AND created_by = ?' : ''));
$st->execute($ownParams);
$archivedContracts = (int) $st->fetchColumn();

$pendingSt = $pdo->prepare(
    "SELECT c.id, c.payment_type, c.amount, c.is_archived,
            COALESCE(SUM(CASE WHEN t.tx_type = 'receipt' THEN t.amount ELSE 0 END), 0) AS receipt_done,
            COALESCE(SUM(CASE WHEN t.tx_type = 'payment' THEN t.amount ELSE 0 END), 0) AS payment_done
     FROM contracts c
     LEFT JOIN contract_transactions t ON t.contract_id = c.id
     " . ($ownOnly ? "WHERE c.created_by = ? " : "") . "
     GROUP BY c.id, c.payment_type, c.amount, c.is_archived"
);
$pendingSt->execute($ownParams);
$pendingReceiptAmount = 0.0;
$pendingPaymentAmount = 0.0;
$doneReceiptAmount = 0.0;
$donePaymentAmount = 0.0;
while ($pr = $pendingSt->fetch(PDO::FETCH_ASSOC)) {
    $contractAmount = (float) $pr['amount'];
    $paymentType = (string) $pr['payment_type'];
    $isArchived = (int) $pr['is_archived'] === 1;
    if ($paymentType === 'payment') {
        $donePaymentAmount += (float) $pr['payment_done'];
        $diff = max(0.0, $contractAmount - (float) $pr['payment_done']);
        if ($diff > 0 && !$isArchived) {
            $pendingPaymentAmount += $diff;
        }
    } else {
        $doneReceiptAmount += (float) $pr['receipt_done'];
        $diff = max(0.0, $contractAmount - (float) $pr['receipt_done']);
        if ($diff > 0 && !$isArchived) {
            $pendingReceiptAmount += $diff;
        }
    }
}

$chartStart = date('Y-m-d', strtotime('-6 days'));
$st = $pdo->prepare(
    "SELECT DATE(created_at) AS d, COUNT(*) AS c, COALESCE(SUM(amount),0) AS a
     FROM contracts
     WHERE DATE(created_at) >= ? AND DATE(created_at) <= ?" . ($ownOnly ? " AND created_by = ?" : "") . "
     GROUP BY DATE(created_at)"
);
$st->execute($ownOnly ? [$chartStart, $today, $currentAdminId] : [$chartStart, $today]);
$byDay = [];
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $byDay[(string) $r['d']] = ['count' => (int) $r['c'], 'amount' => (float) $r['a']];
}
$labels = [];
$counts = [];
$amounts = [];
$ts = strtotime($chartStart);
while ($ts <= strtotime($today)) {
    $d = date('Y-m-d', $ts);
    $labels[] = date('m-d', $ts);
    $counts[] = (int) (($byDay[$d]['count'] ?? 0));
    $amounts[] = round((float) ($byDay[$d]['amount'] ?? 0), 2);
    $ts = strtotime('+1 day', $ts);
}
$chartPayload = ['labels' => $labels, 'counts' => $counts, 'amounts' => $amounts];

$pageTitle = '首页';
$activeNav = 'dashboard';
ob_start();
?>
<?php
// 分层展示：核心指标（4个）+ 详细统计（8个，可折叠）
// 构建跳转URL
$totalContractsUrl = url('orders.php');
$pendingReceiptUrl = url('finance_progress.php?tab=receipt&biz=receipt&menu=entry');
$pendingPaymentUrl = url('finance_progress.php?tab=payment&biz=payment&menu=entry');
$expiringUrl = url('report.php');
$receiptContractsUrl = url('orders.php?biz=receipt');
$paymentContractsUrl = url('orders.php?biz=payment');
$ongoingUrl = url('orders.php?status=ongoing');
$completedUrl = url('orders.php?status=completed');
$archivedUrl = url('archived_contracts.php');
$terminatedUrl = url('orders.php?status=terminated');
?>
<div class="mf-stat-dashboard mf-stat-dashboard--core">
    <div class="mf-stat-tile mf-stat-tile--sky mf-stat-tile--core mf-stat-tile--clickable" onclick="location.href='<?= e($totalContractsUrl) ?>'" title="点击查看全部合同">
        <div class="mf-stat-tile__label">合同总数</div>
        <div class="mf-stat-tile__value"><?= $totalContracts ?></div>
        <div class="mf-stat-tile__compare">全部合同</div>
        <i class="mf-stat-tile__icon bi bi-files" aria-hidden="true"></i>
    </div>
    <div class="mf-stat-tile mf-stat-tile--green mf-stat-tile--core mf-stat-tile--clickable" onclick="location.href='<?= e($pendingReceiptUrl) ?>'" title="点击登记收款">
        <div class="mf-stat-tile__label">待收款金额</div>
        <div class="mf-stat-tile__value">¥<?= number_format($pendingReceiptAmount, 2) ?></div>
        <div class="mf-stat-tile__compare">未归档待收余额</div>
        <i class="mf-stat-tile__icon bi bi-cash-coin" aria-hidden="true"></i>
    </div>
    <div class="mf-stat-tile mf-stat-tile--orange mf-stat-tile--core mf-stat-tile--clickable" onclick="location.href='<?= e($pendingPaymentUrl) ?>'" title="点击登记付款">
        <div class="mf-stat-tile__label">待付款金额</div>
        <div class="mf-stat-tile__value">¥<?= number_format($pendingPaymentAmount, 2) ?></div>
        <div class="mf-stat-tile__compare">未归档待付余额</div>
        <i class="mf-stat-tile__icon bi bi-wallet2" aria-hidden="true"></i>
    </div>
    <div class="mf-stat-tile mf-stat-tile--violet mf-stat-tile--core mf-stat-tile--clickable" onclick="location.href='<?= e($expiringUrl) ?>'" title="点击查看到期提醒">
        <div class="mf-stat-tile__label">即将到期</div>
        <div class="mf-stat-tile__value"><?= $expiringContracts ?></div>
        <div class="mf-stat-tile__compare"><?= $remindDays ?>天内到期</div>
        <i class="mf-stat-tile__icon bi bi-bell" aria-hidden="true"></i>
    </div>
</div>

<div class="mf-stat-collapse" id="mfStatCollapse">
    <div class="mf-stat-collapse__header" data-collapse-toggle="#mfStatCollapse">
        <span class="mf-stat-collapse__title">
            <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>
            详细统计
        </span>
        <span class="mf-stat-collapse__toggle">
            <span class="mf-stat-collapse__toggle-text">展开</span>
            <i class="bi bi-chevron-down mf-stat-collapse__toggle-icon" aria-hidden="true"></i>
        </span>
    </div>
    <div class="mf-stat-collapse__body">
        <div class="mf-stat-dashboard mf-stat-dashboard--detail">
            <div class="mf-stat-tile mf-stat-tile--sky mf-stat-tile--detail mf-stat-tile--clickable" onclick="location.href='<?= e($receiptContractsUrl) ?>'" title="点击查看收款合同">
                <div class="mf-stat-tile__label">收款合同</div>
                <div class="mf-stat-tile__value"><?= $receiptContracts ?></div>
                <div class="mf-stat-tile__compare">收款类型</div>
                <i class="mf-stat-tile__icon bi bi-wallet2" aria-hidden="true"></i>
            </div>
            <div class="mf-stat-tile mf-stat-tile--orange mf-stat-tile--detail mf-stat-tile--clickable" onclick="location.href='<?= e($paymentContractsUrl) ?>'" title="点击查看付款合同">
                <div class="mf-stat-tile__label">付款合同</div>
                <div class="mf-stat-tile__value"><?= $paymentContracts ?></div>
                <div class="mf-stat-tile__compare">付款类型</div>
                <i class="mf-stat-tile__icon bi bi-credit-card-2-front" aria-hidden="true"></i>
            </div>
            <div class="mf-stat-tile mf-stat-tile--indigo mf-stat-tile--detail mf-stat-tile--clickable" onclick="location.href='<?= e($ongoingUrl) ?>'" title="点击查看进行中合同">
                <div class="mf-stat-tile__label">进行中</div>
                <div class="mf-stat-tile__value"><?= $ongoingContracts ?></div>
                <div class="mf-stat-tile__compare">未归档</div>
                <i class="mf-stat-tile__icon bi bi-hourglass-split" aria-hidden="true"></i>
            </div>
            <div class="mf-stat-tile mf-stat-tile--sky mf-stat-tile--detail mf-stat-tile--clickable" onclick="location.href='<?= e($completedUrl) ?>'" title="点击查看已完成合同">
                <div class="mf-stat-tile__label">已完成</div>
                <div class="mf-stat-tile__value"><?= $completedContracts ?></div>
                <div class="mf-stat-tile__compare">未归档</div>
                <i class="mf-stat-tile__icon bi bi-check2-circle" aria-hidden="true"></i>
            </div>
            <div class="mf-stat-tile mf-stat-tile--green mf-stat-tile--detail mf-stat-tile--clickable" onclick="location.href='<?= e($archivedUrl) ?>'" title="点击查看归档合同">
                <div class="mf-stat-tile__label">已归档</div>
                <div class="mf-stat-tile__value"><?= $archivedContracts ?></div>
                <div class="mf-stat-tile__compare">归档总数</div>
                <i class="mf-stat-tile__icon bi bi-archive" aria-hidden="true"></i>
            </div>
            <div class="mf-stat-tile mf-stat-tile--orange mf-stat-tile--detail mf-stat-tile--clickable" onclick="location.href='<?= e($terminatedUrl) ?>'" title="点击查看已终止合同">
                <div class="mf-stat-tile__label">已终止</div>
                <div class="mf-stat-tile__value"><?= $terminatedContracts ?></div>
                <div class="mf-stat-tile__compare">未归档</div>
                <i class="mf-stat-tile__icon bi bi-slash-circle" aria-hidden="true"></i>
            </div>
            <div class="mf-stat-tile mf-stat-tile--green mf-stat-tile--detail">
                <div class="mf-stat-tile__label">已收款</div>
                <div class="mf-stat-tile__value">¥<?= number_format($doneReceiptAmount, 2) ?></div>
                <div class="mf-stat-tile__compare">累计登记</div>
                <i class="mf-stat-tile__icon bi bi-piggy-bank" aria-hidden="true"></i>
            </div>
            <div class="mf-stat-tile mf-stat-tile--sky mf-stat-tile--detail">
                <div class="mf-stat-tile__label">已付款</div>
                <div class="mf-stat-tile__value">¥<?= number_format($donePaymentAmount, 2) ?></div>
                <div class="mf-stat-tile__compare">累计登记</div>
                <i class="mf-stat-tile__icon bi bi-cash-stack" aria-hidden="true"></i>
            </div>
        </div>
    </div>
</div>

<div class="mf-panel mf-dashboard-trend mf-mt-3">
    <div class="mf-panel__header">近7日合同趋势</div>
    <div class="mf-panel__body mf-p-0">
        <div id="mfDashboardTrend" class="mf-dashboard-trend__chart" role="img" aria-label="近7日合同趋势"></div>
    </div>
</div>

<script src="<?= e(asset_url('vendor/echarts/echarts.min.js')) ?>"></script>
<script>
(function () {
  // 折叠交互
  var collapse = document.getElementById('mfStatCollapse');
  var header = collapse ? collapse.querySelector('.mf-stat-collapse__header') : null;
  if (header && collapse) {
    header.addEventListener('click', function () {
      var isOpen = collapse.classList.contains('is-open');
      collapse.classList.toggle('is-open');
      var textEl = header.querySelector('.mf-stat-collapse__toggle-text');
      if (textEl) textEl.textContent = isOpen ? '展开' : '收起';
    });
  }

  // 图表初始化
  var payload = <?= json_encode($chartPayload, JSON_UNESCAPED_UNICODE) ?>;
  function boot() {
    var el = document.getElementById('mfDashboardTrend');
    if (!el || typeof echarts === 'undefined') return;
    var chart = echarts.init(el);
    chart.setOption({
      color: ['#2563eb', '#22c55e'],
      tooltip: { trigger: 'axis' },
      legend: { data: ['新增合同数', '合同金额'], left: 12, top: 12 },
      grid: { left: 48, right: 48, top: 74, bottom: 30 },
      xAxis: { type: 'category', data: payload.labels },
      yAxis: [
        { type: 'value', name: '数量', minInterval: 1 },
        { type: 'value', name: '金额', position: 'right' }
      ],
      series: [
        { name: '新增合同数', type: 'line', smooth: true, data: payload.counts, yAxisIndex: 0 },
        { name: '合同金额', type: 'line', smooth: true, data: payload.amounts, yAxisIndex: 1 }
      ]
    });
    window.addEventListener('resize', function () { chart.resize(); });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
