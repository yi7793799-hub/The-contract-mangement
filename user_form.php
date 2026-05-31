<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_permission('users.edit');
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
$row = ['id' => 0, 'username' => '', 'display_name' => '', 'phone' => '', 'status' => 'normal', 'permissions' => []];
if ($id > 0) {
    $st = $pdo->prepare('SELECT id,username,display_name,phone,status,role,permissions FROM admins WHERE id=?');
    $st->execute([$id]);
    $f = $st->fetch();
    if (!$f || (string) $f['role'] !== 'sales') {
        redirect('users.php?err=' . rawurlencode('该业务员不存在或不可编辑'));
    }
    $row = $f;
    $perms = json_decode((string) ($f['permissions'] ?? ''), true);
    $row['permissions'] = is_array($perms) ? $perms : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        redirect('users.php?err=' . rawurlencode('会话已过期'));
    }
    $id = (int) ($_POST['id'] ?? 0);
    $username = trim((string) ($_POST['username'] ?? ''));
    $display = trim((string) ($_POST['display_name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $status = (string) ($_POST['status'] ?? 'normal');
    $password = (string) ($_POST['password'] ?? '');
    $groupList = array_values(array_filter(array_map('strval', (array) ($_POST['permission_groups'] ?? []))));
    $validGroups = array_keys(mf_permission_groups());
    $groupList = array_values(array_intersect($groupList, $validGroups));
    $permList = mf_permissions_from_groups($groupList);
    if ($username === '' || $display === '' || $phone === '') {
        redirect('user_form.php' . ($id > 0 ? '?id=' . $id . '&err=' : '?err=') . rawurlencode('姓名、手机号、登录账号不能为空'));
    }
    if (!in_array($status, ['normal', 'disabled'], true)) {
        $status = 'normal';
    }
    $permJson = json_encode($permList, JSON_UNESCAPED_UNICODE);
    if ($id > 0) {
        if ($password !== '') {
            $pdo->prepare('UPDATE admins SET username=?,display_name=?,phone=?,status=?,permissions=?,password_hash=?,role="sales" WHERE id=?')
                ->execute([$username, $display, $phone, $status, $permJson, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            $pdo->prepare('UPDATE admins SET username=?,display_name=?,phone=?,status=?,permissions=?,role="sales" WHERE id=?')
                ->execute([$username, $display, $phone, $status, $permJson, $id]);
        }
    } else {
        if ($password === '') {
            redirect('user_form.php?err=' . rawurlencode('新增用户必须设置密码'));
        }
        if (db_column_exists($pdo, 'admins', 'name')) {
            $pdo->prepare('INSERT INTO admins (username,display_name,phone,password_hash,role,status,permissions,name) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$username, $display, $phone, password_hash($password, PASSWORD_DEFAULT), 'sales', $status, $permJson, $display]);
        } else {
            $pdo->prepare('INSERT INTO admins (username,display_name,phone,password_hash,role,status,permissions) VALUES (?,?,?,?,?,?,?)')
                ->execute([$username, $display, $phone, password_hash($password, PASSWORD_DEFAULT), 'sales', $status, $permJson]);
        }
    }
    redirect('users.php?saved=1');
}

$groupMap = mf_permission_groups();
$pickedPermissions = array_map('strval', (array) ($row['permissions'] ?? []));
$pickedGroupKeys = [];
foreach ($groupMap as $groupKey => $groupDef) {
    $required = array_map('strval', (array) ($groupDef['permissions'] ?? []));
    if ($required && count(array_intersect($required, $pickedPermissions)) > 0) {
        $pickedGroupKeys[] = (string) $groupKey;
    }
}
$pageTitle = $id > 0 ? '编辑业务员' : '新增业务员';
$activeNav = 'users';
ob_start();
?>
<div class="mf-panel">
  <div class="mf-panel__header"><?= e($pageTitle) ?></div>
  <div class="mf-panel__body">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">

      <div class="mf-row mf-row--tight">
        <div class="mf-col mf-col-12 mf-col-md-4">
          <label class="mf-label">姓名</label>
          <input class="mf-input" name="display_name" required value="<?= e((string) ($row['display_name'] ?? '')) ?>">
        </div>
        <div class="mf-col mf-col-12 mf-col-md-4">
          <label class="mf-label">手机号</label>
          <input class="mf-input" name="phone" required value="<?= e((string) ($row['phone'] ?? '')) ?>">
        </div>
        <div class="mf-col mf-col-12 mf-col-md-4">
          <label class="mf-label">登录账号</label>
          <input class="mf-input" name="username" required value="<?= e((string) ($row['username'] ?? '')) ?>">
        </div>
      </div>

      <div class="mf-row mf-row--tight">
        <div class="mf-col mf-col-12 mf-col-md-4">
          <label class="mf-label">登录密码<?= $id > 0 ? '（留空不改）' : '' ?></label>
          <input class="mf-input" type="password" name="password">
        </div>
        <div class="mf-col mf-col-12 mf-col-md-4">
          <label class="mf-label">状态</label>
          <select class="mf-select" name="status">
            <option value="normal"<?= (string) ($row['status'] ?? 'normal') === 'normal' ? ' selected' : '' ?>>正常</option>
            <option value="disabled"<?= (string) ($row['status'] ?? 'normal') === 'disabled' ? ' selected' : '' ?>>禁用</option>
          </select>
        </div>
      </div>

      <div class="mf-form-item">
        <label class="mf-label">模块权限（简化版）</label>
        <div class="mf-row mf-row--tight">
          <?php foreach ($groupMap as $gk => $gv): ?>
            <div class="mf-col mf-col-12 mf-col-md-6">
              <label class="mf-small">
                <input type="checkbox" name="permission_groups[]" value="<?= e((string) $gk) ?>"<?= in_array((string) $gk, $pickedGroupKeys, true) ? ' checked' : '' ?>>
                <?= e((string) ($gv['label'] ?? $gk)) ?>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="mf-small mf-text-muted mf-mt-1">已改为模块权限分配，不再配置细粒度动作权限。</div>
      </div>

      <div class="mf-mt-3">
        <button class="mf-btn mf-btn--primary" type="submit">保存</button>
        <a class="mf-btn mf-btn--default" href="<?= e(url('users.php')) ?>">返回列表</a>
      </div>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
