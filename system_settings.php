<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_login();
redirect('dashboard.php?err=' . rawurlencode('管理员设置已下线'));

$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('admins.edit');
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        redirect('system_settings.php?err=' . rawurlencode('会话已过期'));
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 1) {
            $pdo->prepare('DELETE FROM admins WHERE id=? AND role="normal"')->execute([$id]);
        }
        redirect('system_settings.php?deleted=1');
    }
}
$rows = $pdo->query("SELECT id,username,display_name,role,status,created_at FROM admins ORDER BY id ASC")->fetchAll() ?: [];

$pageTitle = '管理员设置';
$activeNav = 'system';
ob_start();
?>
<div class="mf-panel"><div class="mf-panel__body mf-flex mf-justify-end"><a class="mf-btn mf-btn--primary" href="<?= e(url('admin_form.php')) ?>">+ 新增管理员</a></div></div>
<div class="mf-panel"><div class="mf-table-wrap"><table class="mf-table mf-table--striped table-mf mf-mb-0"><thead><tr><th>账号</th><th>显示名称</th><th>角色</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= e((string)$r['username']) ?></td><td><?= e((string)$r['display_name']) ?></td><td><?= ($r['role']==='super'?'超级管理员':'普通管理员') ?></td><td><?= ($r['status']==='normal'?'正常':'禁用') ?></td><td><?= e((string)$r['created_at']) ?></td><td><?php if($r['role']!=='super'): ?><a class="mf-btn mf-btn--default mf-btn--sm" href="<?= e(url('admin_form.php?id='.(int)$r['id'])) ?>">编辑</a><form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="mf-btn mf-btn--danger mf-btn--sm">删除</button></form><?php else: ?><span class="mf-small mf-text-muted">内置账户</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
