<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_permission('users.view');
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('users.edit');
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        redirect('users.php?err=' . rawurlencode('会话已过期'));
    }
    if ((string) ($_POST['action'] ?? '') === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 1) {
            $pdo->prepare('DELETE FROM admins WHERE id=? AND role="sales"')->execute([$id]);
        }
        redirect('users.php?deleted=1');
    }
}

$rows = $pdo->query("SELECT id,username,display_name,phone,status,role,permissions,created_at FROM admins WHERE role='sales' ORDER BY id ASC")->fetchAll() ?: [];
$pageTitle = '业务员管理';
$activeNav = 'users';
ob_start();
?>
<div class="mf-panel"><div class="mf-panel__body mf-flex mf-justify-end"><?php if (admin_can('users.edit')): ?><a class="mf-btn mf-btn--primary" href="<?= e(url('user_form.php')) ?>">+ 新增业务员</a><?php endif; ?></div></div>
<div class="mf-panel"><div class="mf-table-wrap"><table class="mf-table mf-table--striped table-mf mf-mb-0"><thead><tr><th>姓名</th><th>手机号</th><th>登录账号</th><th>角色</th><th>状态</th><th>权限数</th><th>创建时间</th><th>操作</th></tr></thead><tbody><?php foreach($rows as $r): $pc = 0; $rp=(string)($r['permissions']??''); if($rp!==''){ $d=json_decode($rp,true); if(is_array($d)) $pc=count($d);} ?><tr><td><?= e((string)$r['display_name']) ?></td><td><?= e((string)($r['phone']?:'-')) ?></td><td><?= e((string)$r['username']) ?></td><td>业务员</td><td><?= (string)$r['status']==='normal'?'正常':'禁用' ?></td><td><?= (int)$pc ?></td><td><?= e((string)$r['created_at']) ?></td><td><?php if (admin_can('users.edit')): ?><a class="mf-btn mf-btn--default mf-btn--sm" href="<?= e(url('user_form.php?id='.(int)$r['id'])) ?>">编辑</a><form method="post" style="display:inline;"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="mf-btn mf-btn--danger mf-btn--sm" type="submit">删除</button></form><?php endif; ?></td></tr><?php endforeach; ?><?php if (!$rows): ?><tr><td colspan="8" class="mf-text-center mf-text-muted mf-p-4">暂无业务员</td></tr><?php endif; ?></tbody></table></div></div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
