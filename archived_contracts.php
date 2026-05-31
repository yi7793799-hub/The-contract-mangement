<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('archived.view');
$pdo = db();
$admin = current_admin() ?? [];
$isSuper = (string) ($admin['role'] ?? 'normal') === 'super';
$ownOnly = mf_own_contract_only_enabled($pdo) && (($admin['role'] ?? 'normal') !== 'super');
$currentAdminId = (int) ($admin['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        redirect('archived_contracts.php?err=' . rawurlencode('请求已过期，请刷新后重试'));
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'delete') {
        if (!$isSuper) {
            redirect('archived_contracts.php?err=' . rawurlencode('仅超级管理员可删除合同'));
        }
        require_permission('contracts.delete');
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $checkSt = $pdo->prepare('SELECT id, created_by FROM contracts WHERE id = ? AND is_archived = 1 LIMIT 1');
            $checkSt->execute([$id]);
            $target = $checkSt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                redirect('archived_contracts.php?err=' . rawurlencode('归档合同不存在'));
            }
            if ($ownOnly && (int) ($target['created_by'] ?? 0) !== $currentAdminId) {
                redirect('archived_contracts.php?err=' . rawurlencode('仅可操作自己登记的合同'));
            }

            $sf = $pdo->prepare('SELECT file_path FROM contract_files WHERE contract_id = ?');
            $sf->execute([$id]);
            foreach ($sf->fetchAll(PDO::FETCH_ASSOC) ?: [] as $x) {
                $fp = __DIR__ . '/' . ltrim((string) ($x['file_path'] ?? ''), '/');
                if (is_file($fp)) {
                    @unlink($fp);
                }
            }

            $vf = $pdo->prepare('SELECT voucher_path FROM contract_transactions WHERE contract_id = ? AND voucher_path IS NOT NULL AND voucher_path <> ""');
            $vf->execute([$id]);
            foreach ($vf->fetchAll(PDO::FETCH_ASSOC) ?: [] as $x) {
                $vp = __DIR__ . '/' . ltrim((string) ($x['voucher_path'] ?? ''), '/');
                if (is_file($vp)) {
                    @unlink($vp);
                }
            }

            if (db_table_exists($pdo, 'contract_invoices')) {
                $if = $pdo->prepare('SELECT file_path FROM contract_invoices WHERE contract_id = ? AND file_path IS NOT NULL AND file_path <> ""');
                $if->execute([$id]);
                foreach ($if->fetchAll(PDO::FETCH_ASSOC) ?: [] as $x) {
                    $ip = __DIR__ . '/' . ltrim((string) ($x['file_path'] ?? ''), '/');
                    if (is_file($ip)) {
                        @unlink($ip);
                    }
                }
            }

            $delSt = $pdo->prepare('DELETE FROM contracts WHERE id = ? LIMIT 1');
            $delSt->execute([$id]);
        }
        redirect('archived_contracts.php?deleted=1');
    }
}

