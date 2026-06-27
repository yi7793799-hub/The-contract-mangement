<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('contracts.view');
$pdo = db();
$admin = current_admin() ?? [];
$ownOnly = mf_own_contract_only_enabled($pdo) && (($admin['role'] ?? 'normal') !== 'super');
$currentAdminId = (int) ($admin['id'] ?? 0);
$biz = (string) ($_GET['biz'] ?? '');
if (!in_array($biz, ['receipt', 'payment'], true)) {
    $biz = '';
}
$listUrl = 'orders.php' . ($biz !== '' ? ('?biz=' . $biz) : '');

$id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare("SELECT c.*, t.name AS type_name, COALESCE(a.display_name, a.username, '-') AS creator_name,
    CASE WHEN c.expiry_date IS NULL THEN NULL ELSE DATEDIFF(c.expiry_date, CURDATE()) END AS left_days
    FROM contracts c
    LEFT JOIN contract_types t ON t.id=c.type_id
    LEFT JOIN admins a ON a.id = c.created_by
    WHERE c.id=?");
$st->execute([$id]);
$row = $st->fetch();
if (!$row) {
    redirect('orders.php?err=' . rawurlencode('合同不存在'));
}
if ($ownOnly && (int) ($row['created_by'] ?? 0) !== $currentAdminId) {
    redirect('orders.php?err=' . rawurlencode('仅可操作自己登记的合同'));
}

$sf = $pdo->prepare('SELECT * FROM contract_files WHERE contract_id=? ORDER BY id DESC');
$sf->execute([$id]);
$files = $sf->fetchAll() ?: [];
$sumSt = $pdo->prepare(
    "SELECT tx_type, COALESCE(SUM(amount),0) AS s
     FROM contract_transactions
     WHERE contract_id = ?
     GROUP BY tx_type"
);
$sumSt->execute([$id]);
$receiptDone = 0.0;
$paymentDone = 0.0;
while ($sr = $sumSt->fetch(PDO::FETCH_ASSOC)) {
    if ((string) $sr['tx_type'] === 'receipt') {
        $receiptDone = (float) $sr['s'];
    } elseif ((string) $sr['tx_type'] === 'payment') {
        $paymentDone = (float) $sr['s'];
    }
}
$totalAmount = (float) $row['amount'];
$paymentType = (string) ($row['payment_type'] ?? 'receipt');
$receiptPct = $totalAmount > 0 ? min(100.0, ($receiptDone / $totalAmount) * 100.0) : 0.0;
$paymentPct = $totalAmount > 0 ? min(100.0, ($paymentDone / $totalAmount) * 100.0) : 0.0;
$currentTxType = $paymentType === 'payment' ? 'payment' : 'receipt';
$currentTxLabel = $currentTxType === 'payment' ? '付款' : '收款';
$currentDone = $currentTxType === 'payment' ? $paymentDone : $receiptDone;
$currentPct = $currentTxType === 'payment' ? $paymentPct : $receiptPct;
$currentLeft = max(0.0, $totalAmount - $currentDone);
$invoiceDone = 0.0;
$invoiceTimelineRows = [];
if (db_table_exists($pdo, 'contract_invoices')) {
    $invoiceSt = $pdo->prepare('SELECT COALESCE(SUM(amount),0) AS s FROM contract_invoices WHERE contract_id = ? AND invoice_type = ?');
    $invoiceSt->execute([$id, $currentTxType]);
    $invoiceDone = (float) ($invoiceSt->fetchColumn() ?: 0);
    $invoiceTimelineSt = $pdo->prepare(
        "SELECT i.id, i.amount, i.note, i.file_path, i.created_at, COALESCE(a.display_name, a.username, '-') AS registrar_name
         FROM contract_invoices i
         LEFT JOIN admins a ON a.id = i.created_by
         WHERE i.contract_id = ? AND i.invoice_type = ?
         ORDER BY i.id DESC"
    );
    $invoiceTimelineSt->execute([$id, $currentTxType]);
    $invoiceTimelineRows = $invoiceTimelineSt->fetchAll() ?: [];
}
$invoiceLeft = max(0.0, $currentDone - $invoiceDone);
$invoicePct = $currentDone > 0 ? min(100.0, ($invoiceDone / $currentDone) * 100.0) : 0.0;
$timelineSt = $pdo->prepare(
    "SELECT t.id, t.amount, t.note, t.created_at, COALESCE(a.display_name, a.username, '-') AS registrar_name
     FROM contract_transactions
     t LEFT JOIN admins a ON a.id = t.created_by
     WHERE t.contract_id = ? AND t.tx_type = ?
     ORDER BY t.id DESC"
);
$timelineSt->execute([$id, $currentTxType]);
$timelineRows = $timelineSt->fetchAll() ?: [];

function money_to_cn(float $n): string
{
    $n = round($n, 2);
    if ($n <= 0) {
        return '零元整';
    }

    $digit = ['零','壹','贰','叁','肆','伍','陆','柒','捌','玖'];
    $unit1 = ['','拾','佰','仟'];
    $unit2 = ['','万','亿'];

    $int = (int) floor($n);
    $dec = (int) round(($n - $int) * 100);

    $out = '';

    // 处理整数部分 - 分段处理（每4位一段）
    if ($int > 0) {
        $sections = [];
        $tempInt = $int;
        while ($tempInt > 0) {
            $sections[] = $tempInt % 10000;
            $tempInt = (int) floor($tempInt / 10000);
        }

        $sectionCount = count($sections);
        for ($i = $sectionCount - 1; $i >= 0; $i--) {
            $section = $sections[$i];
            if ($section === 0) {
                continue;
            }

            $sectionStr = '';
            $thousands = (int) floor($section / 1000);
            $hundreds = (int) floor(($section % 1000) / 100);
            $tens = (int) floor(($section % 100) / 10);
            $ones = $section % 10;

            if ($thousands > 0) {
                $sectionStr .= $digit[$thousands] . '仟';
            }
            if ($hundreds > 0) {
                $sectionStr .= $digit[$hundreds] . '佰';
            } elseif ($thousands > 0 && ($tens > 0 || $ones > 0)) {
                $sectionStr .= '零';
            }
            if ($tens > 0) {
                $sectionStr .= $digit[$tens] . '拾';
            } elseif ($hundreds > 0 && $ones > 0) {
                $sectionStr .= '零';
            }
            if ($ones > 0) {
                $sectionStr .= $digit[$ones];
            }

            // 添加段单位
            if ($i === 1) {
                $sectionStr .= '万';
            } elseif ($i === 2) {
                $sectionStr .= '亿';
            }

            // 如果前面有内容且当前段不是第一段，需要补零
            if ($out !== '' && $section < 1000) {
                $out .= '零';
            }

            $out .= $sectionStr;
        }

        $out .= '元';
    }

    // 处理小数部分
    if ($dec === 0) {
        return $out . '整';
    }

    $j = (int) floor($dec / 10);
    $f = $dec % 10;

    if ($j > 0) {
        $out .= $digit[$j] . '角';
    }
    if ($f > 0) {
        if ($j === 0 && $int > 0) {
            $out .= '零';
        }
        $out .= $digit[$f] . '分';
    }

    return $out;
}

$pageTitle = '合同详情';
$activeNav = 'orders';
ob_start();
?>
<div class="mf-panel">
  <div class="mf-panel__header">合同详情</div>
  <div class="mf-panel__body">
    <div class="mf-mb-2">
      <button type="button" class="mf-btn mf-btn--primary mf-btn--sm js-main-tab" data-target="contractInfoPane">合同详情</button>
      <button type="button" class="mf-btn mf-btn--default mf-btn--sm js-main-tab" data-target="contractFinancePane">收付款进度</button>
      <button type="button" class="mf-btn mf-btn--default mf-btn--sm js-main-tab" data-target="contractInvoicePane">发票进度</button>
    </div>
    <div id="contractInfoPane" class="js-main-pane">
    <div class="mf-row" style="margin-left:-6px;margin-right:-6px;">
      <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;"><div class="mf-form-item"><label class="mf-label">合同编号</label><input class="mf-input" disabled value="<?= e((string) $row['contract_no']) ?>"></div></div>
      <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;"><div class="mf-form-item" style="background:#fffbe6;border:2px solid #d48806;padding:12px;border-radius:6px;"><label class="mf-label" style="color:#d48806;font-weight:700;">项目号</label><input class="mf-input" disabled value="<?= e((string) ($row['project_no'] ?? '-')) ?>" style="border-color:#d48806;font-weight:700;font-size:15px;background:#fffbe6;"></div></div>
      <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;"><div class="mf-form-item"><label class="mf-label">合同名称</label><input class="mf-input" disabled value="<?= e((string) $row['contract_name']) ?>"></div></div>
    </div>
    <div class="mf-row" style="margin-left:-6px;margin-right:-6px;">
      <div class="mf-col mf-col-12 mf-col-md-6" style="padding-left:6px;padding-right:6px;"><div class="mf-form-item"><label class="mf-label">项目名称</label><input class="mf-input" disabled value="<?= e((string) ($row['project_name'] ?? '-')) ?>"></div></div>
      <div class="mf-col mf-col-12 mf-col-md-6" style="padding-left:6px;padding-right:6px;"><div class="mf-form-item"><label class="mf-label">客户名称</label><input class="mf-input" disabled value="<?= e((string) $row['customer_name']) ?>"></div></div>
    </div>
    <div class="mf-row" style="margin-left:-6px;margin-right:-6px;">
      <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;"><div class="mf-form-item"><label class="mf-label">甲方</label><input class="mf-input" disabled value="<?= e((string) $row['signer_party']) ?>"></div></div>
      <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;"><div class="mf-form-item"><label class="mf-label">签约人</label><input class="mf-input" disabled value="<?= e((string) $row['signer_name']) ?>"></div></div>
      <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;"><div class="mf-form-item"><label class="mf-label">联系电话</label><input class="mf-input" disabled value="<?= e((string) $row['phone']) ?>"></div></div>
    </div>
      <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;"><div class="mf-form-item" style="background:#fffbe6;border:2px solid #d48806;padding:12px;border-radius:6px;"><label class="mf-label" style="color:#d48806;font-weight:700;">合同金额</label><input class="mf-input" disabled value="¥<?= e(number_format((float) $row['amount'], 2)) ?>" style="border-color:#d48806;font-weight:700;font-size:15px;background:#fffbe6;"></div></div>
      <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;"><div class="mf-form-item"><label class="mf-label">金额大写</label><input class="mf-input" disabled value="<?= e(money_to_cn((float) $row['amount'])) ?>"></div></div>
    </div>
    <div class="mf-row" style="margin-left:-6px;margin-right:-6px;">
      <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;"><div class="mf-form-item"><label class="mf-label">签订日期</label><input class="mf-input" disabled value="<?= e((string) ($row['signed_date'] ?: '-')) ?>"></div></div>
      <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;"><div class="mf-form-item"><label class="mf-label">生效日期</label><input class="mf-input" disabled value="<?= e((string) ($row['effective_date'] ?: '-')) ?>"></div></div>
      <div class="mf-col mf-col-12 mf-col-md-4" style="padding-left:6px;padding-right:6px;"><div class="mf-form-item" style="background:#fffbe6;border:2px solid #d48806;padding:12px;border-radius:6px;"><label class="mf-label" style="color:#d48806;font-weight:700;">截止日期</label><input class="mf-input" disabled value="<?= e((string) ($row['expiry_date'] ?: '长期有效')) ?>" style="border-color:#d48806;font-weight:700;font-size:15px;background:#fffbe6;"></div></div>
    </div>
    <div class="mf-row" style="margin-left:-6px;margin-right:-6px;">
      <div class="mf-col mf-col-12 mf-col-md-6" style="padding-left:6px;padding-right:6px;"><div class="mf-form-item"><label class="mf-label">剩余天数</label><input class="mf-input" disabled value="<?= $row['left_days'] === null ? '-' : ((int) $row['left_days'] . ' 天') ?>"></div></div>
      <div class="mf-col mf-col-12 mf-col-md-6" style="padding-left:6px;padding-right:6px;"><div class="mf-form-item"><label class="mf-label">合同状态</label><input class="mf-input" disabled value="<?= e(mf_contract_status_label((string) $row['status'])) ?>"></div></div>
    </div>
    <div class="mf-form-item"><label class="mf-label">附件</label>
      <?php if ($files): ?>
        <?php foreach ($files as $f): $fileId = (int) $f['id']; $img = strpos((string) $f['mime_type'], 'image/') === 0; $pdf = (string) $f['mime_type'] === 'application/pdf'; $dlUrl = url('api/file-download.php?id=' . $fileId); ?>
          <div class="mf-flex mf-items-center mf-gap-2 mf-mb-1">
            <span><?= e((string) $f['origin_name']) ?></span>
            <?php if ($img || $pdf): ?><a class="mf-btn mf-btn--default mf-btn--sm" target="_blank" href="<?= e($dlUrl) ?>">预览</a><?php endif; ?>
            <a class="mf-btn mf-btn--default mf-btn--sm" href="<?= e($dlUrl) ?>" download="<?= e((string) $f['origin_name']) ?>">下载</a>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="mf-small mf-text-muted">无附件</div>
      <?php endif; ?>
    </div>
    </div>
    <div id="contractFinancePane" class="js-main-pane" style="display:none;">
      <div>
        <div class="mf-form-item" style="border:1px solid #ebeef5;background:#fafafa;padding:12px 14px;margin-bottom:12px;">
          <div class="mf-small mf-text-muted" style="margin-bottom:8px;"><?= e($currentTxLabel) ?>进度卡片</div>
          <div class="mf-row" style="margin-left:-6px;margin-right:-6px;">
            <div class="mf-col mf-col-12 mf-col-md-3" style="padding-left:6px;padding-right:6px;">
              <div class="mf-small mf-text-muted">合同金额</div>
              <div style="font-size:15px;color:#303133;">¥<?= number_format($totalAmount, 2) ?></div>
            </div>
            <div class="mf-col mf-col-12 mf-col-md-3" style="padding-left:6px;padding-right:6px;">
              <div class="mf-small mf-text-muted">已<?= e($currentTxLabel) ?>金额</div>
              <div style="font-size:15px;color:#303133;">¥<?= number_format($currentDone, 2) ?></div>
            </div>
            <div class="mf-col mf-col-12 mf-col-md-3" style="padding-left:6px;padding-right:6px;">
              <div class="mf-small mf-text-muted">待<?= e($currentTxLabel) ?>金额</div>
              <div style="font-size:15px;color:#303133;">¥<?= number_format($currentLeft, 2) ?></div>
            </div>
            <div class="mf-col mf-col-12 mf-col-md-3" style="padding-left:6px;padding-right:6px;">
              <div class="mf-small mf-text-muted">完成率</div>
              <div style="font-size:15px;color:#303133;"><?= number_format($currentPct, 2) ?>%</div>
            </div>
          </div>
        </div>
        <div class="mf-row" style="margin-left:-6px;margin-right:-6px;">
          <div class="mf-col mf-col-12" style="padding-left:6px;padding-right:6px;">
            <?php if ($paymentType === 'payment'): ?>
              <div class="mf-form-item">
                <label class="mf-label">付款进度（已付 ¥<?= number_format($paymentDone, 2) ?> / 合同 ¥<?= number_format($totalAmount, 2) ?>）</label>
                <div style="height:10px;background:#ebeef5;overflow:hidden;">
                  <div style="height:10px;width:<?= number_format($paymentPct, 2, '.', '') ?>%;background:#e6a23c;"></div>
                </div>
                <div class="mf-small mf-text-muted"><?= number_format($paymentPct, 2) ?>%</div>
              </div>
            <?php else: ?>
              <div class="mf-form-item">
                <label class="mf-label">收款进度（已收 ¥<?= number_format($receiptDone, 2) ?> / 合同 ¥<?= number_format($totalAmount, 2) ?>）</label>
                <div style="height:10px;background:#ebeef5;overflow:hidden;">
                  <div style="height:10px;width:<?= number_format($receiptPct, 2, '.', '') ?>%;background:#67c23a;"></div>
                </div>
                <div class="mf-small mf-text-muted"><?= number_format($receiptPct, 2) ?>%</div>
              </div>
            <?php endif; ?>
          </div>
        </div>
        <div class="mf-form-item">
          <label class="mf-label"><?= e($currentTxLabel) ?>登记记录（时间线）</label>
          <?php if ($timelineRows): ?>
            <div style="border-left:2px solid #e4e7ed;padding-left:14px;margin-left:4px;">
              <?php foreach ($timelineRows as $tr): ?>
                <div style="position:relative;padding:0 0 14px 0;">
                  <span style="position:absolute;left:-20px;top:3px;width:8px;height:8px;border-radius:50%;background:#409eff;display:block;"></span>
                  <div class="mf-small" style="color:#909399;"><?= e((string) $tr['created_at']) ?></div>
                  <div style="font-size:13px;color:#303133;"><?= e($currentTxLabel) ?>金额：¥<?= number_format((float) $tr['amount'], 2) ?></div>
                  <div class="mf-small mf-text-muted">登记人：<?= e((string) ($tr['registrar_name'] ?? '-')) ?></div>
                  <div class="mf-small mf-text-muted">备注：<?= e((string) ($tr['note'] ?: '-')) ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="mf-small mf-text-muted">暂无<?= e($currentTxLabel) ?>登记记录</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div id="contractInvoicePane" class="js-main-pane" style="display:none;">
      <div class="mf-form-item" style="border:1px solid #ebeef5;background:#fafafa;padding:12px 14px;margin-bottom:12px;">
        <div class="mf-small mf-text-muted" style="margin-bottom:8px;"><?= e($currentTxLabel) ?>发票进度卡片</div>
        <div class="mf-row" style="margin-left:-6px;margin-right:-6px;">
          <div class="mf-col mf-col-12 mf-col-md-3" style="padding-left:6px;padding-right:6px;">
            <div class="mf-small mf-text-muted">可开票金额</div>
            <div style="font-size:15px;color:#303133;">¥<?= number_format($currentDone, 2) ?></div>
          </div>
          <div class="mf-col mf-col-12 mf-col-md-3" style="padding-left:6px;padding-right:6px;">
            <div class="mf-small mf-text-muted">已开票金额</div>
            <div style="font-size:15px;color:#303133;">¥<?= number_format($invoiceDone, 2) ?></div>
          </div>
          <div class="mf-col mf-col-12 mf-col-md-3" style="padding-left:6px;padding-right:6px;">
            <div class="mf-small mf-text-muted">待开票金额</div>
            <div style="font-size:15px;color:#303133;">¥<?= number_format($invoiceLeft, 2) ?></div>
          </div>
          <div class="mf-col mf-col-12 mf-col-md-3" style="padding-left:6px;padding-right:6px;">
            <div class="mf-small mf-text-muted">开票率</div>
            <div style="font-size:15px;color:#303133;"><?= number_format($invoicePct, 2) ?>%</div>
          </div>
        </div>
      </div>
      <div class="mf-form-item">
        <label class="mf-label"><?= e($currentTxLabel) ?>开票进度（已开 ¥<?= number_format($invoiceDone, 2) ?> / 可开 ¥<?= number_format($currentDone, 2) ?>）</label>
        <div style="height:10px;background:#ebeef5;overflow:hidden;">
          <div style="height:10px;width:<?= number_format($invoicePct, 2, '.', '') ?>%;background:#409eff;"></div>
        </div>
        <div class="mf-small mf-text-muted"><?= number_format($invoicePct, 2) ?>%</div>
      </div>
      <div class="mf-form-item">
        <label class="mf-label"><?= e($currentTxLabel) ?>开票记录（时间线）</label>
        <?php if ($invoiceTimelineRows): ?>
          <div style="border-left:2px solid #e4e7ed;padding-left:14px;margin-left:4px;">
            <?php foreach ($invoiceTimelineRows as $iv): ?>
              <div style="position:relative;padding:0 0 14px 0;">
                <span style="position:absolute;left:-20px;top:3px;width:8px;height:8px;border-radius:50%;background:#409eff;display:block;"></span>
                <div class="mf-small" style="color:#909399;"><?= e((string) $iv['created_at']) ?></div>
                <div style="font-size:13px;color:#303133;">开票金额：¥<?= number_format((float) $iv['amount'], 2) ?></div>
                <div class="mf-small mf-text-muted">登记人：<?= e((string) ($iv['registrar_name'] ?? '-')) ?></div>
                <div class="mf-small mf-text-muted">备注：<?= e((string) ($iv['note'] ?: '-')) ?></div>
                <?php if ((string) ($iv['file_path'] ?? '') !== ''): ?>
                  <div class="mf-small"><a href="<?= e(url((string) $iv['file_path'])) ?>" target="_blank">查看附件</a></div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="mf-small mf-text-muted">暂无<?= e($currentTxLabel) ?>开票记录</div>
        <?php endif; ?>
      </div>
      <div class="mf-form-item">
        <label class="mf-label">开票说明</label>
        <div class="mf-small mf-text-muted">发票进度按当前合同款项类型统计（<?= e($currentTxLabel) ?>）。</div>
      </div>
    </div>
    <div class="mf-mt-3">
      <a class="mf-btn mf-btn--primary" href="<?= e(url('contract_form.php?id=' . (int) $row['id'] . ($biz !== '' ? '&biz=' . rawurlencode($biz) : ''))) ?>">编辑合同</a>
      <a class="mf-btn mf-btn--default" href="<?= e(url($listUrl)) ?>">返回列表</a>
    </div>
  </div>
</div>
<script>
(function () {
  var mainTabs = document.querySelectorAll('.js-main-tab');
  var mainPanes = document.querySelectorAll('.js-main-pane');
  if (mainTabs.length && mainPanes.length) {
    mainTabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        var target = tab.getAttribute('data-target');
        mainPanes.forEach(function (pane) {
          pane.style.display = pane.id === target ? '' : 'none';
        });
        mainTabs.forEach(function (btn) {
          btn.classList.remove('mf-btn--primary');
          btn.classList.add('mf-btn--default');
        });
        tab.classList.remove('mf-btn--default');
        tab.classList.add('mf-btn--primary');
      });
    });
  }

})();
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
