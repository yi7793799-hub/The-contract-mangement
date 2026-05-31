<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>审核合同</title>
    <link href="<?= asset_url('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('css/mf-ui.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('css/app.css') ?>" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../../includes/layout.php'; ?>

<div class="container-fluid">
    <div class="page-header">
        <h1>审核合同</h1>
        <a href="<?= url('/import/review') ?>" class="btn btn-secondary">返回列表</a>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
    <div class="alert alert-danger"><?= e($_SESSION['error']) ?></div>
    <?php unset($_SESSION['error']); endif; ?>

    <div class="card">
        <div class="card-header">
            <h5>识别结果</h5>
        </div>
        <div class="card-body">
            <?php if (!empty($files)): ?>
            <p>
                <strong>原始文件:</strong>
                <?php foreach ($files as $file): ?>
                <a href="<?= asset_url($file['file_path']) ?>" target="_blank" class="btn btn-sm btn-info">
                    <?= e($file['origin_name']) ?>
                </a>
                <?php endforeach; ?>
            </p>
            <?php endif; ?>

            <table class="table table-bordered mt-3">
                <thead>
                    <tr>
                        <th>字段</th>
                        <th>识别值</th>
                        <th>置信度</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $fieldLabels = [
                        'contract_no' => '合同编号',
                        'contract_name' => '合同名称',
                        'customer_name' => '客户名称',
                        'signer_party' => '签约方',
                        'signer_name' => '签约人',
                        'phone' => '联系电话',
                        'amount' => '金额',
                        'signed_date' => '签订日期',
                        'effective_date' => '生效日期',
                        'expiry_date' => '截止日期',
                        'payment_type' => '款项类型',
                    ];

                    $highThreshold = 85;
                    $lowThreshold = 60;

                    foreach ($fieldLabels as $key => $label):
                        $value = $contract[$key] ?? '';
                        $conf = $fields[$key] ?? null;
                        $confValue = is_numeric($conf) ? (float) $conf : null;
                        $confClass = $confValue === null ? 'secondary' : ($confValue >= $highThreshold ? 'success' : ($confValue >= $lowThreshold ? 'warning' : 'danger'));
                    ?>
                    <tr>
                        <td><strong><?= e($label) ?></strong></td>
                        <td><?= e($value) ?: '-' ?></td>
                        <td>
                            <?php if ($confValue !== null): ?>
                            <span class="badge bg-<?= $confClass ?>"><?= number_format($confValue, 1) ?>%</span>
                            <?php else: ?>
                            <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php
            $lowConfCount = 0;
            foreach ($fields as $conf) {
                if (is_numeric($conf) && $conf < $lowThreshold) {
                    $lowConfCount++;
                }
            }
            ?>

            <?php if ($lowConfCount > 0): ?>
            <div class="alert alert-warning mt-3">
                有 <?= $lowConfCount ?> 个字段置信度较低，请核实后保存
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="mt-3">
        <form method="post" action="<?= url('/import/reject/' . $contract['id']) ?>" style="display:inline;">
            <button type="submit" class="btn btn-danger" onclick="return confirm('确定要驳回此合同吗？')">
                驳回
            </button>
        </form>

        <form method="post" action="<?= url('/import/approve/' . $contract['id']) ?>" style="display:inline;">
            <button type="submit" class="btn btn-success">
                审核通过
            </button>
        </form>
    </div>

    <?php if ($ocrText): ?>
    <div class="card mt-4">
        <div class="card-header">
            <h5>原始 OCR 文本</h5>
        </div>
        <div class="card-body">
            <pre style="max-height: 300px; overflow-y: auto;"><?= e($ocrText) ?></pre>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="<?= asset_url('vendor/echarts/echarts.min.js') ?>"></script>
<script src="<?= asset_url('js/mf-ui.js') ?>"></script>
<script src="<?= asset_url('js/app.js') ?>"></script>
</body>
</html>
