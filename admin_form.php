<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
redirect('dashboard.php?err=' . rawurlencode('管理员设置已下线'));
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
$row = ['id' => 0, 'username' => '', 'display_name' => ''];
if ($id > 0) {
    $st = $pdo->prepare('SELECT id,username,display_name,role FROM admins WHERE id=?');
    $st->execute([$id]);
    $f = $st->fetch();
    if (!$f || (string) $f['role'] === 'super') {
        redirect('system_settings.php?err=' . rawurlencode('该管理员不可编辑'));
    }
    $row = $f;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        redirect('system_settings.php?err=' . rawurlencode('会话已过期'));
    }
    $id = (int) ($_POST['id'] ?? 0);
    $username = trim((string) ($_POST['username'] ?? ''));
    $display = trim((string) ($_POST['display_name'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    if ($username === '' || $display === '') {
        redirect('admin_form.php' . ($id > 0 ? '?id=' . $id . '&err=' : '?err=') . rawurlencode('账号和名称不能为空'));
    }
    if ($id > 0) {
        if ($password !== '') {
            $pdo->prepare('UPDATE admins SET username=?,display_name=?,password_hash=?,role="normal",status="normal" WHERE id=?')->execute([$username, $display, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            $pdo->prepare('UPDATE admins SET username=?,display_name=?,role="normal",status="normal" WHERE id=?')->execute([$username, $display, $id]);
        }
    } else {
        if ($password === '') {
            redirect('admin_form.php?err=' . rawurlencode('新增管理员必须设置密码'));
        }
        if (db_column_exists($pdo, 'admins', 'name')) {
            $pdo->prepare('INSERT INTO admins (username,display_name,password_hash,role,status,name) VALUES (?,?,?,?,?,?)')
                ->execute([$username, $display, password_hash($password, PASSWORD_DEFAULT), 'normal', 'normal', $display]);
        } else {
            $pdo->prepare('INSERT INTO admins (username,display_name,password_hash,role,status) VALUES (?,?,?,?,?)')
                ->execute([$username, $display, password_hash($password, PASSWORD_DEFAULT), 'normal', 'normal']);
        }
    }
    redirect('system_settings.php?saved=1');
}

$pageTitle = $id > 0 ? '编辑管理员' : '新增管理员';
$activeNav = 'system';
ob_start();
?>
<div class="mf-panel"><div class="mf-panel__header"><?= e($pageTitle) ?></div><div class="mf-panel__body"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><div class="mf-row mf-row--tight"><div class="mf-col mf-col-12 mf-col-md-4"><label class="mf-label">登录账号</label><input class="mf-input" name="username" required value="<?= e((string) $row['username']) ?>"></div><div class="mf-col mf-col-12 mf-col-md-4"><label class="mf-label">显示名称</label><input class="mf-input" name="display_name" required value="<?= e((string) $row['display_name']) ?>"></div><div class="mf-col mf-col-12 mf-col-md-4"><label class="mf-label">密码<?= $id > 0 ? '（留空不改）' : '' ?></label><input class="mf-input" type="password" name="password"></div></div><div class="mf-mt-3"><button class="mf-btn mf-btn--primary" type="submit">保存</button> <a class="mf-btn mf-btn--default" href="<?= e(url('system_settings.php')) ?>">返回列表</a></div></form></div></div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
