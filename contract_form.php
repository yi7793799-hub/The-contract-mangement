<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
$pdo = db();
$admin = current_admin() ?? [];
$ownOnly = mf_own_contract_only_enabled($pdo) && (($admin['role'] ?? 'normal') !== 'super');
$currentAdminId = (int) ($admin['id'] ?? 0);
$biz = (string) ($_GET['biz'] ?? '');
if (!in_array($biz, ['receipt', 'payment'], true)) {
    $biz = '';
}

$id = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;
$row = [
    'contract_no' => '',
    'project_no' => '',
    'project_name' => '',
    'contract_name' => '',
    'customer_name' => '',
    'payment_type' => 'receipt',
    'signer_party' => '',
    'signer_name' => '',
    'phone' => '',
    'amount' => '0',
    'signed_date' => '',
    'effective_date' => '',
    'expiry_date' => '',
    'status' => 'ongoing',
    'type_id' => 0,
];

if ($isEdit) {
    require_permission('contracts.edit');
    $st = $pdo->prepare('SELECT * FROM contracts WHERE id=?');
    $st->execute([$id]);
    $f = $st->fetch();
    if (!$f) {
        redirect('orders.php?err=' . rawurlencode('合同不存在'));
    }
    if ($ownOnly && (int) ($f['created_by'] ?? 0) !== $currentAdminId) {
        redirect('orders.php?err=' . rawurlencode('仅可操作自己登记的合同'));
    }
    $row = $f;
} elseif ($biz !== '') {
    $row['payment_type'] = $biz;
}
if (!$isEdit) {
    require_permission('contracts.create');
}
$isCompleted = $isEdit && (string) ($row['status'] ?? '') === 'completed';
$hasTransactions = false;
if ($isEdit) {
    $txCountSt = $pdo->prepare('SELECT COUNT(*) FROM contract_transactions WHERE contract_id = ?');
    $txCountSt->execute([$id]);
    $hasTransactions = ((int) $txCountSt->fetchColumn()) > 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ((int) ($_POST['id'] ?? 0) > 0) {
        require_permission('contracts.edit');
    } else {
        require_permission('contracts.create');
    }
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        redirect('orders.php?err=' . rawurlencode('会话已过期'));
    }
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete_file') {
        $fid = (int) ($_POST['file_id'] ?? 0);
        $st = $pdo->prepare('SELECT file_path, contract_id FROM contract_files WHERE id=?');
        $st->execute([$fid]);
        $fr = $st->fetch();
        if ($fr) {
            if ($ownOnly) {
                $ownerSt = $pdo->prepare('SELECT created_by FROM contracts WHERE id=?');
                $ownerSt->execute([(int) $fr['contract_id']]);
                if ((int) $ownerSt->fetchColumn() !== $currentAdminId) {
                    redirect('orders.php?err=' . rawurlencode('仅可操作自己登记的合同'));
                }
            }
            $cst = $pdo->prepare('SELECT status FROM contracts WHERE id=?');
            $cst->execute([(int) $fr['contract_id']]);
            if ((string) ($cst->fetchColumn() ?: '') === 'completed') {
                redirect('orders.php?err=' . rawurlencode('已完成合同不允许编辑'));
            }
            $p = __DIR__ . '/' . ltrim((string) $fr['file_path'], '/');
            if (is_file($p)) {
                @unlink($p);
            }
            $pdo->prepare('DELETE FROM contract_files WHERE id=?')->execute([$fid]);
            redirect('contract_form.php?id=' . (int) $fr['contract_id'] . '&saved=1');
        }
    }
    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $st = $pdo->prepare('SELECT status, created_by FROM contracts WHERE id=?');
        $st->execute([$id]);
        $contractCheck = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($ownOnly && (int) ($contractCheck['created_by'] ?? 0) !== $currentAdminId) {
            redirect('orders.php?err=' . rawurlencode('仅可操作自己登记的合同'));
        }
        if ((string) ($contractCheck['status'] ?? '') === 'completed') {
            redirect('orders.php?err=' . rawurlencode('已完成合同不允许编辑'));
        }
    }
    $typeId = (int) ($_POST['type_id'] ?? 0);
    $vals = [
        trim((string) ($_POST['contract_no'] ?? '')),
        trim((string) ($_POST['project_no'] ?? '')),
        trim((string) ($_POST['project_name'] ?? '')),
        trim((string) ($_POST['contract_name'] ?? '')),
        trim((string) ($_POST['customer_name'] ?? '')),
        (string) ($_POST['payment_type'] ?? 'receipt'),
        trim((string) ($_POST['signer_party'] ?? '')),
        trim((string) ($_POST['signer_name'] ?? '')),
        trim((string) ($_POST['phone'] ?? '')),
        round((float) ($_POST['amount'] ?? 0), 2),
        ($_POST['signed_date'] ?? '') ?: null,
        ($_POST['effective_date'] ?? '') ?: null,
        ($_POST['expiry_date'] ?? '') ?: null,
        (string) ($_POST['status'] ?? 'ongoing'),
        $typeId > 0 ? $typeId : null,
    ];
    if ($vals[0] === '' || $vals[3] === '') {
        redirect('contract_form.php' . ($id > 0 ? '?id=' . $id . '&err=' : '?err=') . rawurlencode('合同编号和合同名称不能为空'));
    }
    if (!in_array($vals[3], ['receipt', 'payment'], true)) {
        $vals[3] = 'receipt';
    }
    if ($id > 0) {
        $txCountSt = $pdo->prepare('SELECT COUNT(*) FROM contract_transactions WHERE contract_id = ?');
        $txCountSt->execute([$id]);
        $lockedPaymentType = ((int) $txCountSt->fetchColumn()) > 0;
        if ($lockedPaymentType) {
            $oldPaymentTypeSt = $pdo->prepare('SELECT payment_type FROM contracts WHERE id = ?');
            $oldPaymentTypeSt->execute([$id]);
            $oldPaymentType = (string) ($oldPaymentTypeSt->fetchColumn() ?: 'receipt');
            $vals[5] = in_array($oldPaymentType, ['receipt', 'payment'], true) ? $oldPaymentType : 'receipt';
        }
        $pdo->prepare('UPDATE contracts SET contract_no=?,project_no=?,project_name=?,contract_name=?,customer_name=?,payment_type=?,signer_party=?,signer_name=?,phone=?,amount=?,signed_date=?,effective_date=?,expiry_date=?,status=?,type_id=? WHERE id=?')
            ->execute(array_merge($vals, [$id]));
    } else {
        $pdo->prepare('INSERT INTO contracts (contract_no,project_no,project_name,contract_name,customer_name,payment_type,signer_party,signer_name,phone,amount,signed_date,effective_date,expiry_date,status,type_id,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute(array_merge($vals, [$currentAdminId]));
        $id = (int) $pdo->lastInsertId();
    }
    if (isset($_FILES['attachments']) && is_array($_FILES['attachments']['name'] ?? null)) {
        $dir = __DIR__ . '/uploads/contracts';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $allow = ['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','image/jpeg','image/png','image/gif','image/webp'];
        $count = count($_FILES['attachments']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ((int) ($_FILES['attachments']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }
            $tmp = (string) $_FILES['attachments']['tmp_name'][$i];
            $origin = (string) $_FILES['attachments']['name'][$i];
            $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
            if (!in_array($mime, $allow, true)) {
                continue;
            }
            $ext = pathinfo($origin, PATHINFO_EXTENSION);
            $name = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . ($ext !== '' ? '.' . $ext : '');
            $full = $dir . '/' . $name;
            if (move_uploaded_file($tmp, $full)) {
                $pdo->prepare('INSERT INTO contract_files (contract_id,origin_name,file_path,mime_type,file_size) VALUES (?,?,?,?,?)')
                    ->execute([$id, $origin, 'uploads/contracts/' . $name, $mime, (int) filesize($full)]);
            }
        }
    }
    redirect('contract_form.php?id=' . $id . '&saved=1');
}

$types = $pdo->query('SELECT id,name FROM contract_types ORDER BY id DESC')->fetchAll() ?: [];
$files = [];
if ($isEdit) {
    $st = $pdo->prepare('SELECT * FROM contract_files WHERE contract_id=? ORDER BY id DESC');
    $st->execute([$id]);
    $files = $st->fetchAll() ?: [];
}

$pageTitle = $isEdit ? '编辑合同' : '新增合同';
$activeNav = 'orders';
ob_start();
?>
<div class="mf-panel">
    <div class="mf-panel__header"><?= e($pageTitle) ?></div>
    <div class="mf-panel__body">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">
            <input type="hidden" name="biz" value="<?= e($biz) ?>">

            <h3 class="mf-small mf-text-muted mf-mb-2">基本信息</h3>
            <div class="mf-row" style="margin-left:-6px;margin-right:-6px;">
                <div class="mf-col mf-col-12 mf-col-md-3" style="padding-left:6px;padding-right:6px;">
                    <div class="mf-form-item"><label class="mf-label">合同编号 *</label><input class="mf-input" required name="contract_no" value="<?= e((string) ($row['contract_no'] ?? '')) ?>"></div>
                </div>
                <div class="mf-col mf-col-12 mf-col-md-3" style="padding-left:6px;padding-right:6px;">
                    <div class="mf-form-item"><label class="mf-label">项目号</label><input class="mf-input" name="project_no" value="<?= e((string) ($row['project_no'] ?? '')) ?>" placeholder="合同唯一标识"></div>
                </div>
                <div class="mf-col mf-col-12 mf-col-md-3" style="padding-left:6px;padding-right:6px;">
                    <div class="mf-form-item"><label class="mf-label">项目名称</label><input class="mf-input" name="project_name" value="<?= e((string) ($row['project_name'] ?? '')) ?>" placeholder="项目/工程名称"></div>
                </div>
                <div class="mf-col mf-col-12 mf-col-md-3" style="padding-left:6px;padding-right:6px;">
                    <div class="mf-form-item"><label class="mf-label">合同名称 *</label><input class="mf-input" required name="contract_name" value="<?= e((string) ($row['contract_name'] ?? '')) ?>"></div>
                </div>
            </div>
            <div class="mf-row" style="margin-left:-6px;margin-right:-6px;">
                <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;">
                    <div class="mf-form-item"><label class="mf-label">客户名称</label><input class="mf-input" name="customer_name" value="<?= e((string) ($row['customer_name'] ?? '')) ?>"></div>
                </div>
                <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;">
                    <div class="mf-form-item"><label class="mf-label">签约方</label><input class="mf-input" name="signer_party" value="<?= e((string) ($row['signer_party'] ?? '')) ?>"></div>
                </div>
                <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;">
                    <div class="mf-form-item"><label class="mf-label">签约人</label><input class="mf-input" name="signer_name" value="<?= e((string) ($row['signer_name'] ?? '')) ?>"></div>
                </div>
                <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;">
                    <div class="mf-form-item"><label class="mf-label">联系电话</label><input class="mf-input" name="phone" value="<?= e((string) ($row['phone'] ?? '')) ?>"></div>
                </div>
            </div>
            <div class="mf-form-item">
                <label class="mf-label">款项类型</label>
                <?php if ($hasTransactions): ?>
                    <input type="hidden" name="payment_type" value="<?= e((string) ($row['payment_type'] ?? 'receipt')) ?>">
                <?php endif; ?>
                <select class="mf-select" name="payment_type" style="max-width:260px;"<?= $hasTransactions ? ' disabled' : '' ?>>
                    <?php $pt = (string) ($row['payment_type'] ?? 'receipt'); ?>
                    <option value="receipt"<?= $pt === 'receipt' ? ' selected' : '' ?>>收款</option>
                    <option value="payment"<?= $pt === 'payment' ? ' selected' : '' ?>>付款</option>
                </select>
                <?php if ($hasTransactions): ?>
                    <div class="mf-small mf-text-muted mf-mt-1">已存在登记进度，款项类型已锁定不可修改。</div>
                <?php endif; ?>
            </div>
            <div class="mf-form-item">
                <label class="mf-label">合同金额</label>
                <div style="max-width:260px;">
                    <input class="mf-input" id="contractAmountInput" type="number" min="0" step="0.01" name="amount" value="<?= e((string) ($row['amount'] ?? '0')) ?>">
                </div>
                <div class="mf-small mf-text-muted mf-mt-1">金额大写：<span id="contractAmountCN">零元整</span></div>
            </div>

            <h3 class="mf-small mf-text-muted mf-mb-2 mf-mt-3" style="margin-bottom:10px;">日期信息</h3>
            <div class="mf-row" style="margin-left:-6px;margin-right:-6px;">
                <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;">
                    <div class="mf-form-item">
                        <label class="mf-label">签订日期</label>
                        <input class="mf-input" type="date" name="signed_date" value="<?= e((string) ($row['signed_date'] ?? '')) ?>">
                    </div>
                </div>
                <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;">
                    <div class="mf-form-item">
                        <label class="mf-label">生效日期</label>
                        <input class="mf-input" type="date" name="effective_date" value="<?= e((string) ($row['effective_date'] ?? '')) ?>">
                    </div>
                </div>
                <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;">
                    <div class="mf-form-item">
                        <label class="mf-label">截止日期 <span class="mf-text-danger">*</span></label>
                        <input class="mf-input" type="date" name="expiry_date" value="<?= e((string) ($row['expiry_date'] ?? '')) ?>" required>
                    </div>
                </div>
            </div>

            <h3 class="mf-small mf-text-muted mf-mb-2 mf-mt-3">业务信息</h3>
            <div class="mf-row" style="margin-left:-6px;margin-right:-6px;">
                <div class="mf-col mf-col-12 mf-col-md-3" style="padding-left:6px;padding-right:6px;">
                    <div class="mf-form-item">
                        <label class="mf-label">合同状态</label>
                        <select class="mf-select" name="status">
                            <?php $sv = (string) ($row['status'] ?? 'ongoing'); ?>
                            <option value="ongoing"<?= $sv === 'ongoing' ? ' selected' : '' ?>>进行中</option>
                            <option value="completed"<?= $sv === 'completed' ? ' selected' : '' ?>>已完成</option>
                            <option value="terminated"<?= $sv === 'terminated' ? ' selected' : '' ?>>已终止</option>
                            <option value="expiring"<?= $sv === 'expiring' ? ' selected' : '' ?>>即将到期</option>
                        </select>
                    </div>
                </div>
                <div class="mf-col mf-col-12 mf-col-md-3" style="padding-left:6px;padding-right:6px;">
                    <div class="mf-form-item">
                        <label class="mf-label">合同类型</label>
                        <select class="mf-select" name="type_id">
                            <option value="0">未分类</option>
                            <?php foreach ($types as $t): ?>
                                <option value="<?= (int) $t['id'] ?>"<?= (int) ($row['type_id'] ?? 0) === (int) $t['id'] ? ' selected' : '' ?>><?= e((string) $t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mf-col mf-col-12 mf-col-md-6" style="padding-left:6px;padding-right:6px;">
                    <div class="mf-form-item">
                        <label class="mf-label">附件（PDF/Word/图片）</label>
                        <input id="contractAttachmentInput" type="file" class="mf-input" name="attachments[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.gif,.webp">
                        <div id="contractAttachmentPreview" class="mf-small mf-text-muted mf-mt-1">未选择文件</div>
                    </div>
                </div>
            </div>

            <div class="mf-mt-3">
                <?php if (!$isCompleted): ?>
                    <button class="mf-btn mf-btn--primary" type="submit">保存合同</button>
                <?php else: ?>
                    <span class="mf-small mf-text-muted">已完成合同不允许编辑</span>
                <?php endif; ?>
                <a class="mf-btn mf-btn--default" href="<?= e(url('orders.php' . ($biz !== '' ? ('?biz=' . rawurlencode($biz)) : ''))) ?>">返回列表</a>
            </div>
        </form>

        <?php if ($files): ?>
            <div class="mf-mt-3">
                <div class="mf-small mf-text-muted mf-mb-1">合同附件</div>
                <?php foreach ($files as $f): ?>
                    <?php
                    $u = url((string) $f['file_path']);
                    $img = strpos((string) $f['mime_type'], 'image/') === 0;
                    $pdf = ((string) $f['mime_type'] === 'application/pdf');
                    ?>
                    <div class="mf-flex mf-items-center mf-gap-2 mf-mb-1">
                        <span><?= e((string) $f['origin_name']) ?></span>
                        <?php if ($img || $pdf): ?><a class="mf-btn mf-btn--default mf-btn--sm" target="_blank" href="<?= e($u) ?>">预览</a><?php endif; ?>
                        <a class="mf-btn mf-btn--default mf-btn--sm" href="<?= e($u) ?>" download>下载</a>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="delete_file">
                            <input type="hidden" name="file_id" value="<?= (int) $f['id'] ?>">
                            <button class="mf-btn mf-btn--danger mf-btn--sm" type="submit">删除</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
  function toCN(num) {
    num = Number(num || 0);
    if (!isFinite(num) || num < 0) return '零元整';
    if (num === 0) return '零元整';
    var digits = ['零','壹','贰','叁','肆','伍','陆','柒','捌','玖'];
    var radices = ['','拾','佰','仟'];
    var units = ['','万','亿','兆'];
    var decUnits = ['角','分'];
    var val = Math.round(num * 100);
    var intNum = Math.floor(val / 100);
    var decNum = val % 100;
    var out = '';
    if (intNum > 0) {
      var s = String(intNum);
      var zero = false;
      for (var i = 0; i < s.length; i++) {
        var n = Number(s.charAt(i));
        var p = s.length - i - 1;
        var q = Math.floor(p / 4);
        var r = p % 4;
        if (n === 0) zero = true;
        else {
          if (zero) out += digits[0];
          zero = false;
          out += digits[n] + radices[r];
        }
        if (r === 0 && !zero) out += units[q];
      }
      out += '元';
    }
    if (decNum === 0) return out + '整';
    var j = Math.floor(decNum / 10);
    var f = decNum % 10;
    if (j > 0) out += digits[j] + decUnits[0];
    if (f > 0) out += digits[f] + decUnits[1];
    return out || '零元整';
  }
  var amountEl = document.getElementById('contractAmountInput');
  var cnEl = document.getElementById('contractAmountCN');
  if (amountEl && cnEl) {
    function sync() { cnEl.textContent = toCN(amountEl.value); }
    amountEl.addEventListener('input', sync);
    sync();
  }

  var fileInput = document.getElementById('contractAttachmentInput');
  var preview = document.getElementById('contractAttachmentPreview');
  if (fileInput && preview) {
    fileInput.addEventListener('change', function () {
      var files = Array.prototype.slice.call(fileInput.files || []);
      if (!files.length) {
        preview.innerHTML = '未选择文件';
        return;
      }
      var html = files.map(function (f) {
        return '<div style="padding:2px 0;">' + f.name + '（' + Math.round((f.size || 0) / 1024) + 'KB）</div>';
      }).join('');
      preview.innerHTML = html;
    });
  }
})();
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
