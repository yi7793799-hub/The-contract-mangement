<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
$pdo = db();
$admin = current_admin() ?? [];
$ownOnly = mf_own_contract_only_enabled($pdo) && (($admin['role'] ?? 'normal') !== 'super');
$currentAdminId = (int) ($admin['id'] ?? 0);

$tab = (string) ($_GET['tab'] ?? 'receipt');
if (!in_array($tab, ['receipt', 'payment'], true)) {
    $tab = 'receipt';
}
$biz = (string) ($_GET['biz'] ?? $tab);
if (!in_array($biz, ['receipt', 'payment'], true)) {
    $biz = $tab;
}
$tab = $biz;
require_permission($tab === 'payment' ? 'payment.records.view' : 'receipt.records.view');
$kw = trim((string) ($_GET['kw'] ?? ''));

$titleMap = ['receipt' => '收款', 'payment' => '付款'];
$where = ['t.tx_type = ?', 'c.is_archived = 0'];
$params = [$tab];
if ($ownOnly) {
    $where[] = 'c.created_by = ?';
    $params[] = $currentAdminId;
}
if ($kw !== '') {
    $where[] = '(c.contract_no LIKE ? OR c.project_no LIKE ? OR c.contract_name LIKE ?)';
    $like = '%' . $kw . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$sql = "SELECT t.*, c.contract_no, c.project_no, c.contract_name, c.amount AS contract_amount,
            COALESCE(a.display_name, a.username, '-') AS registrar_name,
            c.amount - COALESCE((
                SELECT SUM(t2.amount)
                FROM contract_transactions t2
                WHERE t2.contract_id = t.contract_id
                  AND t2.tx_type = t.tx_type
                  AND t2.id <= t.id
            ), 0) AS remaining_after_tx
     FROM contract_transactions t
     INNER JOIN contracts c ON c.id = t.contract_id
     LEFT JOIN admins a ON a.id = t.created_by
      WHERE " . implode(' AND ', $where) . "
     ORDER BY t.id DESC
      LIMIT 200";
$recentSt = $pdo->prepare($sql);
$recentSt->execute($params);
$rows = $recentSt->fetchAll() ?: [];

if ((string) ($_GET['export'] ?? '') === '1') {
    require_permission($tab === 'payment' ? 'payment.records.export' : 'receipt.records.export');
    $filename = ($tab === 'payment' ? '付款记录' : '收款记录') . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    echo "\xEF\xBB\xBF";
    echo "\"时间\",\"合同编号\",\"合同名称\",\"登记人\",\"合同金额\",\"本次金额\",\"登记后剩余金额\",\"备注\"\r\n";
    foreach ($rows as $r) {
        $line = [
            (string) $r['created_at'],
            (string) $r['contract_no'],
            (string) $r['contract_name'],
            (string) ($r['registrar_name'] ?? '-'),
            number_format((float) $r['contract_amount'], 2, '.', ''),
            number_format((float) $r['amount'], 2, '.', ''),
            number_format(max(0, (float) $r['remaining_after_tx']), 2, '.', ''),
            (string) $r['note'],
        ];
        $escaped = array_map(static function ($v): string {
            $s = str_replace('"', '""', (string) $v);
            return '"' . $s . '"';
        }, $line);
        echo implode(',', $escaped) . "\r\n";
    }
    exit;
}

$pageTitle = '登记记录';
$activeNav = 'finance_records';
ob_start();
?>
<div class="mf-panel">
  <div class="mf-panel__body">
    <form method="get" class="mf-row mf-row--tight mf-items-end mf-toolbar-row">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      <input type="hidden" name="biz" value="<?= e($biz) ?>">
      <div class="mf-col mf-col-12 mf-col-md-6"><label class="mf-label mf-small mf-text-muted mf-mb-0">关键词</label><input class="mf-input" name="kw" value="<?= e($kw) ?>" placeholder="合同编号 / 项目号 / 合同名称"></div>
      <div class="mf-col mf-col-12 mf-col-md-6 mf-toolbar-actions">
        <button class="mf-btn mf-btn--primary">查询</button>
        <a class="mf-btn mf-btn--default" href="<?= e(url('finance_records.php?tab=' . $tab . '&biz=' . $biz)) ?>">重置</a>
        <a class="mf-btn mf-btn--default" href="<?= e(url('finance_records.php?tab=' . $tab . '&biz=' . $biz . '&kw=' . rawurlencode($kw) . '&export=1')) ?>">导出</a>
      </div>
    </form>
  </div>
</div>

<div class="mf-panel">
  <div class="mf-panel__header"><?= e($titleMap[$tab]) ?>登记记录</div>
  <div class="mf-table-wrap">
    <table class="mf-table mf-table--striped table-mf mf-mb-0">
      <thead><tr><th>时间</th><th>合同编号</th><th>项目号</th><th>合同名称</th><th>登记人</th><th>合同金额</th><th>本次金额</th><th>登记后剩余金额</th><th>备注</th><th>凭证</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e((string) $r['created_at']) ?></td>
          <td><?= e((string) $r['contract_no']) ?></td>
          <td><?= e((string) ($r['project_no'] ?? '-')) ?></td>
          <td><a href="<?= e(url('contract_view.php?id=' . (int) $r['contract_id'])) ?>"><?= e((string) $r['contract_name']) ?></a></td>
          <td><?= e((string) ($r['registrar_name'] ?? '-')) ?></td>
          <td>¥<?= number_format((float) $r['contract_amount'], 2) ?></td>
          <td>¥<?= number_format((float) $r['amount'], 2) ?></td>
          <td>¥<?= number_format(max(0, (float) $r['remaining_after_tx']), 2) ?></td>
          <td><?= e((string) $r['note']) ?></td>
          <td>
            <?php if (!empty($r['voucher_path'])): ?>
              <a class="mf-btn mf-btn--default mf-btn--sm" target="_blank" href="<?= e(url((string) $r['voucher_path'])) ?>">查看</a>
            <?php else: ?>
              <span class="mf-small mf-text-muted">无</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="10" class="mf-text-center mf-text-muted mf-p-4">暂无记录</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
