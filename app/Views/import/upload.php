<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>合同批量导入</title>
    <link href="<?= asset_url('vendor/bootstrap-icons/bootstrap-icons.min.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('css/mf-ui.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('css/app.css') ?>" rel="stylesheet">
</head>
<body class="mf-login-page">
<div class="mf-login-cloud">
    <header class="mf-login-cloud__header">
        <div class="mf-login-cloud__header-brand">
            <span class="mf-login-cloud__logo mf-login-cloud__logo--mark" aria-hidden="true">CP</span>
            <span class="mf-login-cloud__header-title">合同管理系统</span>
        </div>
    </header>

    <div class="mf-login-cloud__hero">
        <div class="mf-login-cloud__hero-bg" aria-hidden="true"></div>
        <div class="mf-login-cloud__hero-inner">
            <section class="mf-login-cloud__pitch" aria-labelledby="mf-login-pitch-title">
                <h1 id="mf-login-pitch-title" class="mf-login-cloud__pitch-title">合同批量导入</h1>
                <div class="mf-login-cloud__features">
                    支持 Word、PDF、图片等多种格式，自动识别合同内容
                </div>
            </section>

            <div class="mf-login-cloud__card">
                <h2 class="mf-login-cloud__card-title">选择文件夹</h2>

                <?php if ($notification): ?>
                <div class="alert alert-success">
                    导入完成！成功: <?= $notification['success'] ?> | 待审核: <?= $notification['pending'] ?> | 失败: <?= $notification['failed'] ?>
                </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><?= e($_SESSION['success']) ?></div>
                <?php unset($_SESSION['success']); endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?= e($_SESSION['error']) ?></div>
                <?php unset($_SESSION['error']); endif; ?>

                <form method="post" action="<?= url('/import/process') ?>">
                    <div class="mb-3">
                        <label class="form-label">文件夹路径</label>
                        <input type="text" name="folder_path" class="form-control"
                               placeholder="请输入包含合同文件的文件夹路径，如 D:\contracts"
                               required>
                        <div class="form-text">支持 .doc, .docx, .pdf, .jpg, .png, .webp 格式</div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload"></i> 开始导入
                    </button>

                    <?php if ($pendingCount > 0): ?>
                    <a href="<?= url('/import/review') ?>" class="btn btn-warning">
                        查看待审核合同 (<?= $pendingCount ?>)
                    </a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="mf-login-cloud__card mt-4">
                <h3 class="mf-login-cloud__card-title">导入说明</h3>
                <ul class="text-start">
                    <li>请确保文件夹内的文件格式为支持的格式</li>
                    <li>系统将自动识别合同文本并提取关键字段</li>
                    <li>使用百度 OCR 识别图片和扫描版 PDF</li>
                    <li>使用 DeepSeek 大模型进行语义校验</li>
                    <li>低置信度合同将标记为待审核状态</li>
                    <li>原始文件将保存为合同附件</li>
                </ul>
            </div>

            <div class="mt-4">
                <a href="<?= url('/dashboard') ?>" class="btn btn-secondary">
                    返回首页
                </a>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset_url('vendor/echarts/echarts.min.js') ?>"></script>
<script src="<?= asset_url('js/mf-ui.js') ?>"></script>
<script src="<?= asset_url('js/app.js') ?>"></script>
</body>
</html>