$kw = trim((string) ($_GET['kw'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$typeFilter = (int) ($_GET['type_id'] ?? 0);
$paymentFilter = (string) ($_GET['payment_type'] ?? '');

$where = ['c.is_archived = 1'];
$params = [];
if ($kw !== '') {
    $where[] = '(c.contract_no LIKE ? OR c.contract_name LIKE ? OR c.customer_name LIKE ?)';
    $like = '%' . $kw . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if (in_array($statusFilter, ['ongoing', 'completed', 'terminated', 'expiring'], true)) {
    $where[] = 'c.status = ?';
    $params[] = $statusFilter;
}
if ($typeFilter > 0) {
    $where[] = 'c.type_id = ?';
    $params[] = $typeFilter;
}
if (in_array($paymentFilter, ['receipt', 'payment'], true)) {
    $where[] = 'c.payment_type = ?';
    $params[] = $paymentFilter;
}
if ($ownOnly) {
    $where[] = 'c.created_by = ?';
    $params[] = $currentAdminId;
}

$types = $pdo->query('SELECT id,name FROM contract_types ORDER BY id DESC')->fetchAll() ?: [];
$st = $pdo->prepare(
    "SELECT c.*, t.name AS type_name, COALESCE(a.display_name, a.username, '-') AS creator_name,
            COALESCE((SELECT SUM(tx.amount) FROM contract_transactions tx WHERE tx.contract_id = c.id AND tx.tx_type = c.payment_type),0) AS done_amount
     FROM contracts c
     LEFT JOIN contract_types t ON t.id = c.type_id
     LEFT JOIN admins a ON a.id = c.created_by
     WHERE " . implode(' AND ', $where) . '
     ORDER BY c.archived_at DESC, c.id DESC'
);
$st->execute($params);
$rows = $st->fetchAll() ?: [];

$exportQuery = http_build_query([
    'archived' => 1,
    'kw' => $kw,
    'status' => $statusFilter,
    'type_id' => $typeFilter,
    'payment_type' => $paymentFilter,
]);

$pageTitle = '归档合同';
$activeNav = 'archived_contracts';
ob_start();
?>
<div class="mf-panel">
  <div class="mf-panel__body">
    <?php if ((string) ($_GET['deleted'] ?? '') === '1'): ?><div class="mf-alert mf-alert--success mf-mb-2">删除成功</div><?php endif; ?>
    <?php if ((string) ($_GET['err'] ?? '') !== ''): ?><div class="mf-alert mf-alert--danger mf-mb-2"><?= e((string) $_GET['err']) ?></div><?php endif; ?>
    <form method="get" class="mf-row mf-row--tight mf-items-end mf-toolbar-row">
      <div class="mf-col mf-col-12 mf-col-md-3"><label class="mf-label mf-small mf-text-muted mf-mb-0">关键词</label><input class="mf-input" name="kw" value="<?= e($kw) ?>" placeholder="合同编号/名称/客户名称"></div>
      <div class="mf-col mf-col-12 mf-col-md-2"><label class="mf-label mf-small mf-text-muted mf-mb-0">状态</label><select class="mf-select" name="status"><option value="">全部</option><option value="ongoing"<?= $statusFilter==='ongoing'?' selected':'' ?>>进行中</option><option value="completed"<?= $statusFilter==='completed'?' selected':'' ?>>已完成</option><option value="terminated"<?= $statusFilter==='terminated'?' selected':'' ?>>已终止</option><option value="expiring"<?= $statusFilter==='expiring'?' selected':'' ?>>即将到期</option></select></div>
      <div class="mf-col mf-col-12 mf-col-md-2"><label class="mf-label mf-small mf-text-muted mf-mb-0">合同类型</label><select class="mf-select" name="type_id"><option value="0">全部</option><?php foreach ($types as $t): ?><option value="<?= (int)$t['id'] ?>"<?= (int)$t['id']===$typeFilter?' selected':'' ?>><?= e((string)$t['name']) ?></option><?php endforeach; ?></select></div>
      <div class="mf-col mf-col-12 mf-col-md-2"><label class="mf-label mf-small mf-text-muted mf-mb-0">款项类型</label><select class="mf-select" name="payment_type"><option value="">全部</option><option value="receipt"<?= $paymentFilter==='receipt'?' selected':'' ?>>收款</option><option value="payment"<?= $paymentFilter==='payment'?' selected':'' ?>>付款</option></select></div>
      <div class="mf-col mf-col-12 mf-col-md-3 mf-toolbar-actions"><button class="mf-btn mf-btn--primary">查询</button><a class="mf-btn mf-btn--default" href="<?= e(url('archived_contracts.php')) ?>">重置</a><span class="mf-flex-grow mf-toolbar-actions__spacer"></span><a class="mf-btn mf-btn--default" href="<?= e(url('contracts_export.php' . ($exportQuery !== '' ? ('?' . $exportQuery) : ''))) ?>">导出</a></div>
    </form>
  </div>
</div>
<div class="mf-panel">
  <div class="mf-table-wrap">
    <table class="mf-table mf-table--striped table-mf mf-mb-0">
      <thead><tr><th>合同编号</th><th>合同名称</th><th>类型</th><th>款项类型</th><th>创建人</th><th>合同金额</th><th>已登记金额</th><th>状态</th><th>归档时间</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e((string) $r['contract_no']) ?></td>
          <td><a href="<?= e(url('contract_view.php?id=' . (int) $r['id'])) ?>"><?= e((string) $r['contract_name']) ?></a></td>
          <td><?= e((string) ($r['type_name'] ?: '-')) ?></td>
          <td><?= mf_payment_type_badge((string) ($r['payment_type'] ?? 'receipt')) ?></td>
          <td><?= e((string) ($r['creator_name'] ?? '-')) ?></td>
          <td>¥<?= number_format((float) $r['amount'], 2) ?></td>
          <td>¥<?= number_format((float) $r['done_amount'], 2) ?></td>
          <td><?= mf_contract_status_badge((string) $r['status']) ?></td>
          <td><?= e((string) ($r['archived_at'] ?: '-')) ?></td>
          <td>
            <div class="mf-flex mf-items-center mf-gap-1">
              <a class="mf-btn mf-btn--default mf-btn--sm" href="<?= e(url('contract_export.php?id=' . (int) $r['id'])) ?>">导出</a>
              <?php if ($isSuper): ?>
                <form method="post" id="deleteArchivedForm<?= (int) $r['id'] ?>">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button
                    type="button"
                    class="mf-btn mf-btn--danger mf-btn--sm js-open-archived-delete"
                    data-form-id="deleteArchivedForm<?= (int) $r['id'] ?>"
                    data-name="<?= e((string) $r['contract_name']) ?>"
                  >删除</button>
                </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="10" class="mf-text-center mf-text-muted mf-p-4">暂无归档合同</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<div class="mf-modal" id="archivedDeleteModal" aria-hidden="true">
  <div class="mf-modal__mask" data-mf-modal-close></div>
  <div class="mf-modal__wrap">
    <div class="mf-modal__box">
      <div class="mf-modal__header">
        <h2 class="mf-modal__title">确认删除归档合同</h2>
        <button type="button" class="mf-modal__close" data-mf-modal-close aria-label="关闭">&times;</button>
      </div>
      <div class="mf-modal__body">
        <div class="mf-text-danger">删除后将同时删除附件、收付款凭证及发票附件，此操作不可恢复。</div>
        <div class="mf-mt-2">请确认是否删除：<span id="archivedDeleteContractName" class="mf-text-muted"></span></div>
      </div>
      <div class="mf-modal__footer mf-flex mf-gap-2 mf-justify-end">
        <button type="button" class="mf-btn mf-btn--default" data-mf-modal-close>取消</button>
        <button type="button" class="mf-btn mf-btn--danger" id="archivedDeleteConfirmBtn">确认删除</button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var confirmBtn = document.getElementById('archivedDeleteConfirmBtn');
  var nameEl = document.getElementById('archivedDeleteContractName');
  var targetForm = null;

  document.querySelectorAll('.js-open-archived-delete').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var formId = btn.getAttribute('data-form-id') || '';
      targetForm = formId ? document.getElementById(formId) : null;
      if (nameEl) nameEl.textContent = btn.getAttribute('data-name') || '';
      if (window.MFModal) {
        window.MFModal.show('archivedDeleteModal');
      }
    });
  });

  if (confirmBtn) {
    confirmBtn.addEventListener('click', function () {
      if (targetForm) targetForm.submit();
    });
  }
})();
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
