<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
$row = ['id' => 0, 'name' => '', 'remark' => ''];
if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM contract_types WHERE id=?');
    $st->execute([$id]);
    $f = $st->fetch();
    if (!$f) {
        redirect('settings.php?err=' . rawurlencode('类型不存在'));
    }
    $row = $f;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        redirect('settings.php?err=' . rawurlencode('会话已过期'));
    }
    $id = (int) ($_POST['id'] ?? 0);
    $name = trim((string) ($_POST['name'] ?? ''));
    $remark = trim((string) ($_POST['remark'] ?? ''));
    if ($name === '') {
        redirect('type_form.php' . ($id > 0 ? '?id=' . $id . '&err=' : '?err=') . rawurlencode('类型名称不能为空'));
    }
    if ($id > 0) {
        $pdo->prepare('UPDATE contract_types SET name=?,remark=? WHERE id=?')->execute([$name, $remark, $id]);
    } else {
        $pdo->prepare('INSERT INTO contract_types (name,remark) VALUES (?,?)')->execute([$name, $remark]);
    }
    redirect('settings.php?saved=1');
}

$pageTitle = $id > 0 ? '编辑类型' : '新增类型';
$activeNav = 'types';
ob_start();
?>
<div class="mf-panel"><div class="mf-panel__header"><?= e($pageTitle) ?></div><div class="mf-panel__body"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><div class="mf-row mf-row--tight"><div class="mf-col mf-col-12 mf-col-md-6"><label class="mf-label">名称</label><input class="mf-input" name="name" required value="<?= e((string) $row['name']) ?>"></div><div class="mf-col mf-col-12 mf-col-md-6"><label class="mf-label">备注</label><input class="mf-input" name="remark" value="<?= e((string) $row['remark']) ?>"></div></div><div class="mf-mt-3"><button class="mf-btn mf-btn--primary" type="submit">保存</button> <a class="mf-btn mf-btn--default" href="<?= e(url('settings.php')) ?>">返回列表</a></div></form></div></div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
