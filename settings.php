<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('types.view');

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('types.edit');
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        redirect('settings.php?err=' . rawurlencode('会话已过期'));
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM contract_types WHERE id = ?')->execute([(int) ($_POST['id'] ?? 0)]);
        redirect('settings.php?deleted=1');
    }
}

$rows = $pdo->query(
    "SELECT t.*, COUNT(c.id) AS contract_count, COALESCE(SUM(c.amount),0) AS total_amount
     FROM contract_types t
     LEFT JOIN contracts c ON c.type_id = t.id
     GROUP BY t.id
     ORDER BY t.id DESC"
)->fetchAll() ?: [];
$pageTitle = '类型管理';
$activeNav = 'types';
ob_start();
?>
<div class="mf-panel">
<div class="mf-panel__body mf-flex mf-justify-end">
  <a class="mf-btn mf-btn--primary" href="<?= e(url('type_form.php')) ?>">+ 新增类型</a>
</div>
</div>
<div class="mf-panel"><div class="mf-table-wrap"><table class="mf-table mf-table--striped table-mf mf-mb-0"><thead><tr><th>名称</th><th>备注</th><th>合同数</th><th>合同金额</th><th>操作</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= e((string)$r['name']) ?></td><td><?= e((string)$r['remark']) ?></td><td><?= (int)$r['contract_count'] ?></td><td>¥<?= number_format((float)$r['total_amount'],2) ?></td><td><a class="mf-btn mf-btn--default mf-btn--sm" href="<?= e(url('type_form.php?id='.(int)$r['id'])) ?>">编辑</a><form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="mf-btn mf-btn--danger mf-btn--sm" type="submit">删除</button></form></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="5" class="mf-text-center mf-text-muted mf-p-4">暂无类型</td></tr><?php endif; ?></tbody></table></div></div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
