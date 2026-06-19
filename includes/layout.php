<?php
declare(strict_types=1);
/** @var string $pageTitle */
/** @var string $activeNav */
/** @var string $content */
/** @var string $contentClass 附加在 .mf-content 上的类（可选） */
$contentClass = $contentClass ?? '';
/** @var array|null $mfBootToast 页面加载后弹出一次 {type,msg,title?}，需在 mf-ui.js 之后执行 */
$mfBootToast = $mfBootToast ?? null;
$cfg = app_config();
$mfBrand = mf_site_branding(db());
$appName = $mfBrand['name'];
$admin = current_admin();
$mfTabActive = $activeNav ?? '';
$mfAdminAccountUrl = url('api/admin_account.php');
$mfAdminAccountCsrf = csrf_token();
$biz = (string) ($_GET['biz'] ?? '');
if (!in_array($biz, ['receipt', 'payment'], true)) {
    $biz = '';
}
$receiptOpen = in_array($activeNav, ['orders', 'finance', 'finance_records', 'finance_invoices'], true) && $biz === 'receipt';
$paymentOpen = in_array($activeNav, ['orders', 'finance', 'finance_records', 'finance_invoices'], true) && $biz === 'payment';
$receiptActive = in_array($activeNav, ['orders', 'finance', 'finance_records', 'finance_invoices'], true) && $biz === 'receipt';
$paymentActive = in_array($activeNav, ['orders', 'finance', 'finance_records', 'finance_invoices'], true) && $biz === 'payment';
$remindDays = 15;
$expiringList = [];
try {
    $st = db()->query('SELECT remind_days FROM contract_settings WHERE id = 1 LIMIT 1');
    $remindDays = max(1, (int) (($st ? $st->fetchColumn() : 15) ?: 15));
    $st = db()->prepare(
        "SELECT contract_no, contract_name, customer_name, expiry_date
         FROM contracts
         WHERE expiry_date IS NOT NULL
           AND status IN ('ongoing','expiring')
           AND DATEDIFF(expiry_date, CURDATE()) BETWEEN 0 AND ?
         ORDER BY expiry_date ASC
         LIMIT 5"
    );
    $st->execute([$remindDays]);
    $expiringList = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $expiringList = [];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> — <?= e($appName) ?></title>
    <link href="<?= e(asset_url('vendor/bootstrap-icons/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset_url('vendor/fontawesome-free/css/all.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset_url('css/mf-ui.css')) ?>" rel="stylesheet">
    <link href="<?= e(asset_url('css/app.css')) ?>" rel="stylesheet">
    <script>window.MF_BASE = <?= json_encode(base_path(), JSON_UNESCAPED_UNICODE) ?>;</script>
</head>
<body class="mf-app">
<header class="mf-topbar">
    <div class="mf-topbar-inner mf-flex mf-items-center mf-justify-between mf-p-2">
        <div class="mf-flex mf-items-center mf-gap-2">
            <span class="mf-panda-logo" aria-hidden="true">
                <svg viewBox="0 0 100 100" width="42" height="42">
                    <!-- 耳朵 -->
                    <ellipse cx="22" cy="18" rx="12" ry="14" fill="#2d2d2d">
                        <animate attributeName="ry" values="14;12;14" dur="0.4s" repeatCount="indefinite"/>
                    </ellipse>
                    <ellipse cx="78" cy="18" rx="12" ry="14" fill="#2d2d2d">
                        <animate attributeName="ry" values="12;14;12" dur="0.4s" repeatCount="indefinite"/>
                    </ellipse>

                    <!-- 头部 -->
                    <ellipse cx="50" cy="42" rx="35" ry="32" fill="#fff">
                        <animate attributeName="ry" values="32;30;32" dur="0.8s" repeatCount="indefinite"/>
                    </ellipse>

                    <!-- 眼圈 -->
                    <ellipse cx="35" cy="40" rx="12" ry="14" fill="#2d2d2d"/>
                    <ellipse cx="65" cy="40" rx="12" ry="14" fill="#2d2d2d"/>

                    <!-- 眼睛 - 眨眼动画 -->
                    <ellipse cx="35" cy="40" rx="6" ry="7" fill="#fff">
                        <animate attributeName="ry" values="7;1;7" dur="2.5s" repeatCount="indefinite"/>
                    </ellipse>
                    <ellipse cx="65" cy="40" rx="6" ry="7" fill="#fff">
                        <animate attributeName="ry" values="7;1;7" dur="2.5s" repeatCount="indefinite"/>
                    </ellipse>

                    <!-- 眼珠 - 左右看 -->
                    <circle cx="37" cy="41" r="3" fill="#1a1a1a">
                        <animate attributeName="cx" values="37;33;37" dur="1.5s" repeatCount="indefinite"/>
                    </circle>
                    <circle cx="67" cy="41" r="3" fill="#1a1a1a">
                        <animate attributeName="cx" values="67;63;67" dur="1.5s" repeatCount="indefinite"/>
                    </circle>

                    <!-- 鼻子 -->
                    <ellipse cx="50" cy="52" rx="5" ry="4" fill="#2d2d2d">
                        <animate attributeName="ry" values="4;3;4" dur="0.6s" repeatCount="indefinite"/>
                    </ellipse>

                    <!-- 嘴巴 - 微笑动画 -->
                    <path d="M 42 58 Q 50 66 58 58" stroke="#2d2d2d" stroke-width="2.5" fill="none" stroke-linecap="round">
                        <animate attributeName="d" values="M 42 58 Q 50 66 58 58;M 42 56 Q 50 68 58 56;M 42 58 Q 50 66 58 58" dur="0.8s" repeatCount="indefinite"/>
                    </path>

                    <!-- 腮红 -->
                    <circle cx="22" cy="50" r="6" fill="#ffb3ba" opacity="0.7">
                        <animate attributeName="r" values="6;7;6" dur="0.8s" repeatCount="indefinite"/>
                    </circle>
                    <circle cx="78" cy="50" r="6" fill="#ffb3ba" opacity="0.7">
                        <animate attributeName="r" values="6;7;6" dur="0.8s" repeatCount="indefinite"/>
                    </circle>

                    <!-- 身体 -->
                    <ellipse cx="50" cy="78" rx="22" ry="18" fill="#fff">
                        <animate attributeName="cy" values="78;76;78" dur="0.4s" repeatCount="indefinite"/>
                    </ellipse>

                    <!-- 左手 - 挥手动画 -->
                    <ellipse cx="25" cy="72" rx="10" ry="14" fill="#2d2d2d">
                        <animate attributeName="cy" values="72;62;72" dur="0.4s" repeatCount="indefinite"/>
                        <animate attributeName="cx" values="25;20;25" dur="0.4s" repeatCount="indefinite"/>
                        <animateTransform attributeName="transform" type="rotate" values="-10 25 72;20 25 72;-10 25 72" dur="0.4s" repeatCount="indefinite"/>
                    </ellipse>

                    <!-- 右手 - 挥手动画 -->
                    <ellipse cx="75" cy="72" rx="10" ry="14" fill="#2d2d2d">
                        <animate attributeName="cy" values="62;72;62" dur="0.4s" repeatCount="indefinite"/>
                        <animate attributeName="cx" values="80;75;80" dur="0.4s" repeatCount="indefinite"/>
                        <animateTransform attributeName="transform" type="rotate" values="20 75 72;-10 75 72;20 75 72" dur="0.4s" repeatCount="indefinite"/>
                    </ellipse>

                    <!-- 左脚 -->
                    <ellipse cx="38" cy="94" rx="10" ry="6" fill="#2d2d2d">
                        <animate attributeName="cx" values="38;35;38" dur="0.4s" repeatCount="indefinite"/>
                    </ellipse>

                    <!-- 右脚 -->
                    <ellipse cx="62" cy="94" rx="10" ry="6" fill="#2d2d2d">
                        <animate attributeName="cx" values="62;65;62" dur="0.4s" repeatCount="indefinite"/>
                    </ellipse>

                    <!-- 头顶小花 -->
                    <circle cx="50" cy="8" r="4" fill="#ff6b9d">
                        <animate attributeName="r" values="4;5;4" dur="0.6s" repeatCount="indefinite"/>
                    </circle>
                    <circle cx="46" cy="6" r="3" fill="#ffb3d1">
                        <animate attributeName="r" values="3;4;3" dur="0.6s" repeatCount="indefinite" begin="0.1s"/>
                    </circle>
                    <circle cx="54" cy="6" r="3" fill="#ffb3d1">
                        <animate attributeName="r" values="3;4;3" dur="0.6s" repeatCount="indefinite" begin="0.1s"/>
                    </circle>
                </svg>
            </span>
            <span class="mf-brand-title" style="color:#fff;font-weight:600;"><?= e($appName) ?></span>
        </div>
        <div class="mf-flex mf-items-center mf-gap-1 mf-topbar-actions">
            <div class="mf-dropdown">
                <a href="#" class="mf-dropdown__trigger mf-topbar-item mf-flex mf-flex-wrap mf-items-center" style="color:#fff;flex-direction:column;" data-mf-dropdown-toggle>
                    <i class="bi bi-person-circle" style="font-size:1.5rem;"></i>
                    <span class="mf-topbar-label">用户</span>
                </a>
                <div class="mf-dropdown__menu mf-dropdown__menu--right">
                    <a class="mf-dropdown__item" href="<?= e(url('app_settings.php')) ?>"><i class="bi bi-sliders"></i> 系统设置</a>
                    <a class="mf-dropdown__item" href="#" id="mfOpenAccountModal"><i class="bi bi-key"></i> 账户与密码</a>
                    <div class="mf-dropdown__divider"></div>
                    <a class="mf-dropdown__item" href="<?= e(url('logout.php')) ?>" style="color:#f56c6c;"><i class="bi bi-box-arrow-right"></i> 退出登录</a>
                </div>
            </div>
        </div>
    </div>
</header>

<div class="mf-shell mf-flex">
    <aside class="mf-sidebar">
        <nav class="mf-sidebar-nav mf-flex-grow">
            <?php if (admin_can('dashboard.view')): ?><a class="mf-nav-link <?= $activeNav === 'dashboard' ? 'active' : '' ?>" href="<?= e(url('dashboard.php')) ?>"><i class="fa-solid fa-house mf-nav-fa mf-nav-fa--dashboard" aria-hidden="true"></i><span>首页</span></a><?php endif; ?>
            <?php if (admin_can('search.view')): ?><a class="mf-nav-link <?= $activeNav === 'quick_search' ? 'active' : '' ?>" href="<?= e(url('quick_search.php')) ?>"><i class="fa-solid fa-magnifying-glass mf-nav-fa mf-nav-fa--dashboard" aria-hidden="true"></i><span>快速搜索</span></a><?php endif; ?>
            <?php if (admin_can('contracts.view') || admin_can('receipt.progress.view') || admin_can('receipt.records.view')): ?><a class="mf-nav-link <?= $receiptActive ? 'active' : '' ?>" href="#" data-receipt-toggle>
                <i class="fa-solid fa-hand-holding-dollar mf-nav-fa mf-nav-fa--orders" aria-hidden="true"></i>
                <span>收款业务</span>
                <i class="fa-solid fa-chevron-down" style="margin-left:auto;font-size:12px;opacity:.75;transform:<?= $receiptOpen ? 'rotate(180deg)' : 'rotate(0deg)' ?>;" id="receiptChevron"></i>
            </a>
            <div id="receiptSubmenu" style="display:<?= $receiptOpen ? 'block' : 'none' ?>;">
                <?php if (admin_can('contracts.view')): ?><a class="mf-nav-link <?= $receiptActive && $activeNav === 'orders' ? 'active' : '' ?>" href="<?= e(url('orders.php?biz=receipt')) ?>" style="padding-left:2.2rem;font-size:13px;"><i class="fa-solid fa-file-invoice mf-nav-fa" aria-hidden="true"></i><span>项目列表</span></a><?php endif; ?>
                <?php if (admin_can('receipt.progress.view')): ?><a class="mf-nav-link <?= $receiptActive && $activeNav === 'finance' ? 'active' : '' ?>" href="<?= e(url('finance_progress.php?tab=receipt&biz=receipt&menu=entry')) ?>" style="padding-left:2.2rem;font-size:13px;"><i class="fa-solid fa-sack-dollar mf-nav-fa" aria-hidden="true"></i><span>登记收款</span></a><?php endif; ?>
                <?php if (admin_can('receipt.records.view')): ?><a class="mf-nav-link <?= $receiptActive && $activeNav === 'finance_records' ? 'active' : '' ?>" href="<?= e(url('finance_records.php?tab=receipt&biz=receipt')) ?>" style="padding-left:2.2rem;font-size:13px;"><i class="fa-solid fa-receipt mf-nav-fa" aria-hidden="true"></i><span>收款记录</span></a><?php endif; ?>
                <?php if (admin_can('receipt.invoices.view')): ?><a class="mf-nav-link <?= $receiptActive && $activeNav === 'finance_invoices' ? 'active' : '' ?>" href="<?= e(url('finance_invoices.php?tab=receipt&biz=receipt')) ?>" style="padding-left:2.2rem;font-size:13px;"><i class="fa-solid fa-file-invoice-dollar mf-nav-fa" aria-hidden="true"></i><span>开票明细</span></a><?php endif; ?>
            </div>
            <?php endif; if (admin_can('contracts.view') || admin_can('payment.progress.view') || admin_can('payment.records.view')): ?><a class="mf-nav-link <?= $paymentActive ? 'active' : '' ?>" href="#" data-payment-toggle>
                <i class="fa-solid fa-credit-card mf-nav-fa mf-nav-fa--orders" aria-hidden="true"></i>
                <span>付款业务</span>
                <i class="fa-solid fa-chevron-down" style="margin-left:auto;font-size:12px;opacity:.75;transform:<?= $paymentOpen ? 'rotate(180deg)' : 'rotate(0deg)' ?>;" id="paymentChevron"></i>
            </a>
            <div id="paymentSubmenu" style="display:<?= $paymentOpen ? 'block' : 'none' ?>;">
                <?php if (admin_can('contracts.view')): ?><a class="mf-nav-link <?= $paymentActive && $activeNav === 'orders' ? 'active' : '' ?>" href="<?= e(url('orders.php?biz=payment')) ?>" style="padding-left:2.2rem;font-size:13px;"><i class="fa-solid fa-file-invoice mf-nav-fa" aria-hidden="true"></i><span>项目列表</span></a><?php endif; ?>
                <?php if (admin_can('payment.progress.view')): ?><a class="mf-nav-link <?= $paymentActive && $activeNav === 'finance' ? 'active' : '' ?>" href="<?= e(url('finance_progress.php?tab=payment&biz=payment&menu=entry')) ?>" style="padding-left:2.2rem;font-size:13px;"><i class="fa-solid fa-money-check-dollar mf-nav-fa" aria-hidden="true"></i><span>登记付款</span></a><?php endif; ?>
                <?php if (admin_can('payment.records.view')): ?><a class="mf-nav-link <?= $paymentActive && $activeNav === 'finance_records' ? 'active' : '' ?>" href="<?= e(url('finance_records.php?tab=payment&biz=payment')) ?>" style="padding-left:2.2rem;font-size:13px;"><i class="fa-solid fa-file-circle-check mf-nav-fa" aria-hidden="true"></i><span>付款记录</span></a><?php endif; ?>
                <?php if (admin_can('payment.invoices.view')): ?><a class="mf-nav-link <?= $paymentActive && $activeNav === 'finance_invoices' ? 'active' : '' ?>" href="<?= e(url('finance_invoices.php?tab=payment&biz=payment')) ?>" style="padding-left:2.2rem;font-size:13px;"><i class="fa-solid fa-file-invoice-dollar mf-nav-fa" aria-hidden="true"></i><span>开票明细</span></a><?php endif; ?>
            </div>
            <?php endif; if (admin_can('archived.view')): ?><a class="mf-nav-link <?= $activeNav === 'archived_contracts' ? 'active' : '' ?>" href="<?= e(url('archived_contracts.php')) ?>"><i class="fa-solid fa-box-archive mf-nav-fa mf-nav-fa--orders" aria-hidden="true"></i><span>归档合同</span></a><?php endif; ?>
            <?php if (admin_can('import.view')): ?><a class="mf-nav-link <?= $activeNav === 'import' ? 'active' : '' ?>" href="<?= e(url('import.php')) ?>"><i class="fa-solid fa-file-import mf-nav-fa mf-nav-fa--orders" aria-hidden="true"></i><span>批量导入</span></a><?php endif; ?>
            <?php if (admin_can('types.view')): ?><a class="mf-nav-link <?= $activeNav === 'types' ? 'active' : '' ?>" href="<?= e(url('settings.php')) ?>"><i class="fa-solid fa-layer-group mf-nav-fa mf-nav-fa--members" aria-hidden="true"></i><span>类型管理</span></a><?php endif; ?>
            <?php if (admin_can('remind.view')): ?><a class="mf-nav-link <?= $activeNav === 'report' ? 'active' : '' ?>" href="<?= e(url('report.php')) ?>"><i class="fa-solid fa-bell mf-nav-fa mf-nav-fa--report" aria-hidden="true"></i><span>到期提醒</span></a><?php endif; ?>
            <?php if (admin_can('app_settings.view')): ?><a class="mf-nav-link <?= $activeNav === 'app_settings' ? 'active' : '' ?>" href="<?= e(url('app_settings.php')) ?>"><i class="fa-solid fa-gear mf-nav-fa mf-nav-fa--system" aria-hidden="true"></i><span>系统设置</span></a><?php endif; ?>
        </nav>
    </aside>
    <main class="mf-main mf-flex-grow">
        <div class="mf-content mf-flex-grow <?= e($contentClass) ?>">
            <?= $content ?>
        </div>
    </main>
</div>

<div class="mf-modal" id="accountModal" aria-hidden="true">
    <div class="mf-modal__mask" data-mf-modal-close></div>
    <div class="mf-modal__wrap">
        <div class="mf-modal__box mf-modal__box--lg">
            <div class="mf-modal__header">
                <h2 class="mf-modal__title">账户与密码</h2>
                <button type="button" class="mf-modal__close" data-mf-modal-close aria-label="关闭">&times;</button>
            </div>
            <div class="mf-modal__body">
                <form id="mfAccountForm" autocomplete="off">
                    <div class="mf-form-item">
                        <label class="mf-label">登录账号</label>
                        <input type="text" class="mf-input" id="mfAcctUsername" disabled value="<?= e((string) ($admin['username'] ?? '')) ?>">
                    </div>
                    <div class="mf-form-item">
                        <label class="mf-label">显示名称 <span class="mf-text-danger">*</span></label>
                        <input type="text" class="mf-input" id="mfAcctDisplayName" required value="<?= e((string) ($admin['display_name'] ?? '')) ?>">
                    </div>
                    <div class="mf-form-item">
                        <label class="mf-label">邮箱</label>
                        <input type="email" class="mf-input" id="mfAcctEmail" value="<?= e((string) ($admin['email'] ?? '')) ?>">
                    </div>
                    <p class="mf-small mf-text-muted mf-mb-2">修改密码（留空则不修改）</p>
                    <div class="mf-form-item">
                        <label class="mf-label">新密码</label>
                        <input type="password" class="mf-input" id="mfAcctPass1" autocomplete="new-password">
                    </div>
                    <div class="mf-form-item">
                        <label class="mf-label">确认新密码</label>
                        <input type="password" class="mf-input" id="mfAcctPass2" autocomplete="new-password">
                    </div>
                </form>
            </div>
            <div class="mf-modal__footer mf-flex mf-gap-2 mf-justify-end">
                <button type="button" class="mf-btn mf-btn--default" data-mf-modal-close>取消</button>
                <button type="button" class="mf-btn mf-btn--primary" id="mfAcctSave">保存</button>
            </div>
        </div>
    </div>
</div>

<script src="<?= e(asset_url('js/mf-ui.js')) ?>"></script>
<script src="<?= e(asset_url('js/app.js')) ?>"></script>
<?php if (!empty($mfBootToast) && is_array($mfBootToast) && !empty($mfBootToast['type']) && isset($mfBootToast['msg'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  if (!window.MFToast) return;
  var t = <?= json_encode(['type' => $mfBootToast['type'], 'msg' => (string) $mfBootToast['msg'], 'title' => (string) ($mfBootToast['title'] ?? '')], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var title = t.title || '提示';
  if (t.type === 'success') window.MFToast.success(t.msg, title);
  else if (t.type === 'warning') window.MFToast.warning(t.msg, title);
  else if (t.type === 'info') window.MFToast.info(t.msg, title);
  else window.MFToast.error(t.msg, title);
});
</script>
<?php endif; ?>
<script>
(function () {
  var API = <?= json_encode($mfAdminAccountUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  var CSRF = <?= json_encode($mfAdminAccountCsrf, JSON_UNESCAPED_UNICODE) ?>;
  function $(id) { return document.getElementById(id); }
  var openBtn = $('mfOpenAccountModal');
  if (openBtn) {
    openBtn.addEventListener('click', function (e) {
      e.preventDefault();
      var dd = document.querySelector('.mf-topbar-actions .mf-dropdown');
      if (dd) dd.classList.remove('is-open');
      if (window.MFModal) window.MFModal.show('accountModal');
    });
  }
  var saveBtn = $('mfAcctSave');
  if (saveBtn) {
    saveBtn.addEventListener('click', function () {
      var dn = ($('mfAcctDisplayName') && $('mfAcctDisplayName').value) ? $('mfAcctDisplayName').value.trim() : '';
      var em = ($('mfAcctEmail') && $('mfAcctEmail').value) ? $('mfAcctEmail').value.trim() : '';
      var p1 = $('mfAcctPass1') ? $('mfAcctPass1').value : '';
      var p2 = $('mfAcctPass2') ? $('mfAcctPass2').value : '';
      if (!dn) {
        if (window.MFToast) window.MFToast.warning('请填写显示名称', '提示');
        return;
      }
      saveBtn.disabled = true;
      fetch(API, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          csrf: CSRF,
          display_name: dn,
          email: em,
          password: p1,
          password2: p2
        })
      }).then(function (r) { return r.json(); }).then(function (d) {
        saveBtn.disabled = false;
        if (d.ok) {
          if ($('mfAcctPass1')) $('mfAcctPass1').value = '';
          if ($('mfAcctPass2')) $('mfAcctPass2').value = '';
          var lab = document.querySelector('.mf-topbar-actions .mf-topbar-label');
          if (lab && d.display_name) lab.textContent = d.display_name;
          if (window.MFModal) window.MFModal.hide('accountModal');
          if (window.MFToast) window.MFToast.success('已保存', '提示');
        } else {
          if (window.MFToast) window.MFToast.error(d.error || '保存失败', '提示');
        }
      }).catch(function () {
        saveBtn.disabled = false;
        if (window.MFToast) window.MFToast.error('网络错误', '提示');
      });
    });
  }
  var receiptToggle = document.querySelector('[data-receipt-toggle]');
  var receiptSub = document.getElementById('receiptSubmenu');
  var receiptChev = document.getElementById('receiptChevron');
  if (receiptToggle && receiptSub && receiptChev) {
    receiptToggle.addEventListener('click', function (e) {
      e.preventDefault();
      var open = receiptSub.style.display !== 'none';
      receiptSub.style.display = open ? 'none' : 'block';
      receiptChev.style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
    });
  }
  var paymentToggle = document.querySelector('[data-payment-toggle]');
  var paymentSub = document.getElementById('paymentSubmenu');
  var paymentChev = document.getElementById('paymentChevron');
  if (paymentToggle && paymentSub && paymentChev) {
    paymentToggle.addEventListener('click', function (e) {
      e.preventDefault();
      var open = paymentSub.style.display !== 'none';
      paymentSub.style.display = open ? 'none' : 'block';
      paymentChev.style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
    });
  }
})();
</script>
<?php if (!empty($expiringList)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var lines = <?= json_encode(array_map(static function ($x) {
      return sprintf('%s｜%s｜到期:%s', $x['contract_no'], $x['contract_name'], $x['expiry_date']);
  }, $expiringList), JSON_UNESCAPED_UNICODE) ?>;
  var box = document.createElement('div');
  box.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:9999;width:340px;max-width:calc(100vw - 24px);background:#fff;border:1px solid #dcdfe6;padding:10px 12px;box-shadow:none;';
  box.innerHTML = '<div style="font-weight:600;margin-bottom:6px;">到期提醒（' + <?= json_encode($remindDays) ?> + '天内）</div>'
    + '<div style="font-size:12px;color:#606266;line-height:1.55;max-height:180px;overflow:auto;">'
    + lines.map(function (x) { return '<div style="padding:3px 0;border-top:1px dashed #ebeef5;">' + x + '</div>'; }).join('')
    + '</div>';
  document.body.appendChild(box);
  setTimeout(function () { box.remove(); }, 12000);
});
</script>
<?php endif; ?>
</body>
</html>
