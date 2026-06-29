<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('contracts.view');
$pdo = db();
$admin = current_admin() ?? [];
$ownOnly = mf_own_contract_only_enabled($pdo) && (($admin['role'] ?? 'normal') !== 'super');
$currentAdminId = (int) ($admin['id'] ?? 0);
$isSuper = (string) ($admin['role'] ?? 'normal') === 'super';
$salesmen = [];
if ($isSuper) {
    try {
        $salesmen = $pdo->query("SELECT id, COALESCE(display_name, username) AS name, username FROM admins WHERE role = 'sales' AND status = 'normal' ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $salesmen = [];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bizPost = (string) ($_POST['biz'] ?? '');
    $bizQuery = in_array($bizPost, ['receipt', 'payment'], true) ? ('?biz=' . $bizPost) : '';
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'err=' . rawurlencode('会话已过期'));
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'transfer') {
        if (!$isSuper) {
            redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'err=' . rawurlencode('仅超级管理员可转交合同'));
        }
        $id = (int) ($_POST['id'] ?? 0);
        $newOwnerId = (int) ($_POST['new_owner_id'] ?? 0);
        if ($id <= 0 || $newOwnerId <= 0) {
            redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'err=' . rawurlencode('参数无效'));
        }
        $ownerSt = $pdo->prepare("SELECT id FROM admins WHERE id = ? AND role = 'sales' AND status = 'normal' LIMIT 1");
        $ownerSt->execute([$newOwnerId]);
        if (!$ownerSt->fetchColumn()) {
            redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'err=' . rawurlencode('业务员不存在或已禁用'));
        }
        $pdo->prepare('UPDATE contracts SET created_by = ? WHERE id = ?')->execute([$newOwnerId, $id]);
        redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'saved=1');
    } elseif ($action === 'delete') {
        if (!$isSuper) {
            redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'err=' . rawurlencode('仅超级管理员可删除合同'));
        }
        require_permission('contracts.delete');
        $id = (int) ($_POST['id'] ?? 0);
        if ($ownOnly) {
            $ownerSt = $pdo->prepare('SELECT created_by FROM contracts WHERE id = ?');
            $ownerSt->execute([$id]);
            if ((int) $ownerSt->fetchColumn() !== $currentAdminId) {
                redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'err=' . rawurlencode('仅可操作自己登记的合同'));
            }
        }
        $sf = $pdo->prepare('SELECT file_path FROM contract_files WHERE contract_id=?');
        $sf->execute([$id]);
        foreach ($sf->fetchAll() as $x) {
            $fp = __DIR__ . '/' . ltrim((string) $x['file_path'], '/');
            if (is_file($fp)) {
                @unlink($fp);
            }
        }
        // 删除收付款凭证文件（voucher）
        $vf = $pdo->prepare('SELECT voucher_path FROM contract_transactions WHERE contract_id = ? AND voucher_path IS NOT NULL AND voucher_path <> ""');
        $vf->execute([$id]);
        foreach ($vf->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $vp = __DIR__ . '/' . ltrim((string) ($x['voucher_path'] ?? ''), '/');
            if ($vp !== '' && is_file($vp)) {
                @unlink($vp);
            }
        }
        $pdo->prepare('DELETE FROM contracts WHERE id = ?')->execute([$id]);
        redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'deleted=1');
    } elseif ($action === 'update_status') {
        require_permission('contracts.update_status');
        $id = (int) ($_POST['id'] ?? 0);
        $newStatus = (string) ($_POST['new_status'] ?? '');
        if (!in_array($newStatus, ['completed', 'terminated'], true)) {
            redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'err=' . rawurlencode('仅允许更新为已完成或已终止'));
        }
        $contractSt = $pdo->prepare('SELECT payment_type, amount FROM contracts WHERE id = ? AND is_archived = 0');
        $contractSt->execute([$id]);
        $contract = $contractSt->fetch(PDO::FETCH_ASSOC);
        if (!$contract) {
            redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'err=' . rawurlencode('合同不存在或已归档'));
        }
        if ($ownOnly) {
            $ownerSt = $pdo->prepare('SELECT created_by FROM contracts WHERE id = ?');
            $ownerSt->execute([$id]);
            if ((int) $ownerSt->fetchColumn() !== $currentAdminId) {
                redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'err=' . rawurlencode('仅可操作自己登记的合同'));
            }
        }
        if ($newStatus === 'completed') {
            $done = mf_contract_done_amount($pdo, $id, (string) $contract['payment_type']);
            if ($done + 0.00001 < (float) $contract['amount']) {
                redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'err=' . rawurlencode('收付款金额未达到合同金额，不能设为已完成'));
            }
        }
        $pdo->prepare('UPDATE contracts SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'saved=1');
    } elseif ($action === 'archive') {
        require_permission('contracts.archive');
        $id = (int) ($_POST['id'] ?? 0);
        if ($ownOnly) {
            $ownerSt = $pdo->prepare('SELECT created_by FROM contracts WHERE id = ?');
            $ownerSt->execute([$id]);
            if ((int) $ownerSt->fetchColumn() !== $currentAdminId) {
                redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'err=' . rawurlencode('仅可操作自己登记的合同'));
            }
        }
        $st = $pdo->prepare('SELECT status FROM contracts WHERE id = ? AND is_archived = 0');
        $st->execute([$id]);
        $status = (string) ($st->fetchColumn() ?: '');
        if ($status !== 'completed') {
            redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'err=' . rawurlencode('仅已完成合同可归档'));
        }
        $pdo->prepare('UPDATE contracts SET is_archived = 1, archived_at = NOW() WHERE id = ?')->execute([$id]);
        redirect('orders.php' . $bizQuery . ($bizQuery === '' ? '?' : '&') . 'saved=1');
    }
}

