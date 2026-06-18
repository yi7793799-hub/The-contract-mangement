<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (current_admin()) {
    redirect('dashboard.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        $error = '会话已过期，请重试。';
    } else {
        $u = trim((string) ($_POST['username'] ?? ''));
        $p = (string) ($_POST['password'] ?? '');
        if ($u === '' || $p === '') {
            $error = '请输入账号和密码。';
        } elseif (!attempt_login(db(), $u, $p)) {
            $error = '账号或密码错误。';
        } else {
            redirect('dashboard.php');
        }
    }
}

$mfBrand = mf_site_branding(db());
$appName = $mfBrand['name'] !== '' ? $mfBrand['name'] : '管理后台';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>登录 — <?= e($appName) ?></title>
    <link href="<?= e(asset_url('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset_url('css/mf-ui.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset_url('css/app.css')) ?>" rel="stylesheet">
</head>
<body class="mf-login-page mf-login-page--cloud">
<div class="mf-login-cloud">
    <div class="mf-login-cloud__hero">
        <div class="mf-login-cloud__hero-bg" aria-hidden="true"></div>
        <!-- 漂浮光点粒子 -->
        <div class="mf-login-particles" aria-hidden="true">
            <div class="mf-login-particle mf-login-particle--1"></div>
            <div class="mf-login-particle mf-login-particle--2"></div>
            <div class="mf-login-particle mf-login-particle--3"></div>
            <div class="mf-login-particle mf-login-particle--4"></div>
            <div class="mf-login-particle mf-login-particle--5"></div>
            <div class="mf-login-particle mf-login-particle--6"></div>
        </div>
        <div class="mf-login-cloud__hero-inner">
            <section class="mf-login-cloud__pitch" aria-labelledby="mf-login-pitch-title">
                <h1 id="mf-login-pitch-title" class="mf-login-cloud__pitch-title">智链经营</h1>
            </section>

            <div class="mf-login-cloud__card">
                <h2 class="mf-login-cloud__card-title">登录</h2>
                <form method="post" autocomplete="off" class="mf-login-cloud__form" id="mf-login-form">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <div class="mf-login-cloud__field">
                        <div class="mf-login-cloud__input-wrap">
                            <i class="bi bi-person" aria-hidden="true"></i>
                            <input type="text" name="username" id="mf-login-user" class="mf-login-cloud__input" required autofocus placeholder="用户名" autocomplete="username">
                            <span class="mf-login-cloud__input-placeholder" aria-hidden="true"></span>
                        </div>
                    </div>
                    <div class="mf-login-cloud__field">
                        <div class="mf-login-cloud__input-wrap">
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            <input type="password" name="password" id="mf-login-pass" class="mf-login-cloud__input" required placeholder="密码" autocomplete="current-password">
                            <button type="button" class="mf-login-cloud__toggle-password" id="mf-toggle-password" aria-label="显示密码" title="显示密码">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mf-login-cloud__remember-row">
                        <label class="mf-login-cloud__remember">
                            <input type="checkbox" name="remember_local" id="mf-login-remember" value="1">
                            <span>记住密码</span>
                        </label>
                    </div>
                    <button type="submit" class="mf-login-cloud__submit">登录</button>
                </form>
            </div>
        </div>
    </div>

    <footer class="mf-login-cloud__footer">
        <p class="mf-login-cloud__slogan">运筹从容 · 经营有道</p>
        <p class="mf-login-cloud__copy">智链经营</p>
    </footer>
</div>
<script src="<?= e(asset_url('js/mf-ui.js')) ?>"></script>
<script>
(function () {
  var LS_KEY = 'mf_login_remember';
  var LS_USER = 'mf_login_user';
  var LS_PASS = 'mf_login_pass';
  document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('mf-login-form');
    var user = document.getElementById('mf-login-user');
    var pass = document.getElementById('mf-login-pass');
    var remember = document.getElementById('mf-login-remember');
    var toggleBtn = document.getElementById('mf-toggle-password');
    try {
      if (localStorage.getItem(LS_KEY) === '1') {
        remember.checked = true;
        var su = localStorage.getItem(LS_USER);
        var sp = localStorage.getItem(LS_PASS);
        if (su) user.value = su;
        if (sp) pass.value = sp;
      }
    } catch (e) {}
    if (form) {
      form.addEventListener('submit', function () {
        try {
          if (remember.checked) {
            localStorage.setItem(LS_KEY, '1');
            localStorage.setItem(LS_USER, user.value);
            localStorage.setItem(LS_PASS, pass.value);
          } else {
            localStorage.removeItem(LS_KEY);
            localStorage.removeItem(LS_USER);
            localStorage.removeItem(LS_PASS);
          }
        } catch (e) {}
      });
    }
    // 密码显示/隐藏切换
    if (toggleBtn && pass) {
      toggleBtn.addEventListener('click', function () {
        var icon = toggleBtn.querySelector('i');
        if (pass.type === 'password') {
          pass.type = 'text';
          icon.classList.remove('bi-eye');
          icon.classList.add('bi-eye-slash');
          toggleBtn.setAttribute('aria-label', '隐藏密码');
          toggleBtn.setAttribute('title', '隐藏密码');
        } else {
          pass.type = 'password';
          icon.classList.remove('bi-eye-slash');
          icon.classList.add('bi-eye');
          toggleBtn.setAttribute('aria-label', '显示密码');
          toggleBtn.setAttribute('title', '显示密码');
        }
      });
    }
  <?php if ($error !== ''): ?>
    if (window.MFToast) {
      window.MFToast.error(<?= json_encode($error, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>, '登录失败');
    }
  <?php endif; ?>
  });
})();
</script>
</body>
</html>
