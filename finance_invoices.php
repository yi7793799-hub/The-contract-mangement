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
require_permission($tab === 'payment' ? 'payment.invoices.view' : 'receipt.invoices.view');
$kw = trim((string) ($_GET['kw'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        redirect('finance_invoices.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('会话已过期'));
    }
    require_permission($tab === 'payment' ? 'payment.invoices.entry' : 'receipt.invoices.entry');
    $contractId = (int) ($_POST['contract_id'] ?? 0);
    $amount = round((float) ($_POST['amount'] ?? 0), 2);
    $note = trim((string) ($_POST['note'] ?? ''));
    if ($contractId <= 0 || $amount <= 0) {
        redirect('finance_invoices.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('请选择合同并输入有效金额'));
    }
    $st = $pdo->prepare('SELECT id, payment_type, created_by FROM contracts WHERE id=? AND is_archived = 0');
    $st->execute([$contractId]);
    $contract = $st->fetch(PDO::FETCH_ASSOC);
    if (!$contract || (string) ($contract['payment_type'] ?? '') !== $tab) {
        redirect('finance_invoices.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('合同与业务类型不匹配'));
    }
    if ($ownOnly && (int) ($contract['created_by'] ?? 0) !== $currentAdminId) {
        redirect('finance_invoices.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('仅可操作自己登记的合同'));
    }
    $doneSt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM contract_transactions WHERE contract_id=? AND tx_type=?');
    $doneSt->execute([$contractId, $tab]);
    $doneAmount = (float) $doneSt->fetchColumn();
    $invoicedSt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM contract_invoices WHERE contract_id=? AND invoice_type=?');
    $invoicedSt->execute([$contractId, $tab]);
    $invoicedAmount = (float) $invoicedSt->fetchColumn();
    if ($invoicedAmount + $amount > $doneAmount) {
        redirect('finance_invoices.php?tab=' . $tab . '&biz=' . $biz . '&err=' . rawurlencode('开票金额不能大于已登记收付款金额'));
    }

    $filePath = null;
    if (isset($_FILES['invoice_file']) && is_array($_FILES['invoice_file']) && (int) ($_FILES['invoice_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $dir = __DIR__ . '/uploads/invoices';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $tmp = (string) $_FILES['invoice_file']['tmp_name'];
        $origin = (string) $_FILES['invoice_file']['name'];
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $allow = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
        if (in_array($mime, $allow, true)) {
            $ext = pathinfo($origin, PATHINFO_EXTENSION);
            $fn = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . ($ext !== '' ? '.' . $ext : '');
            $dest = $dir . '/' . $fn;
            if (move_uploaded_file($tmp, $dest)) {
                $filePath = 'uploads/invoices/' . $fn;
            }
        }
    }
    $pdo->prepare('INSERT INTO contract_invoices (contract_id, invoice_type, amount, note, file_path, created_by) VALUES (?,?,?,?,?,?)')
        ->execute([$contractId, $tab, $amount, $note, $filePath, $currentAdminId]);
    redirect('finance_invoices.php?tab=' . $tab . '&biz=' . $biz . '&saved=1');
}

$titleMap = ['receipt' => '收款', 'payment' => '付款'];
$where = ['c.payment_type = ?', 'c.is_archived = 0'];
$params = [$tab, $tab, $tab, $tab, $tab];
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
$contractsSql = "SELECT c.id, c.contract_no, c.project_no, c.contract_name,
                    COALESCE((SELECT SUM(t.amount) FROM contract_transactions t WHERE t.contract_id = c.id AND t.tx_type = ?), 0) AS done_amount,
                    COALESCE((SELECT SUM(i.amount) FROM contract_invoices i WHERE i.contract_id = c.id AND i.invoice_type = ?), 0) AS invoiced_amount,
                    COALESCE((SELECT SUM(t.amount) FROM contract_transactions t WHERE t.contract_id = c.id AND t.tx_type = ?), 0)
                    - COALESCE((SELECT SUM(i.amount) FROM contract_invoices i WHERE i.contract_id = c.id AND i.invoice_type = ?), 0) AS pending_invoice_amount
                 FROM contracts c
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY c.id DESC";
$cs = $pdo->prepare($contractsSql);
$cs->execute($params);
$contracts = $cs->fetchAll(PDO::FETCH_ASSOC) ?: [];

$invoiceWhere = ['i.invoice_type = ?'];
$invoiceParams = [$tab];
if ($ownOnly) {
    $invoiceWhere[] = 'c.created_by = ?';
    $invoiceParams[] = $currentAdminId;
}
if ($kw !== '') {
    $invoiceWhere[] = '(c.contract_no LIKE ? OR c.contract_name LIKE ?)';
    $like = '%' . $kw . '%';
    $invoiceParams[] = $like;
    $invoiceParams[] = $like;
}
$invoiceSql = "SELECT i.*, c.contract_no, c.project_no, c.contract_name, COALESCE(a.display_name, a.username, '-') AS registrar_name
               FROM contract_invoices i
               INNER JOIN contracts c ON c.id = i.contract_id
               LEFT JOIN admins a ON a.id = i.created_by
               WHERE " . implode(' AND ', $invoiceWhere) . "
               ORDER BY i.id DESC
               LIMIT 200";
$is = $pdo->prepare($invoiceSql);
$is->execute($invoiceParams);
$invoiceRows = $is->fetchAll(PDO::FETCH_ASSOC) ?: [];

$pageTitle = '开票明细';
$activeNav = 'finance_invoices';
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
        <a class="mf-btn mf-btn--default" href="<?= e(url('finance_invoices.php?tab=' . $tab . '&biz=' . $biz)) ?>">重置</a>
        <button type="button" class="mf-btn mf-btn--primary" id="openInvoiceModal">上传发票</button>
      </div>
    </form>
  </div>
</div>

<div class="mf-panel">
  <div class="mf-panel__body">
    <div class="mf-flex mf-gap-2">
      <button type="button" class="mf-btn mf-btn--primary mf-btn--sm js-invoice-tab" data-pane="invoiceSummaryPane">开票明细</button>
      <button type="button" class="mf-btn mf-btn--default mf-btn--sm js-invoice-tab" data-pane="invoiceRecordPane">开票记录</button>
    </div>
  </div>
</div>

<div id="invoiceSummaryPane" class="js-invoice-pane">
<div class="mf-panel">
  <div class="mf-panel__header"><?= e($titleMap[$tab]) ?>开票明细</div>
  <div class="mf-table-wrap">
    <table class="mf-table mf-table--striped table-mf mf-mb-0">
      <thead><tr><th>合同编号</th><th>项目号</th><th>合同名称</th><th>已登记金额</th><th>已开票金额</th><th>待开票金额</th></tr></thead>
      <tbody>
      <?php foreach ($contracts as $c): ?>
        <tr>
          <td><?= e((string) $c['contract_no']) ?></td>
          <td><?= e((string) ($c['project_no'] ?? '-')) ?></td>
          <td><?= e((string) $c['contract_name']) ?></td>
          <td>¥<?= number_format((float) $c['done_amount'], 2) ?></td>
          <td>¥<?= number_format((float) $c['invoiced_amount'], 2) ?></td>
          <td>¥<?= number_format(max(0, (float) $c['pending_invoice_amount']), 2) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$contracts): ?><tr><td colspan="6" class="mf-text-center mf-text-muted mf-p-4">暂无数据</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<div id="invoiceRecordPane" class="js-invoice-pane" style="display:none;">
<div class="mf-panel">
  <div class="mf-panel__header">开票记录</div>
  <div class="mf-table-wrap">
    <table class="mf-table mf-table--striped table-mf mf-mb-0">
      <thead><tr><th>时间</th><th>合同编号</th><th>项目号</th><th>合同名称</th><th>开票金额</th><th>登记人</th><th>备注</th><th>附件</th></tr></thead>
      <tbody>
      <?php foreach ($invoiceRows as $r): ?>
        <tr>
          <td><?= e((string) $r['created_at']) ?></td>
          <td><?= e((string) $r['contract_no']) ?></td>
          <td><?= e((string) ($r['project_no'] ?? '-')) ?></td>
          <td><?= e((string) $r['contract_name']) ?></td>
          <td>¥<?= number_format((float) $r['amount'], 2) ?></td>
          <td><?= e((string) ($r['registrar_name'] ?? '-')) ?></td>
          <td><?= e((string) ($r['note'] ?? '')) ?></td>
          <td><?php if (!empty($r['file_path'])): ?><a class="mf-btn mf-btn--default mf-btn--sm" target="_blank" href="<?= e(url((string) $r['file_path'])) ?>">查看</a><?php else: ?><span class="mf-small mf-text-muted">无</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$invoiceRows): ?><tr><td colspan="8" class="mf-text-center mf-text-muted mf-p-4">暂无开票记录</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<div class="mf-modal" id="invoiceModal" aria-hidden="true">
  <div class="mf-modal__mask" data-mf-modal-close></div>
  <div class="mf-modal__wrap">
    <div class="mf-modal__box mf-modal__box--lg">
      <div class="mf-modal__header"><h2 class="mf-modal__title">上传发票</h2><button type="button" class="mf-modal__close" data-mf-modal-close aria-label="关闭">&times;</button></div>
      <div class="mf-modal__body">
        <form method="post" enctype="multipart/form-data" id="invoiceForm">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="tab" value="<?= e($tab) ?>">
          <input type="hidden" name="biz" value="<?= e($biz) ?>">
          <div class="mf-form-item">
            <label class="mf-label">关联合同</label>
            <select class="mf-select" name="contract_id" required>
              <option value="">请选择合同</option>
              <?php foreach ($contracts as $c): ?>
                <?php if ((float) $c['pending_invoice_amount'] <= 0.00001) continue; ?>
                <option value="<?= (int) $c['id'] ?>"><?= e((string) $c['contract_no'] . ' - ' . (string) $c['contract_name']) ?>（待开票 ¥<?= number_format(max(0, (float) $c['pending_invoice_amount']), 2) ?>）</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mf-form-item"><label class="mf-label">开票金额</label><input class="mf-input" type="number" name="amount" min="0.01" step="0.01" required></div>
          <div class="mf-form-item"><label class="mf-label">备注</label><input class="mf-input" type="text" name="note" maxlength="255"></div>
          <div class="mf-form-item"><label class="mf-label">发票附件</label><input class="mf-input" type="file" name="invoice_file" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp"></div>
        </form>
      </div>
      <div class="mf-modal__footer mf-flex mf-gap-2 mf-justify-end"><button type="button" class="mf-btn mf-btn--default" data-mf-modal-close>取消</button><button type="submit" form="invoiceForm" class="mf-btn mf-btn--primary">提交</button></div>
    </div>
  </div>
</div>
<script>
(function () {
  var btn = document.getElementById('openInvoiceModal');
  if (btn) {
    btn.addEventListener('click', function () {
      if (window.MFModal) window.MFModal.show('invoiceModal');
    });
  }

  var tabs = document.querySelectorAll('.js-invoice-tab');
  var panes = document.querySelectorAll('.js-invoice-pane');
  tabs.forEach(function (tabBtn) {
    tabBtn.addEventListener('click', function () {
      var target = tabBtn.getAttribute('data-pane');
      panes.forEach(function (p) {
        p.style.display = p.id === target ? '' : 'none';
      });
      tabs.forEach(function (b) {
        b.classList.remove('mf-btn--primary');
        b.classList.add('mf-btn--default');
      });
      tabBtn.classList.remove('mf-btn--default');
      tabBtn.classList.add('mf-btn--primary');
    });
  });
})();
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';