$kw = trim((string) ($_GET['kw'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$expiryStart = trim((string) ($_GET['expiry_start'] ?? ''));
$expiryEnd = trim((string) ($_GET['expiry_end'] ?? ''));
$biz = (string) ($_GET['biz'] ?? '');
if (!in_array($biz, ['receipt', 'payment'], true)) {
    $biz = '';
}
$where = ['1=1'];
$params = [];
if ((string) ($_GET['archived'] ?? '0') !== '1') {
    $where[] = 'c.is_archived = 0';
}
if ($ownOnly) {
    $where[] = 'c.created_by = ?';
    $params[] = $currentAdminId;
}
if ($biz !== '') {
    $where[] = 'c.payment_type = ?';
    $params[] = $biz;
}
if ($kw !== '') {
    $where[] = '(c.contract_no LIKE ? OR c.project_no LIKE ? OR c.contract_name LIKE ? OR c.signer_party LIKE ? OR c.customer_name LIKE ?)';
    $like = '%' . $kw . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if (in_array($statusFilter, ['ongoing', 'completed', 'terminated', 'expiring', 'expired'], true)) {
    if ($statusFilter === 'expired') {
        $where[] = "c.status NOT IN ('completed','terminated') AND c.expiry_date IS NOT NULL AND c.expiry_date < CURDATE()";
    } else {
        $where[] = 'c.status = ?';
        $params[] = $statusFilter;
    }
}
// 截止日期范围筛选
if ($expiryStart !== '') {
    $where[] = 'c.expiry_date >= ?';
    $params[] = $expiryStart;
}
if ($expiryEnd !== '') {
    $where[] = 'c.expiry_date <= ?';
    $params[] = $expiryEnd;
}
$types = $pdo->query('SELECT id,name FROM contract_types ORDER BY id DESC')->fetchAll() ?: [];
$exportQuery = http_build_query([
    'archived' => 0,
    'biz' => $biz,
    'kw' => $kw,
    'status' => $statusFilter,
    'expiry_start' => $expiryStart,
    'expiry_end' => $expiryEnd,
]);
$st = $pdo->prepare(
    "SELECT COUNT(*)
     FROM contracts c
     WHERE " . implode(' AND ', $where)
);
$st->execute($params);
$totalRows = (int) $st->fetchColumn();
$pageSize = 20;
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
$page = max(1, (int) ($_GET['page'] ?? 1));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $pageSize;

$listParams = $params;
$listParams[] = $pageSize;
$listParams[] = $offset;

$st = $pdo->prepare(
    "SELECT c.*,
            c.is_subcontract,
            t.name AS type_name,
            CASE WHEN c.expiry_date IS NULL THEN NULL ELSE DATEDIFF(c.expiry_date, CURDATE()) END AS left_days,
            COALESCE((SELECT SUM(tx.amount) FROM contract_transactions tx WHERE tx.contract_id = c.id AND tx.tx_type = c.payment_type), 0) AS done_amount,
            COALESCE((SELECT SUM(iv.amount) FROM contract_invoices iv WHERE iv.contract_id = c.id AND iv.invoice_type = c.payment_type), 0) AS invoiced_amount,
            CASE
                WHEN c.status IN ('completed','terminated') THEN c.status
                WHEN c.expiry_date IS NOT NULL AND c.expiry_date < CURDATE() THEN 'expired'
                ELSE c.status
            END AS display_status,
            COALESCE(a.display_name, a.username, '-') AS creator_name
     FROM contracts c
     LEFT JOIN contract_types t ON t.id = c.type_id
     LEFT JOIN admins a ON a.id = c.created_by
     WHERE " . implode(' AND ', $where) . '
     ORDER BY c.id DESC
     LIMIT ? OFFSET ?'
);
$st->execute($listParams);
$rows = $st->fetchAll() ?: [];

$pageTitle = '合同管理';
$activeNav = 'orders';
ob_start();
?>
<div class="mf-panel">
  <div class="mf-panel__body">
    <form method="get" class="mf-row mf-row--tight mf-items-end mf-toolbar-row">
      <input type="hidden" name="biz" value="<?= e($biz) ?>">
      <div class="mf-col mf-col-12 mf-col-md-3"><label class="mf-label mf-small mf-text-muted mf-mb-0">关键词</label><input class="mf-input" name="kw" value="<?= e($kw) ?>" placeholder="合同编号/项目号/名称/甲方/客户名称"></div>
      <div class="mf-col mf-col-12 mf-col-md-2"><label class="mf-label mf-small mf-text-muted mf-mb-0">状态</label><select class="mf-select" name="status"><option value="">全部</option><option value="ongoing"<?= $statusFilter==='ongoing'?' selected':'' ?>>进行中</option><option value="completed"<?= $statusFilter==='completed'?' selected':'' ?>>已完成</option><option value="terminated"<?= $statusFilter==='terminated'?' selected':'' ?>>已终止</option><option value="expiring"<?= $statusFilter==='expiring'?' selected':'' ?>>即将到期</option><option value="expired"<?= $statusFilter==='expired'?' selected':'' ?>>已过期</option></select></div>
      <div class="mf-col mf-col-12 mf-col-md-4"><label class="mf-label mf-small mf-text-muted mf-mb-0">截止日期范围</label><div class="mf-flex mf-items-center mf-gap-1"><input type="date" class="mf-input" name="expiry_start" value="<?= e($expiryStart) ?>" style="width:45%"><span style="color:#909399;">~</span><input type="date" class="mf-input" name="expiry_end" value="<?= e($expiryEnd) ?>" style="width:45%"></div></div>
      <div class="mf-col mf-col-12 mf-col-md-3 mf-toolbar-actions"><button class="mf-btn mf-btn--primary">查询</button><a class="mf-btn mf-btn--default" href="<?= e(url('orders.php' . ($biz !== '' ? ('?biz=' . $biz) : ''))) ?>">重置</a><span class="mf-flex-grow mf-toolbar-actions__spacer"></span><?php if ($biz !== ''): ?><a class="mf-btn mf-btn--success" href="<?= e(url('import.php?biz=' . $biz)) ?>"><i class="bi bi-upload"></i> 批量导入</a><?php endif; ?><a class="mf-btn mf-btn--default" href="<?= e(url('contracts_export.php' . ($exportQuery !== '' ? ('?' . $exportQuery) : ''))) ?>">导出</a><a class="mf-btn mf-btn--primary" href="<?= e(url('contract_form.php' . ($biz !== '' ? ('?payment_type=' . $biz) : ''))) ?>">+ 新增合同</a></div>
    </form>
  </div>
</div>
<div class="mf-panel">
  <div class="mf-table-wrap" id="ordersListWrap">
    <style>
      #ordersListWrap{
        height:540px;
        overflow-x:auto;
        overflow-y:visible;
      }
      .mf-op-menu{position:relative;display:inline-block}
      .mf-op-menu summary{list-style:none;cursor:pointer;border:1px solid #dcdfe6;border-radius:4px;padding:2px 8px;background:#fff;color:#606266}
      .mf-op-menu summary::-webkit-details-marker{display:none}
      .mf-op-menu[open] .mf-op-menu__panel{display:block}
      /* 向左弹出，避免被右侧滚动条/边界遮挡 */
      .mf-op-menu__panel{display:none;position:absolute;right:100%;top:-2px;margin-right:6px;z-index:9999;min-width:140px;background:#fff;border:1px solid #dcdfe6;border-radius:6px;box-shadow:0 6px 16px rgba(0,0,0,.12);padding:4px 0}
      .mf-op-menu__item{display:block;width:100%;padding:8px 12px;color:#303133;text-decoration:none;background:#fff;border:0;text-align:left;cursor:pointer;font-size:13px}
      .mf-op-menu__item:hover{background:#f5f7fa}
      .mf-op-menu__item--danger{color:#f56c6c}
      #ordersListWrap td:last-child{overflow:visible}
    </style>
    <table class="mf-table mf-table--striped table-mf mf-mb-0">
      <thead>
      <tr>
        <th>合同编号</th>
        <th>项目号</th>
        <th>项目名称</th>
        <th>合同名称</th>
        <?php if ($biz === ''): ?><th>类型</th><?php endif; ?>
        <?php if ($biz === ''): ?><th>款项类型</th><?php endif; ?>
        <th><?= $biz === 'payment' ? '乙方' : '甲方' ?></th>
        <th>合同金额</th>
        <th>已登记金额</th>
        <th>已开票金额</th>
        <th>待开票金额</th>
        <th>截止日期</th>
        <th>剩余天数</th>
        <th>状态</th>
        <th>操作</th>
      </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $r):
        // 检查必填项是否缺失
        $missingProjectNo = empty($r['project_no']) || trim($r['project_no']) === '';
        $missingAmount = (float) $r['amount'] <= 0;
        $missingExpiryDate = empty($r['expiry_date']);
        $hasMissing = $missingProjectNo || $missingAmount || $missingExpiryDate;

        // 构建缺失提示
        $missingTips = [];
        if ($missingProjectNo) $missingTips[] = '项目号';
        if ($missingAmount) $missingTips[] = '合同金额';
        if ($missingExpiryDate) $missingTips[] = '截止日期';
        $missingTitle = '缺失：' . implode('、', $missingTips) . '，请补充';
      ?>
        <tr <?= $hasMissing ? 'style="background-color:#fef0f0;" title="' . e($missingTitle) . '"' : '' ?>>
          <td><?= e((string) $r['contract_no']) ?></td>
          <td><?= e((string) ($r['project_no'] ?: '-')) ?></td>
          <td><?= e((string) ($r['project_name'] ?: '-')) ?></td>
          <td>
            <?php $contractName = (string) $r['contract_name']; ?>
            <a
              href="<?= e(url('contract_view.php?id=' . (int) $r['id'] . ($biz !== '' ? '&biz=' . rawurlencode($biz) : ''))) ?>"
              title="<?= e($contractName) ?>"
              style="display:inline-block;max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;vertical-align:bottom;"
            ><?= e($contractName) ?></a>
            <?= mf_subcontract_tag($contractName, (int) ($r['is_subcontract'] ?? 0)) ?>
          </td>
          <?php if ($biz === ''): ?><td><?= e((string) ($r['type_name'] ?: '-')) ?></td><?php endif; ?>
          <?php if ($biz === ''): ?><td><?= mf_payment_type_badge((string) ($r['payment_type'] ?? 'receipt')) ?></td><?php endif; ?>
          <td><?= e((string) $r['signer_party']) ?></td>
          <td><?= number_format((float) $r['amount'], 2) ?></td>
          <td><?= number_format((float) $r['done_amount'], 2) ?></td>
          <td><?= number_format((float) ($r['invoiced_amount'] ?? 0), 2) ?></td>
          <td><?= number_format(max(0, (float) $r['done_amount'] - (float) ($r['invoiced_amount'] ?? 0)), 2) ?></td>
          <td><?= e((string) ($r['expiry_date'] ?: '-')) ?></td>
          <td><?php if ((string) $r['status'] === 'completed' || $r['left_days'] === null): ?>-<?php else: ?><?= (int) $r['left_days'] ?> 天<?php endif; ?></td>
          <td><?= mf_contract_status_badge((string) ($r['display_status'] ?? $r['status'])) ?></td>
          <td>
            <details class="mf-op-menu">
              <summary>⋮</summary>
              <div class="mf-op-menu__panel">
                <a class="mf-op-menu__item" href="<?= e(url('contract_view.php?id=' . (int) $r['id'] . ($biz !== '' ? '&biz=' . rawurlencode($biz) : ''))) ?>">查看详情</a>
                <?php if ((string) $r['status'] !== 'completed'): ?>
                  <a class="mf-op-menu__item" href="<?= e(url('contract_form.php?id=' . (int) $r['id'] . ($biz !== '' ? '&biz=' . rawurlencode($biz) : ''))) ?>">编辑</a>
                  <button
                    type="button"
                    class="mf-op-menu__item js-open-status"
                    data-id="<?= (int) $r['id'] ?>"
                    data-name="<?= e((string) $r['contract_no'] . ' - ' . $r['contract_name']) ?>"
                    data-status="<?= e((string) $r['status']) ?>"
                  >更新</button>
                <?php endif; ?>
                <a class="mf-op-menu__item" href="<?= e(url('contract_export.php?id=' . (int) $r['id'])) ?>">导出</a>
                <?php if ($isSuper): ?>
                  <button
                    type="button"
                    class="mf-op-menu__item js-open-transfer"
                    data-id="<?= (int) $r['id'] ?>"
                    data-name="<?= e((string) $r['contract_no'] . ' - ' . $r['contract_name']) ?>"
                    data-current-owner="<?= e((string) ($r['creator_name'] ?? '')) ?>"
                  >转交</button>
                <?php endif; ?>
                <?php if ((string) $r['status'] === 'completed'): ?>
                  <form method="post">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="archive">
                    <input type="hidden" name="biz" value="<?= e($biz) ?>">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button class="mf-op-menu__item" type="submit">归档</button>
                  </form>
                <?php endif; ?>
                <?php if ($isSuper): ?>
                  <form method="post" id="deleteForm<?= (int) $r['id'] ?>">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="biz" value="<?= e($biz) ?>">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <button
                      class="mf-op-menu__item mf-op-menu__item--danger js-open-delete"
                      type="button"
                      data-form-id="deleteForm<?= (int) $r['id'] ?>"
                      data-name="<?= e((string) $r['contract_no'] . ' - ' . $r['contract_name']) ?>"
                    >删除</button>
                  </form>
                <?php endif; ?>
              </div>
            </details>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?>
        <tr><td colspan="<?= $biz !== '' ? '12' : '14' ?>" class="mf-text-center mf-text-muted mf-p-4">暂无合同</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($totalPages > 1): ?>
<div class="mf-panel">
  <div class="mf-panel__body mf-flex mf-justify-between mf-items-center">
    <div class="mf-small mf-text-muted">共 <?= (int) $totalRows ?> 条，每页 <?= (int) $pageSize ?> 条</div>
    <div class="mf-flex mf-gap-1">
      <?php
      $baseQuery = [
          'biz' => $biz,
          'kw' => $kw,
          'status' => $statusFilter,
          'type_id' => $typeFilter,
          'reach' => $reachFilter,
      ];
      $prev = $page > 1 ? $page - 1 : 1;
      $next = $page < $totalPages ? $page + 1 : $totalPages;
      ?>
      <a class="mf-btn mf-btn--default mf-btn--sm<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= e(url('orders.php?' . http_build_query(array_merge($baseQuery, ['page' => $prev])))) ?>">上一页</a>
      <span class="mf-small" style="line-height:28px;padding:0 8px;"><?= (int) $page ?> / <?= (int) $totalPages ?></span>
      <a class="mf-btn mf-btn--default mf-btn--sm<?= $page >= $totalPages ? ' disabled' : '' ?>" href="<?= e(url('orders.php?' . http_build_query(array_merge($baseQuery, ['page' => $next])))) ?>">下一页</a>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="mf-modal" id="contractDeleteModal" aria-hidden="true">
  <div class="mf-modal__mask" data-mf-modal-close></div>
  <div class="mf-modal__wrap">
    <div class="mf-modal__box">
      <div class="mf-modal__header">
        <h2 class="mf-modal__title">确认删除合同</h2>
        <button type="button" class="mf-modal__close" data-mf-modal-close aria-label="关闭">&times;</button>
      </div>
      <div class="mf-modal__body">
        <div class="mf-text-danger">删除后将同时删除附件与关联记录，此操作不可恢复。</div>
        <div class="mf-mt-2">请确认是否删除：<span id="deleteContractName" class="mf-text-muted"></span></div>
      </div>
      <div class="mf-modal__footer mf-flex mf-gap-2 mf-justify-end">
        <button type="button" class="mf-btn mf-btn--default" data-mf-modal-close>取消</button>
        <button type="button" class="mf-btn mf-btn--danger" id="deleteContractConfirmBtn">确认删除</button>
      </div>
    </div>
  </div>
</div>

<?php if ($isSuper): ?>
<div class="mf-modal" id="contractTransferModal" aria-hidden="true">
  <div class="mf-modal__mask" data-mf-modal-close></div>
  <div class="mf-modal__wrap">
    <div class="mf-modal__box mf-modal__box--lg">
      <div class="mf-modal__header">
        <h2 class="mf-modal__title">合同转交</h2>
        <button type="button" class="mf-modal__close" data-mf-modal-close aria-label="关闭">&times;</button>
      </div>
      <div class="mf-modal__body" style="max-height:65vh;overflow:auto;">
        <form method="post" id="contractTransferForm">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="transfer">
          <input type="hidden" name="biz" value="<?= e($biz) ?>">
          <input type="hidden" name="id" id="transferContractId" value="0">
          <input type="hidden" name="new_owner_id" id="transferNewOwnerId" value="0">

          <div class="mf-form-item">
            <label class="mf-label">合同</label>
            <input type="text" class="mf-input" id="transferContractName" value="" disabled>
          </div>
          <div class="mf-form-item">
            <label class="mf-label">当前创建人</label>
            <input type="text" class="mf-input" id="transferCurrentOwner" value="" disabled>
          </div>
          <div class="mf-form-item">
            <label class="mf-label">转交给业务员</label>
            <?php if ($salesmen): ?>
              <input
                class="mf-input"
                id="transferNewOwnerInput"
                type="text"
                list="transferSalesmenList"
                autocomplete="off"
                placeholder="输入姓名/账号，下拉联想选择"
              >
              <datalist id="transferSalesmenList">
                <?php foreach ($salesmen as $s): ?>
                  <?php
                    $sid = (int) ($s['id'] ?? 0);
                    $sname = (string) ($s['name'] ?? '');
                    $sun = (string) ($s['username'] ?? '');
                    $label = trim($sname !== '' ? ($sname . ($sun !== '' ? ('（' . $sun . '）') : '')) : $sun);
                    $label = $label !== '' ? $label : '-';
                  ?>
                  <option value="<?= e($label) ?>"></option>
                <?php endforeach; ?>
              </datalist>
              <div class="mf-small mf-text-muted mf-mt-1">输入后在下拉中选择。转交只改变合同创建人，收付款登记记录的登记人不会变化。</div>
            <?php else: ?>
              <div class="mf-text-muted mf-small">暂无可用业务员（请先在“业务员管理”里新增业务员）。</div>
            <?php endif; ?>
          </div>
        </form>
      </div>
      <div class="mf-modal__footer mf-flex mf-gap-2 mf-justify-end">
        <button type="button" class="mf-btn mf-btn--default" data-mf-modal-close>取消</button>
        <button type="submit" form="contractTransferForm" class="mf-btn mf-btn--primary"<?= $salesmen ? '' : ' disabled' ?>>确认转交</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="mf-modal" id="contractStatusModal" aria-hidden="true">
  <div class="mf-modal__mask" data-mf-modal-close></div>
  <div class="mf-modal__wrap">
    <div class="mf-modal__box">
      <div class="mf-modal__header">
        <h2 class="mf-modal__title">更新合同状态</h2>
        <button type="button" class="mf-modal__close" data-mf-modal-close aria-label="关闭">&times;</button>
      </div>
      <div class="mf-modal__body">
        <form method="post" id="contractStatusForm">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="biz" value="<?= e($biz) ?>">
          <input type="hidden" name="id" id="statusContractId" value="0">
          <div class="mf-form-item">
            <label class="mf-label">合同</label>
            <input type="text" class="mf-input" id="statusContractName" value="" disabled>
          </div>
          <div class="mf-form-item">
            <label class="mf-label">新状态</label>
            <select class="mf-select" name="new_status" id="statusNewValue">
              <option value="completed">已完成</option>
              <option value="terminated">已终止</option>
            </select>
          </div>
        </form>
      </div>
      <div class="mf-modal__footer mf-flex mf-gap-2 mf-justify-end">
        <button type="button" class="mf-btn mf-btn--default" data-mf-modal-close>取消</button>
        <button type="submit" form="contractStatusForm" class="mf-btn mf-btn--primary">保存状态</button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  var pendingDeleteForm = null;
  var deleteButtons = document.querySelectorAll('.js-open-delete');
  var deleteConfirmBtn = document.getElementById('deleteContractConfirmBtn');
  var deleteNameEl = document.getElementById('deleteContractName');
  deleteButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var fid = btn.getAttribute('data-form-id') || '';
      pendingDeleteForm = fid ? document.getElementById(fid) : null;
      if (deleteNameEl) deleteNameEl.textContent = btn.getAttribute('data-name') || '';
      if (window.MFModal) window.MFModal.show('contractDeleteModal');
    });
  });
  if (deleteConfirmBtn) {
    deleteConfirmBtn.addEventListener('click', function () {
      if (pendingDeleteForm) pendingDeleteForm.submit();
    });
  }

  var buttons = document.querySelectorAll('.js-open-status');
  if (buttons.length) {
    buttons.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var idEl = document.getElementById('statusContractId');
        var nameEl = document.getElementById('statusContractName');
        var statusEl = document.getElementById('statusNewValue');
        var id = btn.getAttribute('data-id') || '0';
        var name = btn.getAttribute('data-name') || '';
        var current = btn.getAttribute('data-status') || '';
        if (idEl) idEl.value = id;
        if (nameEl) nameEl.value = name;
        if (statusEl) {
          statusEl.value = current === 'terminated' ? 'terminated' : 'completed';
        }
        if (window.MFModal) window.MFModal.show('contractStatusModal');
      });
    });
  }

  var transferBtns = document.querySelectorAll('.js-open-transfer');
  if (transferBtns.length) {
    var salesmen = <?= json_encode($salesmen, JSON_UNESCAPED_UNICODE) ?> || [];
    var idByLabel = {};
    salesmen.forEach(function (s) {
      var sid = String(s.id || '');
      var name = (s.name || '').trim();
      var un = (s.username || '').trim();
      var label = (name ? (name + (un ? ('（' + un + '）') : '')) : un);
      if (!label) return;
      idByLabel[label] = Number(sid || 0);
      // 也允许直接输入账号
      if (un) idByLabel[un] = Number(sid || 0);
    });

    transferBtns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var id = btn.getAttribute('data-id') || '0';
        var name = btn.getAttribute('data-name') || '';
        var owner = btn.getAttribute('data-current-owner') || '';
        var idEl = document.getElementById('transferContractId');
        var nameEl = document.getElementById('transferContractName');
        var ownerEl = document.getElementById('transferCurrentOwner');
        var newOwnerIdEl = document.getElementById('transferNewOwnerId');
        var newOwnerInput = document.getElementById('transferNewOwnerInput');
        if (idEl) idEl.value = id;
        if (nameEl) nameEl.value = name;
        if (ownerEl) ownerEl.value = owner;
        if (newOwnerIdEl) newOwnerIdEl.value = '0';
        if (newOwnerInput) newOwnerInput.value = '';
        if (window.MFModal) window.MFModal.show('contractTransferModal');
      });
    });

    var newOwnerInput = document.getElementById('transferNewOwnerInput');
    var newOwnerIdEl = document.getElementById('transferNewOwnerId');
    if (newOwnerInput && newOwnerIdEl) {
      function syncOwnerId() {
        var v = (newOwnerInput.value || '').trim();
        var id = idByLabel[v] || 0;
        newOwnerIdEl.value = String(id);
      }
      newOwnerInput.addEventListener('change', syncOwnerId);
      newOwnerInput.addEventListener('input', syncOwnerId);

      var form = document.getElementById('contractTransferForm');
      if (form) {
        form.addEventListener('submit', function (e) {
          syncOwnerId();
          if (Number(newOwnerIdEl.value || 0) <= 0) {
            e.preventDefault();
            if (window.MFToast) window.MFToast.warning('请选择要转交的业务员', '提示');
          }
        });
      }
    }
  }

  // 点击空白处收起“操作”下拉菜单
  document.addEventListener('click', function (e) {
    var menus = document.querySelectorAll('.mf-op-menu[open]');
    menus.forEach(function (m) {
      if (!m.contains(e.target)) {
        m.removeAttribute('open');
      }
    });
  });
})();
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
