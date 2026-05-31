<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('app_settings.view');

$pdo = db();
mf_ensure_app_settings_table($pdo);
$hasOwnContractOnly = db_column_exists($pdo, 'app_settings', 'own_contract_only');

$st = $pdo->query($hasOwnContractOnly
    ? 'SELECT site_name, logo_path, own_contract_only FROM app_settings WHERE id = 1 LIMIT 1'
    : 'SELECT site_name, logo_path FROM app_settings WHERE id = 1 LIMIT 1');
$row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
if (!$row) {
    if ($hasOwnContractOnly) {
        $pdo->exec("INSERT IGNORE INTO app_settings (id, site_name, logo_path, own_contract_only) VALUES (1, '', NULL, 0)");
    } else {
        $pdo->exec("INSERT IGNORE INTO app_settings (id, site_name, logo_path) VALUES (1, '', NULL)");
    }
    $row = ['site_name' => '', 'logo_path' => null, 'own_contract_only' => 0];
}

$cfg = app_config();
$defaultName = (string) ($cfg['app']['name'] ?? '合同管理系统');
$error = '';
$ok = '';
$uploadDir = __DIR__ . '/uploads/branding';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('app_settings.edit');
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $error = '会话已过期';
    } else {
        $siteName = trim((string) ($_POST['site_name'] ?? ''));
        $ownContractOnly = (string) ($_POST['own_contract_only'] ?? '') === '1' ? 1 : 0;
        $logoPath = $row['logo_path'] ?? null;
        $logoPath = is_string($logoPath) && $logoPath !== '' ? $logoPath : null;

        if (!empty($_POST['remove_logo'])) {
            if ($logoPath !== null) {
                $old = __DIR__ . '/' . $logoPath;
                if (is_file($old)) {
                    @unlink($old);
                }
            }
            $logoPath = null;
        }

        if ($error === '' && isset($_FILES['logo']) && is_array($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $tmp = (string) $_FILES['logo']['tmp_name'];
            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            if (!isset($allowed[$mime])) {
                $error = 'Logo 仅支持 JPG/PNG/GIF/WebP';
            } else {
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $fn = 'logo_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
                $dest = $uploadDir . '/' . $fn;
                if (!move_uploaded_file($tmp, $dest)) {
                    $error = 'Logo 保存失败';
                } else {
                    if ($logoPath !== null) {
                        $old = __DIR__ . '/' . $logoPath;
                        if (is_file($old)) {
                            @unlink($old);
                        }
                    }
                    $logoPath = 'uploads/branding/' . $fn;
                }
            }
        }

        if ($error === '') {
            if ($hasOwnContractOnly) {
                $pdo->prepare('UPDATE app_settings SET site_name = ?, logo_path = ?, own_contract_only = ? WHERE id = 1')->execute([$siteName, $logoPath, $ownContractOnly]);
            } else {
                $pdo->prepare('UPDATE app_settings SET site_name = ?, logo_path = ? WHERE id = 1')->execute([$siteName, $logoPath]);
            }
            $row['site_name'] = $siteName;
            $row['logo_path'] = $logoPath;
            $row['own_contract_only'] = $ownContractOnly;
            $ok = '已保存';
        }
    }
}

$pageTitle = '系统设置';
$activeNav = 'app_settings';
ob_start();
?>
<div class="mf-panel">
  <div class="mf-panel__header">系统外观设置</div>
  <div class="mf-panel__body">
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="mf-form-item">
        <label class="mf-label">系统名称</label>
        <input type="text" name="site_name" class="mf-input" maxlength="200" value="<?= e((string) ($row['site_name'] ?? '')) ?>" placeholder="<?= e($defaultName) ?>">
      </div>
      <div class="mf-form-item">
        <label class="mf-label">系统 Logo</label>
        <?php $lp = (string) ($row['logo_path'] ?? ''); ?>
        <?php if ($lp !== ''): ?>
          <div class="mf-system-logo-preview mf-mb-2"><img src="<?= e(url($lp)) ?>" alt="当前 Logo"></div>
        <?php endif; ?>
        <input type="file" name="logo" class="mf-input" accept="image/jpeg,image/png,image/gif,image/webp">
        <?php if ($lp !== ''): ?>
          <label class="mf-flex mf-items-center mf-gap-2 mf-mt-2">
            <input type="checkbox" name="remove_logo" value="1">
            <span class="mf-small">移除 Logo</span>
          </label>
        <?php endif; ?>
      </div>
      <div class="mf-form-item">
        <label class="mf-label">业务设置</label>
        <label class="mf-flex mf-items-center mf-gap-2">
          <input type="checkbox" name="own_contract_only" value="1"<?= ((int) ($row['own_contract_only'] ?? 0) === 1) ? ' checked' : '' ?>>
          <span class="mf-small">用户仅可操作自己登记的合同</span>
        </label>
      </div>
      <button type="submit" class="mf-btn mf-btn--primary">保存</button>
    </form>
  </div>
</div>
<?php
$content = ob_get_clean();
$mfBootToast = null;
if ($error !== '') {
    $mfBootToast = ['type' => 'error', 'msg' => $error];
} elseif ($ok !== '') {
    $mfBootToast = ['type' => 'success', 'msg' => $ok];
}
require __DIR__ . '/includes/layout.php';
