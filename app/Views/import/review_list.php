<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>待审核合同</title>
    <link href="<?= asset_url('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('css/mf-ui.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('css/app.css') ?>" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../../includes/layout.php'; ?>

<div class="container-fluid">
    <div class="page-header">
        <h1>待审核合同</h1>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
    <div class="alert alert-success"><?= e($_SESSION['success']) ?></div>
    <?php unset($_SESSION['success']); endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?= e($_SESSION['error']) ?></div>
    <?php unset($_SESSION['error']); endif; ?>

    <div class="card">
        <div class="card-body">
            <?php if (empty($contracts)): ?>
            <p class="text-muted">暂无待审核合同</p>
            <?php else: ?>
            <form method="post" action="<?= url('/import/batch-approve') ?>">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAll"></th>
                            <th>合同编号</th>
                            <th>合同名称</th>
                            <th>客户名称</th>
                            <th>金额(万元)</th>
                            <th>置信度</th>
                            <th>导入时间</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($contracts as $c): ?>
                        <tr>
                            <td><input type="checkbox" name="ids[]" value="<?= $c['id'] ?>"></td>
                            <td><?= e($c['contract_no']) ?></td>
                            <td><?= e($c['contract_name']) ?></td>
                            <td><?= e($c['customer_name']) ?></td>
                            <td><?= number_format($c['amount'], 4) ?></td>
                            <td>
                                <?php
                                $conf = (float) ($c['import_confidence'] ?? 0);
                                $color = $conf >= 85 ? 'success' : ($conf >= 60 ? 'warning' : 'danger');
                                ?>
                                <span class="badge bg-<?= $color ?>"><?= number_format($conf, 1) ?>%</span>
                            </td>
                            <td><?= date('Y-m-d H:i', strtotime($c['created_at'])) ?></td>
                            <td>
                                <a href="<?= url('/import/review/' . $c['id']) ?>" class="btn btn-sm btn-primary">
                                    审核
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="mt-3">
                    <button type="submit" class="btn btn-success">批量通过</button>
                    <button type="button" class="btn btn-danger" onclick="batchReject()">批量驳回</button>
                </div>
            </form>

            <?php if ($totalPages > 1): ?>
            <nav>
                <ul class="pagination">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= url('/import/review?page=' . $i) ?>"><?= $i ?></a>
                    </li>
                    <?php endfor; ?>
                </ul>
            </nav>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-3">
        <a href="<?= url('/import') ?>" class="btn btn-secondary">返回导入页</a>
    </div>
</div>

<script>
document.getElementById('selectAll').onclick = function() {
    var checkboxes = document.querySelectorAll('input[name="ids[]"]');
    checkboxes.forEach(function(cb) { cb.checked = document.getElementById('selectAll').checked; });
};

function batchReject() {
    if (confirm('确定要驳回选中的合同吗？')) {
        var form = document.createElement('form');
        form.method = 'post';
        form.action = '<?= url('/import/batch-reject') ?>';

        document.querySelectorAll('input[name="ids[]"]:checked').forEach(function(cb) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = cb.value;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    }
}
</script>
</body>
</html>
