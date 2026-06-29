<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
require_permission('remind.view');
$pdo = db();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_permission('remind.edit');
    if (!csrf_verify($_POST['csrf'] ?? null)) {
        redirect('report.php?err=' . rawurlencode('会话已过期'));
    }
    $days = max(1, (int) ($_POST['remind_days'] ?? 15));
    $pdo->prepare('UPDATE contract_settings SET remind_days=? WHERE id=1')->execute([$days]);
    redirect('report.php?saved=1');
}
$days = (int) ($pdo->query('SELECT remind_days FROM contract_settings WHERE id=1')->fetchColumn() ?: 15);
$st = $pdo->prepare("SELECT c.*, t.name AS type_name, DATEDIFF(c.expiry_date, CURDATE()) AS left_days FROM contracts c LEFT JOIN contract_types t ON t.id=c.type_id WHERE c.expiry_date IS NOT NULL AND DATEDIFF(c.expiry_date, CURDATE()) BETWEEN 0 AND ? ORDER BY c.expiry_date ASC");
$st->execute([$days]);
$rows = $st->fetchAll() ?: [];
$pageTitle = '到期提醒';
$activeNav = 'report';
ob_start();
?>
<div class="mf-panel"><div class="mf-panel__body"><form method="post" class="mf-flex mf-items-end mf-gap-2"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><div><label class="mf-label">到期前提醒天数</label><input class="mf-input" type="number" min="1" name="remind_days" value="<?= (int) $days ?>"></div><button class="mf-btn mf-btn--primary" type="submit">保存</button></form></div></div>
<div class="mf-panel"><div class="mf-table-wrap"><table class="mf-table mf-table--striped table-mf mf-mb-0"><thead><tr><th>合同编号</th><th>合同名称</th><th>客户名称</th><th>合同类型</th><th>截止日期</th><th>剩余天数</th><th>状态</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= e((string)$r['contract_no']) ?></td><td><?= e((string)$r['contract_name']) ?><?= mf_subcontract_tag((string)$r['contract_name'], (int)($r['is_subcontract'] ?? 0)) ?></td><td><?= e((string)$r['customer_name']) ?></td><td><?= e((string)($r['type_name']?:'-')) ?></td><td><?= e((string)$r['expiry_date']) ?></td><td><?= (int)$r['left_days'] ?></td><td><?= mf_contract_status_badge((string)$r['status']) ?></td></tr><?php endforeach; ?><?php if(!$rows): ?><tr><td colspan="7" class="mf-text-center mf-text-muted mf-p-4">暂无即将到期合同</td></tr><?php endif; ?></tbody></table></div></div>
<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';
