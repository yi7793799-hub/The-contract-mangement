<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
$pdo = db();
$admin = current_admin() ?? [];
$ownOnly = mf_own_contract_only_enabled($pdo) && (($admin['role'] ?? 'normal') !== 'super');
$currentAdminId = (int) ($admin['id'] ?? 0);
try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS contract_tx_undo_once (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            contract_id INT UNSIGNED NOT NULL,
            tx_type ENUM('receipt','payment') NOT NULL,
            undone_tx_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_contract_type (contract_id, tx_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (Throwable $e) {
    // ignore schema creation failure to keep page usable
}

$tab = (string) ($_GET['tab'] ?? 'receipt');
if (!in_array($tab, ['receipt', 'payment'], true)) {
    $tab = 'receipt';
}
$biz = (string) ($_GET['biz'] ?? $tab);
if (!in_array($biz, ['receipt', 'payment'], true)) {
    $biz = $tab;
}
$tab = $biz;
require_permission($tab === 'payment' ? 'payment.progress.view' : 'receipt.progress.view');
$kw = trim((string) ($_GET['kw'] ?? ''));
$contractId = (int) ($_GET['contract_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        redirect('finance_progress.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('会话已过期'));
    }
    $tab = (string) ($_POST['tab'] ?? 'receipt');
    if (!in_array($tab, ['receipt', 'payment'], true)) {
        $tab = 'receipt';
    }
    $biz = (string) ($_POST['biz'] ?? $tab);
    if (!in_array($biz, ['receipt', 'payment'], true)) {
        $biz = $tab;
    }
    $tab = $biz;
    require_permission($tab === 'payment' ? 'payment.progress.view' : 'receipt.progress.view');
    $action = (string) ($_POST['action'] ?? 'entry');
    $contractId = (int) ($_POST['contract_id'] ?? 0);
    if ($action === 'undo_last') {
        require_permission($tab === 'payment' ? 'payment.progress.undo' : 'receipt.progress.undo');
        if ($contractId <= 0) {
            redirect('finance_progress.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('合同参数无效'));
        }
        $st = $pdo->prepare('SELECT id, status, created_by FROM contracts WHERE id=? AND payment_type=?');
        $st->execute([$contractId, $tab]);
        $contractRow = $st->fetch(PDO::FETCH_ASSOC);
        if (!$contractRow) {
            redirect('finance_progress.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('合同与款项类型不匹配'));
        }
        if ((string) ($contractRow['status'] ?? '') === 'terminated') {
            redirect('finance_progress.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('合同已终止，不能继续收付款操作'));
        }
        if ($ownOnly && (int) ($contractRow['created_by'] ?? 0) !== $currentAdminId) {
            redirect('finance_progress.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('仅可操作自己登记的合同'));
        }
        $usedSt = $pdo->prepare('SELECT id FROM contract_tx_undo_once WHERE contract_id=? AND tx_type=? LIMIT 1');
        $usedSt->execute([$contractId, $tab]);
        if ($usedSt->fetch()) {
            redirect('finance_progress.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('该合同只能撤销一次，已使用'));
        }
        $lastSt = $pdo->prepare('SELECT id, voucher_path FROM contract_transactions WHERE contract_id=? AND tx_type=? ORDER BY id DESC LIMIT 1');
        $lastSt->execute([$contractId, $tab]);
        $lastTx = $lastSt->fetch(PDO::FETCH_ASSOC);
        if (!$lastTx) {
            redirect('finance_progress.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('暂无可撤销记录'));
        }
        $pdo->prepare('DELETE FROM contract_transactions WHERE id=?')->execute([(int) $lastTx['id']]);
        $pdo->prepare('INSERT INTO contract_tx_undo_once (contract_id,tx_type,undone_tx_id) VALUES (?,?,?)')
            ->execute([$contractId, $tab, (int) $lastTx['id']]);
        $voucher = (string) ($lastTx['voucher_path'] ?? '');
        if ($voucher !== '') {
            $full = __DIR__ . '/' . ltrim($voucher, '/');
            if (is_file($full)) {
                @unlink($full);
            }
        }
        redirect('finance_progress.php?tab=' . $tab . '&biz=' . $biz . '&contract_id=' . $contractId . '&saved=1');
    }

    require_permission($tab === 'payment' ? 'payment.progress.entry' : 'receipt.progress.entry');
    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    $note = trim((string) ($_POST['note'] ?? ''));
    if ($contractId <= 0 || $amount <= 0) {
        redirect('finance_progress.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('请填写合同与有效金额'));
    }
    $st = $pdo->prepare('SELECT id, status, amount, created_by FROM contracts WHERE id=? AND payment_type=?');
    $st->execute([$contractId, $tab]);
    $contractRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$contractRow) {
        redirect('finance_progress.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('合同与款项类型不匹配'));
    }
    if ((string) ($contractRow['status'] ?? '') === 'terminated') {
        redirect('finance_progress.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('合同已终止，不能继续收付款操作'));
    }
    if ($ownOnly && (int) ($contractRow['created_by'] ?? 0) !== $currentAdminId) {
        redirect('finance_progress.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('仅可操作自己登记的合同'));
    }
    $totalAmount = (float) ($contractRow['amount'] ?? 0);
    $doneSt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM contract_transactions WHERE contract_id=? AND tx_type=?');
    $doneSt->execute([$contractId, $tab]);
    $doneAmount = (float) $doneSt->fetchColumn();
    if ($doneAmount + $amount > $totalAmount) {
        redirect('finance_progress.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('已登记金额不能大于合同金额'));
    }
    $voucherPath = null;
    if (isset($_FILES['voucher']) && is_array($_FILES['voucher']) && (int) ($_FILES['voucher']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $dir = __DIR__ . '/uploads/vouchers';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tmp = (string) $_FILES['voucher']['tmp_name'];
        $origin = (string) $_FILES['voucher']['name'];
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $allow = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        if (in_array($mime, $allow, true)) {
            $ext = pathinfo($origin, PATHINFO_EXTENSION);
            $fn = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . ($ext !== '' ? '.' . $ext : '');
            $dest = $dir . '/' . $fn;
            if (move_uploaded_file($tmp, $dest)) {
                $voucherPath = 'uploads/vouchers/' . $fn;
            }
        }
    }
    // 新增一条后，重新允许撤销“最新一条”
    $pdo->prepare('DELETE FROM contract_tx_undo_once WHERE contract_id=? AND tx_type=?')->execute([$contractId, $tab]);
    $pdo->prepare('INSERT INTO contract_transactions (contract_id,tx_type,amount,note,voucher_path,created_by) VALUES (?,?,?,?,?,?)')
        ->execute([$contractId, $tab, $amount, $note, $voucherPath, (int) (current_admin()['id'] ?? 0)]);
    redirect('finance_progress.php?tab=' . $tab . '&biz=' . $biz . '&contract_id=' . $contractId . '&saved=1');
}

$titleMap = ['receipt' => '收款', 'payment' => '付款'];
$where = ['c.payment_type = ?', 'c.is_archived = 0'];
$params = [$tab, $tab, $tab];
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
$sql = "SELECT c.*, COALESCE(a.display_name, a.username, '-') AS creator_name,
            COALESCE((SELECT SUM(t.amount) FROM contract_transactions t WHERE t.contract_id = c.id AND t.tx_type = ?),0) AS done_amount,
            EXISTS(SELECT 1 FROM contract_tx_undo_once u WHERE u.contract_id = c.id AND u.tx_type = ?) AS undo_used
     FROM contracts c
     LEFT JOIN admins a ON a.id = c.created_by
     WHERE " . implode(' AND ', $where) . '
     ORDER BY c.id DESC';
$st = $pdo->prepare($sql);
$st->execute($params);
$contracts = $st->fetchAll() ?: [];

if ((string) ($_GET['export'] ?? '') === '1') {
    require_permission($tab === 'payment' ? 'payment.progress.export' : 'receipt.progress.export');
    $filename = ($tab === 'payment' ? '付款业务进度' : '收款业务进度') . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; filename*=UTF-8\'\'' . rawurlencode($filename));
    echo "\xEF\xBB\xBF";
    echo "\"合同编号\",\"合同名称\",\"创建人\",\"合同总额\",\"已登记金额\",\"剩余金额\",\"进度(%)\",\"状态\"\r\n";
    foreach ($contracts as $c) {
        $total = (float) $c['amount'];
        $done = (float) $c['done_amount'];
        $remaining = max(0, $total - $done);
        $percent = $total > 0 ? min(100, ($done / $total) * 100) : 0;
        $line = [
            (string) $c['contract_no'],
            (string) $c['contract_name'],
            (string) ($c['creator_name'] ?? '-'),
            number_format($total, 2, '.', ''),
            number_format($done, 2, '.', ''),
            number_format($remaining, 2, '.', ''),
            number_format($percent, 2, '.', ''),
            mf_contract_status_label((string) $c['status']),
        ];
        $escaped = array_map(static function ($v): string {
            $s = str_replace('"', '""', (string) $v);
            return '"' . $s . '"';
        }, $line);
        echo implode(',', $escaped) . "\r\n";
    }
    exit;
}

$pageTitle = '付款/收款';
$activeNav = 'finance';
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
        <a class="mf-btn mf-btn--default" href="<?= e(url('finance_progress.php?tab=' . $tab . '&biz=' . $biz)) ?>">重置</a>
        <a class="mf-btn mf-btn--default" href="<?= e(url('finance_progress.php?tab=' . $tab . '&biz=' . $biz . '&kw=' . rawurlencode($kw) . '&export=1')) ?>">导出</a>
      </div>
    </form>
  </div>
</div>

<div class="mf-panel">
  <div class="mf-panel__header"><?= e($titleMap[$tab]) ?>合同进度</div>
  <div class="mf-table-wrap">
    <table class="mf-table mf-table--striped table-mf mf-mb-0">
      <thead><tr><th>合同编号</th><th>项目号</th><th>合同名称</th><th>创建人</th><th>合同总额</th><th>已登记<?= e($titleMap[$tab]) ?></th><th>剩余金额</th><th>进度</th><th>状态</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($contracts as $c): ?>
        <?php
          $total = (float) $c['amount'];
          $done = (float) $c['done_amount'];
          $remaining = max(0, $total - $done);
          $percent = $total > 0 ? min(100, ($done / $total) * 100) : 0;
        ?>
        <tr>
          <td><?= e((string) $c['contract_no']) ?></td>
          <td><?= e((string) ($c['project_no'] ?? '-')) ?></td>
          <td><a href="<?= e(url('contract_view.php?id=' . (int) $c['id'])) ?>"><?= e((string) $c['contract_name']) ?></a><?= mf_subcontract_tag((string) $c['contract_name'], (int)($c['is_subcontract'] ?? 0)) ?></td>
          <td><?= e((string) ($c['creator_name'] ?? '-')) ?></td>
          <td>¥<?= number_format($total, 2) ?></td>
          <td>¥<?= number_format($done, 2) ?></td>
          <td>¥<?= number_format($remaining, 2) ?></td>
          <td style="min-width:220px;">
            <div style="height:10px;background:#ebeef5;overflow:hidden;">
              <div style="height:10px;width:<?= number_format($percent, 2, '.', '') ?>%;background:<?= $tab === 'receipt' ? '#67c23a' : '#e6a23c' ?>;"></div>
            </div>
            <div class="mf-small mf-text-muted"><?= number_format($percent, 2) ?>%</div>
          </td>
          <td><?= mf_contract_status_badge((string) $c['status']) ?></td>
          <td>
            <?php if ((string) ($c['status'] ?? '') === 'terminated'): ?>
              <span class="mf-small mf-text-muted">已终止</span>
            <?php else: ?>
              <button type="button" class="mf-btn mf-btn--primary mf-btn--sm js-open-entry" data-contract-id="<?= (int) $c['id'] ?>" data-contract-label="<?= e((string) $c['contract_no'] . ' - ' . $c['contract_name']) ?>"><?= e($titleMap[$tab]) ?></button>
              <?php if ((float) $done > 0 && (int) $c['undo_used'] === 0): ?>
                <form method="post" style="display:inline;">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="undo_last">
                  <input type="hidden" name="tab" value="<?= e($tab) ?>">
                <input type="hidden" name="biz" value="<?= e($biz) ?>">
                  <input type="hidden" name="contract_id" value="<?= (int) $c['id'] ?>">
                  <button type="submit" class="mf-btn mf-btn--danger mf-btn--sm">撤销上一次</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$contracts): ?>
        <tr><td colspan="9" class="mf-text-center mf-text-muted mf-p-4">暂无合同</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="mf-modal" id="financeEntryModal" aria-hidden="true">
  <div class="mf-modal__mask" data-mf-modal-close></div>
  <div class="mf-modal__wrap">
    <div class="mf-modal__box mf-modal__box--lg">
      <div class="mf-modal__header">
        <h2 class="mf-modal__title">登记<?= e($titleMap[$tab]) ?></h2>
        <button type="button" class="mf-modal__close" data-mf-modal-close aria-label="关闭">&times;</button>
      </div>
      <div class="mf-modal__body">
        <form method="post" enctype="multipart/form-data" id="financeEntryForm">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="tab" value="<?= e($tab) ?>">
          <input type="hidden" name="biz" value="<?= e($biz) ?>">
          <input type="hidden" name="contract_id" id="financeContractId" value="0">
          <div class="mf-row mf-row--tight">
            <div class="mf-col mf-col-12">
              <label class="mf-label">合同</label>
              <input class="mf-input" id="financeContractLabel" type="text" value="" placeholder="请从列表点击登记按钮" disabled>
            </div>
            <div class="mf-col mf-col-12 mf-col-md-4">
              <label class="mf-label">金额</label>
              <input class="mf-input" type="number" min="0.01" step="0.01" name="amount" required>
            </div>
            <div class="mf-col mf-col-12 mf-col-md-8">
              <label class="mf-label">备注</label>
              <input class="mf-input" type="text" name="note" maxlength="255" placeholder="可选">
            </div>
            <div class="mf-col mf-col-12">
              <label class="mf-label">凭证</label>
              <input class="mf-input" type="file" name="voucher" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp">
            </div>
          </div>
        </form>
      </div>
      <div class="mf-modal__footer mf-flex mf-gap-2 mf-justify-end">
        <button type="button" class="mf-btn mf-btn--default" data-mf-modal-close>取消</button>
        <button type="submit" form="financeEntryForm" class="mf-btn mf-btn--primary">提交登记</button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  document.querySelectorAll('.js-open-entry').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var cid = btn.getAttribute('data-contract-id') || '0';
      var clabel = btn.getAttribute('data-contract-label') || '';
      var hidden = document.getElementById('financeContractId');
      var label = document.getElementById('financeContractLabel');
      if (hidden) hidden.value = cid;
      if (label) label.value = clabel;
      if (window.MFModal) window.MFModal.show('financeEntryModal');
    });
  });
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
