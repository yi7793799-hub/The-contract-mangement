<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('search.view');
$pdo = db();

$kw = trim((string) ($_GET['kw'] ?? ''));
$searched = (string) ($_GET['searched'] ?? '') === '1';
$where = ['c.is_archived = 0'];
$params = [];
if ($kw !== '') {
    $where[] = '(c.contract_no LIKE ? OR c.project_no LIKE ? OR c.contract_name LIKE ? OR c.customer_name LIKE ?)';
    $like = '%' . $kw . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$rows = [];
if ($searched) {
    $st = $pdo->prepare(
        'SELECT c.*, t.name AS type_name FROM contracts c LEFT JOIN contract_types t ON t.id=c.type_id WHERE ' . implode(' AND ', $where) . ' ORDER BY c.id DESC LIMIT 50'
    );
    $st->execute($params);
    $rows = $st->fetchAll() ?: [];
}

$pageTitle = '快速搜索';
$activeNav = 'quick_search';
ob_start();
?>
<style>
  .qs-wrap {
    max-width: 920px;
    margin: 0 auto;
    width: 100%;
  }
  .qs-plain {
    padding: 4px 0;
  }
  .qs-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    justify-content: center;
  }
  .qs-tip {
    margin-top: 8px;
    text-align: center;
  }
  .qs-row-gap {
    margin-top: 8px;
  }
  .qs-input {
    height: 42px;
    font-size: 14px;
  }
  .qs-btn {
    min-width: 96px;
    height: 38px;
    line-height: 36px;
    font-size: 14px;
    padding: 0 16px;
  }
</style>
<div class="mf-panel" style="<?= $searched ? '' : 'min-height: calc(100vh - 220px); display:flex; align-items:center; justify-content:center;' ?>">
  <div class="mf-panel__body" style="width:100%;">
    <div class="qs-wrap">
      <div class="qs-plain">
    <form method="get">
      <input type="hidden" name="searched" value="1">
      <div class="mf-form-item">
        <label class="mf-label mf-small mf-text-muted">关键词</label>
        <input class="mf-input qs-input" name="kw" value="<?= e($kw) ?>" placeholder="合同编号 / 项目号 / 合同名称 / 客户名称">
      </div>
      <div class="qs-row-gap">
        <div class="qs-actions">
          <button class="mf-btn mf-btn--primary qs-btn">搜索</button>
          <a class="mf-btn mf-btn--default qs-btn" href="<?= e(url('quick_search.php')) ?>">重置</a>
        </div>
      </div>
    </form>
      </div>
    </div>
  </div>
</div>
<?php if ($searched): ?>
<div class="mf-panel"><div class="mf-table-wrap"><table class="mf-table mf-table--striped table-mf mf-mb-0"><thead><tr><th>合同编号</th><th>合同名称</th><th>款项类型</th><th>客户名称</th><th>合同类型</th><th>状态</th><th>合同金额</th><th>截止日期</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= e((string)$r['contract_no']) ?></td><td><a href="<?= e(url('contract_view.php?id='.(int)$r['id'])) ?>"><?= e((string)$r['contract_name']) ?></a></td><td><?= mf_payment_type_badge((string)($r['payment_type'] ?? 'receipt')) ?></td><td><?= e((string)$r['customer_name']) ?></td><td><?= e((string)($r['type_name']?:'-')) ?></td><td><?= mf_contract_status_badge((string)$r['status']) ?></td><td>¥<?= number_format((float)$r['amount'],2) ?></td><td><?= e((string)($r['expiry_date']?:'-')) ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="8" class="mf-text-center mf-text-muted mf-p-4">未搜索到合同数据</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
