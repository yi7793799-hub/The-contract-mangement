<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>审核合同</title>
    <link href="<?= asset_url('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('css/mf-ui.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('css/app.css') ?>" rel="stylesheet">
    <style>
        .review-container {
            display: flex;
            gap: 16px;
            min-height: calc(100vh - 120px);
        }
        .review-left {
            flex: 0 0 45%;
            min-width: 300px;
            max-width: 50%;
        }
        .review-right {
            flex: 1;
            min-width: 400px;
        }
        .pdf-preview iframe {
            width: 100%;
            height: 600px;
            border: 1px solid #ebeef5;
            border-radius: 8px;
        }
        .review-actions {
            display: flex;
            gap: 12px;
            margin-top: 16px;
            padding: 12px;
            background: #f5f7fa;
            border-radius: 8px;
        }
        @media (max-width: 991.98px) {
            .review-container {
                flex-direction: column;
            }
            .review-left, .review-right {
                max-width: 100%;
                min-width: 0;
            }
            .pdf-preview iframe {
                height: 400px;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../../includes/layout.php'; ?>

<div class="mf-content">
    <div class="mf-panel mf-mb-3">
        <div class="mf-panel__header mf-flex mf-items-center mf-justify-between">
            <h1 style="font-size: 18px; margin: 0;">审核合同</h1>
            <a href="<?= url('/import/review') ?>" class="mf-btn mf-btn--default mf-btn--sm">返回列表</a>
        </div>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
    <div class="mf-alert mf-alert--danger mf-mb-3"><?= e($_SESSION['error']) ?></div>
    <?php unset($_SESSION['error']); endif; ?>

    <div class="review-container">
        <!-- 左侧：PDF预览 -->
        <div class="review-left">
            <?php if (!empty($files)): ?>
            <div class="mf-panel">
                <div class="mf-panel__header">附件文件预览</div>
                <div class="mf-panel__body">
                    <?php
                    $pdfFile = null;
                    foreach ($files as $file) {
                        $ext = strtolower(pathinfo($file['origin_name'], PATHINFO_EXTENSION));
                        if ($ext === 'pdf') {
                            $pdfFile = $file;
                            break;
                        }
                    }
                    ?>
                    <?php if ($pdfFile): ?>
                    <div class="pdf-preview">
                        <iframe src="<?= asset_url($pdfFile['file_path']) ?>" title="PDF预览"></iframe>
                    </div>
                    <div class="mf-mt-2 mf-small mf-text-muted">
                        <a href="<?= asset_url($pdfFile['file_path']) ?>" target="_blank">
                            <i class="bi bi-download"></i> 下载原文件：<?= e($pdfFile['origin_name']) ?>
                        </a>
                    </div>
                    <?php else: ?>
                    <div class="mf-small mf-text-muted">
                        <?php foreach ($files as $file): ?>
                        <a href="<?= asset_url($file['file_path']) ?>" target="_blank" class="mf-btn mf-btn--default mf-btn--sm mf-mb-1">
                            <i class="bi bi-file-earmark"></i> <?= e($file['origin_name']) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($ocrText): ?>
            <div class="mf-panel mf-mt-3">
                <div class="mf-panel__header">OCR识别文本</div>
                <div class="mf-panel__body">
                    <pre style="max-height: 400px; overflow-y: auto; font-size: 12px; line-height: 1.5; white-space: pre-wrap; word-break: break-all;"><?= e($ocrText) ?></pre>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- 右侧：识别结果表单 -->
        <div class="review-right">
            <div class="mf-panel">
                <div class="mf-panel__header">识别结果</div>
                <div class="mf-panel__body mf-p-0">
                    <table class="mf-table mf-table--border">
                        <thead>
                            <tr>
                                <th style="width: 120px;">字段</th>
                                <th>识别值</th>
                                <th style="width: 80px;">置信度</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $fieldLabels = [
                                'contract_no' => '合同编号',
                                'project_no' => '项目号',
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
                            $lowConfCount = 0;

                            foreach ($fieldLabels as $key => $label):
                                $value = $contract[$key] ?? '';
                                $conf = $fields[$key] ?? null;
                                $confValue = is_numeric($conf) ? (float) $conf : null;

                                if ($confValue !== null && $confValue < $lowThreshold) {
                                    $lowConfCount++;
                                }

                                $confClass = $confValue === null ? '' : ($confValue >= $highThreshold ? 'mf-text-success' : ($confValue >= $lowThreshold ? 'mf-text-secondary' : 'mf-text-danger'));
                            ?>
                            <tr>
                                <td><strong><?= e($label) ?></strong></td>
                                <td><?= e($value) ?: '-' ?></td>
                                <td>
                                    <?php if ($confValue !== null): ?>
                                    <span class="<?= $confClass ?>" style="font-weight: 600;"><?= number_format($confValue, 1) ?>%</span>
                                    <?php else: ?>
                                    <span class="mf-text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($lowConfCount > 0): ?>
            <div class="mf-alert mf-alert--warning mf-mt-3">
                <i class="bi bi-exclamation-triangle"></i> 有 <?= $lowConfCount ?> 个字段置信度较低，请核实后保存
            </div>
            <?php endif; ?>

            <div class="review-actions">
                <form method="post" action="<?= url('/import/reject/' . $contract['id']) ?>" style="display:inline;">
                    <button type="submit" class="mf-btn mf-btn--danger" onclick="return confirm('确定要驳回此合同吗？')">
                        <i class="bi bi-x-lg"></i> 驳回
                    </button>
                </form>
                <form method="post" action="<?= url('/import/approve/' . $contract['id']) ?>" style="display:inline;">
                    <button type="submit" class="mf-btn mf-btn--primary">
                        <i class="bi bi-check-lg"></i> 审核通过
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset_url('js/mf-ui.js') ?>"></script>
<script src="<?= asset_url('js/app.js') ?>"></script>
</body>
</html>