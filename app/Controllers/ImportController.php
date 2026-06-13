<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\ContractImportService;

class ImportController
{
    /** @var ContractImportService */
    private $service;

    public function __construct()
    {
        require_login();
        require_permission('import.view');
        $this->service = new ContractImportService();
    }

    /**
     * 导入上传页
     */
    public function index(): void
    {
        global $pageTitle, $activeNav;
        $pageTitle = '批量导入';
        $activeNav = 'import';

        // 检查是否有通知消息
        $notification = $_SESSION['import_notification'] ?? null;
        unset($_SESSION['import_notification']);
        $pendingCount = $this->getPendingReviewCount();

        ob_start();
        ?>
        <div class="mf-panel">
            <div class="mf-panel__header">
                <h3>合同批量导入</h3>
            </div>
            <div class="mf-panel__body">
                <?php if ($notification): ?>
                <div class="mf-alert mf-alert--success mf-mb-3">
                    导入完成！成功: <?= e((string)$notification['success']) ?> | 待审核: <?= e((string)$notification['pending']) ?> | 失败: <?= e((string)$notification['failed']) ?>
                </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                <div class="mf-alert mf-alert--success mf-mb-3"><?= e($_SESSION['success']) ?></div>
                <?php unset($_SESSION['success']); endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                <div class="mf-alert mf-alert--danger mf-mb-3"><?= e($_SESSION['error']) ?></div>
                <?php unset($_SESSION['error']); endif; ?>

                <!-- 文件上传区域 -->
                <form id="uploadForm" method="post" action="<?= url('import/process.php') ?>" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

                    <div class="mf-form-item">
                        <label class="mf-label">选择合同文件</label>
                        <div id="dropZone" style="
                            border: 2px dashed #dcdfe6;
                            border-radius: 8px;
                            padding: 40px 20px;
                            text-align: center;
                            background: #fafafa;
                            cursor: pointer;
                            transition: all 0.3s ease;
                        ">
                            <i class="bi bi-cloud-upload" style="font-size: 48px; color: #c0c4cc;"></i>
                            <p style="margin: 16px 0 8px; color: #606266; font-size: 16px;">
                                点击选择文件或拖拽文件到此处
                            </p>
                            <p style="margin: 0; color: #909399; font-size: 13px;">
                                支持 .doc, .docx, .pdf, .jpg, .png, .webp 格式，可多选
                            </p>
                            <input type="file" name="files[]" id="fileInput" multiple accept=".doc,.docx,.pdf,.jpg,.jpeg,.png,.webp" style="display: none;">
                        </div>
                    </div>

                    <!-- 已选择文件列表 -->
                    <div id="fileList" class="mf-mt-3" style="display: none;">
                        <div class="mf-form-item">
                            <label class="mf-label">已选择的文件 (<span id="fileCount">0</span> 个)</label>
                            <div id="fileListContent" style="max-height: 200px; overflow-y: auto; border: 1px solid #e4e7ed; border-radius: 4px;">
                            </div>
                        </div>
                    </div>

                    <div class="mf-form-item mf-mt-3">
                        <button type="submit" class="mf-btn mf-btn--primary" id="submitBtn" disabled>
                            <i class="bi bi-upload"></i> 开始导入
                        </button>

                        <a href="<?= url('import/review.php') ?>" class="mf-btn mf-btn--warning">
                            查看待审核合同 <?php if ($pendingCount > 0): ?> (<?= $pendingCount ?>) <?php endif; ?>
                        </a>
                    </div>
                </form>

                <div class="mf-panel mf-mt-3" style="background:#f8f9fa;">
                    <div class="mf-panel__header">导入说明</div>
                    <div class="mf-panel__body">
                        <ul style="margin:0;padding-left:1.5em;line-height:2;">
                            <li>支持 Word、PDF、图片等多种格式</li>
                            <li>系统将自动识别合同文本并提取关键字段</li>
                            <li>使用 SiliconFlow OCR 识别图片和扫描版 PDF</li>
                            <li>使用 DeepSeek 大模型进行语义校验</li>
                            <li>低置信度合同将标记为待审核状态</li>
                            <li>原始文件将保存为合同附件</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <style>
        #dropZone:hover, #dropZone.drag-over {
            border-color: #6366f1;
            background: #f0f2ff;
        }
        #dropZone:hover .bi, #dropZone.drag-over .bi {
            color: #6366f1;
        }
        .file-item {
            display: flex;
            align-items: center;
            padding: 10px 12px;
            border-bottom: 1px solid #ebeef5;
            background: #fff;
        }
        .file-item:last-child {
            border-bottom: none;
        }
        .file-item:hover {
            background: #f5f7fa;
        }
        .file-item .file-icon {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #e8f4f8;
            border-radius: 4px;
            margin-right: 12px;
            color: #409eff;
        }
        .file-item .file-name {
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 14px;
        }
        .file-item .file-size {
            color: #909399;
            font-size: 12px;
            margin-left: 12px;
        }
        .file-item .file-remove {
            margin-left: 12px;
            color: #f56c6c;
            cursor: pointer;
            padding: 4px;
        }
        .file-item .file-remove:hover {
            color: #f56c6c;
        }
        </style>

        <style>
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        </style>

        <script>
        (function() {
            var dropZone = document.getElementById('dropZone');
            var fileInput = document.getElementById('fileInput');
            var fileList = document.getElementById('fileList');
            var fileListContent = document.getElementById('fileListContent');
            var fileCount = document.getElementById('fileCount');
            var submitBtn = document.getElementById('submitBtn');
            var selectedFiles = [];

            // 点击上传区域触发文件选择
            dropZone.addEventListener('click', function() {
                fileInput.click();
            });

            // 拖拽效果
            dropZone.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.classList.add('drag-over');
            });

            dropZone.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.classList.remove('drag-over');
            });

            dropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                this.classList.remove('drag-over');
                handleFiles(e.dataTransfer.files);
            });

            // 文件选择变化
            fileInput.addEventListener('change', function() {
                handleFiles(this.files);
            });

            function handleFiles(files) {
                var allowedExts = ['doc', 'docx', 'pdf', 'jpg', 'jpeg', 'png', 'webp'];

                for (var i = 0; i < files.length; i++) {
                    var file = files[i];
                    var ext = file.name.split('.').pop().toLowerCase();

                    if (allowedExts.indexOf(ext) === -1) {
                        continue;
                    }

                    // 检查是否已存在
                    var exists = selectedFiles.some(function(f) {
                        return f.name === file.name && f.size === file.size;
                    });

                    if (!exists) {
                        selectedFiles.push(file);
                    }
                }

                updateFileList();
            }

            function updateFileList() {
                if (selectedFiles.length === 0) {
                    fileList.style.display = 'none';
                    submitBtn.disabled = true;
                    return;
                }

                fileList.style.display = 'block';
                fileCount.textContent = selectedFiles.length;
                submitBtn.disabled = false;

                var html = '';
                var icons = {
                    'pdf': 'bi-file-pdf',
                    'doc': 'bi-file-word',
                    'docx': 'bi-file-word',
                    'jpg': 'bi-file-image',
                    'jpeg': 'bi-file-image',
                    'png': 'bi-file-image',
                    'webp': 'bi-file-image'
                };

                for (var i = 0; i < selectedFiles.length; i++) {
                    var file = selectedFiles[i];
                    var ext = file.name.split('.').pop().toLowerCase();
                    var icon = icons[ext] || 'bi-file';
                    var size = formatSize(file.size);

                    html += '<div class="file-item" data-index="' + i + '">';
                    html += '<div class="file-icon"><i class="bi ' + icon + '"></i></div>';
                    html += '<div class="file-name" title="' + escapeHtml(file.name) + '">' + escapeHtml(file.name) + '</div>';
                    html += '<div class="file-size">' + size + '</div>';
                    html += '<span class="file-remove" onclick="removeFile(' + i + ')"><i class="bi bi-x-lg"></i></span>';
                    html += '</div>';
                }

                fileListContent.innerHTML = html;
            }

            window.removeFile = function(index) {
                selectedFiles.splice(index, 1);
                updateFileList();
            };

            function formatSize(bytes) {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                return (bytes / 1024 / 1024).toFixed(1) + ' MB';
            }

            function escapeHtml(str) {
                var div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            // 表单提交
            document.getElementById('uploadForm').addEventListener('submit', function(e) {
                if (selectedFiles.length === 0) {
                    e.preventDefault();
                    alert('请选择要导入的文件');
                    return;
                }

                // 计算总文件大小
                var totalSize = 0;
                var maxFileSize = 500 * 1024 * 1024; // 500MB 单文件限制
                var maxTotalSize = 500 * 1024 * 1024; // 500MB 总大小限制
                var oversizedFiles = [];

                for (var i = 0; i < selectedFiles.length; i++) {
                    totalSize += selectedFiles[i].size;
                    if (selectedFiles[i].size > maxFileSize) {
                        oversizedFiles.push(selectedFiles[i].name + ' (' + formatSize(selectedFiles[i].size) + ')');
                    }
                }

                // 检查单个文件是否超限
                if (oversizedFiles.length > 0) {
                    e.preventDefault();
                    alert('以下文件超过 500MB 限制，无法上传：\n\n' + oversizedFiles.join('\n') + '\n\n请压缩文件或拆分后重试。');
                    return;
                }

                // 检查总大小是否超限
                if (totalSize > maxTotalSize) {
                    e.preventDefault();
                    alert('文件总大小 ' + formatSize(totalSize) + ' 超过 500MB 限制。\n\n请减少文件数量或压缩后重试。');
                    return;
                }

                // 大文件警告（超过 100MB 提示）
                if (totalSize > 100 * 1024 * 1024) {
                    if (!confirm('您选择的文件总大小约 ' + formatSize(totalSize) + '，处理可能需要较长时间。\n\n确定继续上传吗？')) {
                        return;
                    }
                }

                // 创建新的 FormData
                var formData = new FormData();
                formData.append('csrf', document.querySelector('input[name="csrf"]').value);

                for (var i = 0; i < selectedFiles.length; i++) {
                    formData.append('files[]', selectedFiles[i]);
                }

                // 使用 AJAX 上传
                e.preventDefault();
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> 正在上传...';

                // 显示进度区域
                var progressArea = document.getElementById('progressArea');
                if (!progressArea) {
                    progressArea = document.createElement('div');
                    progressArea.id = 'progressArea';
                    progressArea.style.marginTop = '16px';
                    document.getElementById('uploadForm').appendChild(progressArea);
                }
                progressArea.innerHTML = '<div style="padding:16px;background:#f8f9fa;border-radius:8px;"><div style="margin-bottom:8px;"><span id="uploadStatus">正在上传文件...</span></div><div style="background:#e9ecef;border-radius:4px;height:8px;"><div id="uploadProgressBar" style="background:#6366f1;height:100%;border-radius:4px;width:0%;transition:width 0.3s;"></div></div></div>';

                var xhr = new XMLHttpRequest();
                xhr.open('POST', '<?= url('import/process-files.php') ?>');

                // 上传超时设置为5分钟（上传完成后进入轮询模式）
                xhr.timeout = 300000;

                // 上传进度
                xhr.upload.onprogress = function(e) {
                    if (e.lengthComputable) {
                        var percent = Math.round((e.loaded / e.total) * 100);
                        document.getElementById('uploadProgressBar').style.width = percent + '%';
                        document.getElementById('uploadStatus').textContent = '正在上传... ' + percent + '% (' + formatSize(e.loaded) + ' / ' + formatSize(e.total) + ')';
                    }
                };

                xhr.onload = function() {
                    console.log('Response:', xhr.responseText);
                    if (xhr.status === 200) {
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.need_login) {
                                alert('登录已过期，请重新登录');
                                window.location.href = '<?= url('login.php') ?>';
                            } else if (resp.error) {
                                var errorMsg = resp.error;
                                if (resp.details && resp.details.length > 0) {
                                    errorMsg += '<br><small>' + escapeHtml(resp.details.join('; ')) + '</small>';
                                }
                                progressArea.innerHTML = '<div class="mf-alert mf-alert--danger"><i class="bi bi-exclamation-circle"></i> ' + errorMsg + '</div>';
                                submitBtn.disabled = false;
                                submitBtn.innerHTML = '<i class="bi bi-upload"></i> 开始导入';
                            } else if (resp.success && resp.job_id) {
                                // 异步模式：开始轮询进度
                                startProgressPolling(resp.job_id, resp.total_files || selectedFiles.length);
                            } else if (resp.redirect) {
                                // 兼容旧模式
                                document.getElementById('uploadStatus').textContent = '导入完成，正在跳转...';
                                window.location.href = resp.redirect;
                            } else if (resp.success) {
                                document.getElementById('uploadStatus').innerHTML = '<span style="color:#28a745;"><i class="bi bi-check-circle"></i> 导入完成！</span>';
                                document.getElementById('uploadProgressBar').style.width = '100%';
                                document.getElementById('uploadProgressBar').style.background = '#28a745';
                                setTimeout(function() {
                                    window.location.href = '<?= url('import/review.php') ?>';
                                }, 1000);
                            }
                        } catch (ex) {
                            console.error('Parse error:', ex);
                            progressArea.innerHTML = '<div class="mf-alert mf-alert--danger">服务器响应格式错误：' + escapeHtml(xhr.responseText.substring(0, 200)) + '</div>';
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = '<i class="bi bi-upload"></i> 开始导入';
                        }
                    } else {
                        progressArea.innerHTML = '<div class="mf-alert mf-alert--danger">上传失败 (HTTP ' + xhr.status + ')，请重试</div>';
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="bi bi-upload"></i> 开始导入';
                    }
                };

                xhr.onerror = function() {
                    progressArea.innerHTML = '<div class="mf-alert mf-alert--danger">网络错误，请重试</div>';
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-upload"></i> 开始导入';
                };

                xhr.ontimeout = function() {
                    progressArea.innerHTML = '<div class="mf-alert mf-alert--warning"><i class="bi bi-clock"></i> 上传超时，请检查网络连接后重试</div>';
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bi bi-upload"></i> 开始导入';
                };

                xhr.send(formData);
            });

            // 进度轮询函数
            function startProgressPolling(jobId, totalFiles) {
                var pollInterval = 2000; // 2秒轮询一次
                var maxPolls = 1800; // 最多轮询1小时（1800 * 2秒）
                var pollCount = 0;

                // 更新UI显示处理中状态
                var progressArea = document.getElementById('progressArea');
                progressArea.innerHTML = '\
                    <div style="padding:16px;background:#f8f9fa;border-radius:8px;">\
                        <div style="margin-bottom:8px;">\
                            <span id="processStatus">正在处理文件...</span>\
                            <span id="processCount" style="float:right;color:#6c757d;"></span>\
                        </div>\
                        <div style="background:#e9ecef;border-radius:4px;height:12px;margin-bottom:12px;">\
                            <div id="processProgressBar" style="background:#6366f1;height:100%;border-radius:4px;width:0%;transition:width 0.3s;"></div>\
                        </div>\
                        <div id="processDetails" style="font-size:13px;color:#6c757d;max-height:150px;overflow-y:auto;"></div>\
                    </div>';

                var progressBar = document.getElementById('processProgressBar');
                var statusText = document.getElementById('processStatus');
                var countText = document.getElementById('processCount');
                var detailsDiv = document.getElementById('processDetails');

                function poll() {
                    pollCount++;
                    if (pollCount > maxPolls) {
                        statusText.innerHTML = '<span style="color:#dc3545;"><i class="bi bi-exclamation-triangle"></i> 轮询超时，请刷新页面查看状态</span>';
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="bi bi-upload"></i> 开始导入';
                        return;
                    }

                    var xhr = new XMLHttpRequest();
                    xhr.open('GET', '<?= url('api/import-status.php') ?>?job_id=' + jobId, true);
                    xhr.timeout = 10000; // 10秒超时

                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            try {
                                var data = JSON.parse(xhr.responseText);

                                if (data.error) {
                                    statusText.innerHTML = '<span style="color:#dc3545;"><i class="bi bi-exclamation-circle"></i> ' + escapeHtml(data.error) + '</span>';
                                    submitBtn.disabled = false;
                                    submitBtn.innerHTML = '<i class="bi bi-upload"></i> 开始导入';
                                    return;
                                }

                                var job = data.job || {};
                                var progress = data.progress || 0;
                                var processed = data.processed || 0;

                                // 更新进度条
                                progressBar.style.width = progress + '%';
                                countText.textContent = processed + ' / ' + (job.total_files || totalFiles);

                                // 更新状态文字
                                var statusHtml = '';
                                if (job.status === 'pending') {
                                    statusHtml = '<i class="bi bi-hourglass"></i> 等待处理...';
                                } else if (job.status === 'processing') {
                                    statusHtml = '<i class="bi bi-gear-fill" style="animation:spin 1s linear infinite;"></i> 正在处理... ' + progress.toFixed(1) + '%';
                                } else if (job.status === 'completed') {
                                    // 处理完成
                                    progressBar.style.background = '#28a745';
                                    statusHtml = '<span style="color:#28a745;"><i class="bi bi-check-circle-fill"></i> 处理完成！</span>';

                                    // 显示统计
                                    var stats = [];
                                    if (job.success_count > 0) stats.push('成功 ' + job.success_count);
                                    if (job.pending_count > 0) stats.push('待审核 ' + job.pending_count);
                                    if (job.failed_count > 0) stats.push('<span style="color:#dc3545;">失败 ' + job.failed_count + '</span>');

                                    detailsDiv.innerHTML = '<strong>结果：</strong>' + stats.join(' | ');

                                    // 2秒后跳转
                                    setTimeout(function() {
                                        window.location.href = '<?= url('import/review.php') ?>';
                                    }, 2000);
                                    return; // 停止轮询
                                } else if (job.status === 'failed') {
                                    progressBar.style.background = '#dc3545';
                                    statusHtml = '<span style="color:#dc3545;"><i class="bi bi-x-circle-fill"></i> 处理失败</span>';
                                    submitBtn.disabled = false;
                                    submitBtn.innerHTML = '<i class="bi bi-upload"></i> 开始导入';
                                    return;
                                }

                                statusText.innerHTML = statusHtml;

                                // 显示最近处理的文件
                                if (data.files && data.files.length > 0) {
                                    var filesHtml = '';
                                    var recentFiles = data.files.slice(0, 5);
                                    for (var i = 0; i < recentFiles.length; i++) {
                                        var f = recentFiles[i];
                                        var icon = f.status === 'success' ? '✓' : (f.status === 'failed' ? '✗' : '…');
                                        var color = f.status === 'success' ? '#28a745' : (f.status === 'failed' ? '#dc3545' : '#6c757d');
                                        filesHtml += '<div style="padding:4px 0;border-bottom:1px solid #eee;">\
                                            <span style="color:' + color + ';">' + icon + '</span> ' + escapeHtml(f.file_name) +
                                            (f.confidence ? ' <small style="color:#999;">(' + f.confidence + '%)</small>' : '') +
                                            (f.error_message ? ' <small style="color:#dc3545;">' + escapeHtml(f.error_message) + '</small>' : '') +
                                        '</div>';
                                    }
                                    detailsDiv.innerHTML = filesHtml;
                                }

                                // 继续轮询
                                setTimeout(poll, pollInterval);

                            } catch (ex) {
                                console.error('Poll parse error:', ex);
                                setTimeout(poll, pollInterval);
                            }
                        } else {
                            console.error('Poll HTTP error:', xhr.status);
                            setTimeout(poll, pollInterval);
                        }
                    };

                    xhr.onerror = function() {
                        console.error('Poll network error');
                        setTimeout(poll, pollInterval);
                    };

                    xhr.ontimeout = function() {
                        console.error('Poll timeout');
                        setTimeout(poll, pollInterval);
                    };

                    xhr.send();
                }

                // 开始轮询
                setTimeout(poll, 1000);
            }
        })();
        </script>
        <?php
        $content = ob_get_clean();
        require dirname(__DIR__, 2) . '/includes/layout.php';
    }

    /**
     * 处理导入请求
     */
    public function process(): void
    {
        require_permission('import.create');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/import.php');
        }

        $folderPath = $_POST['folder_path'] ?? '';

        if (empty($folderPath) || !is_dir($folderPath)) {
            $_SESSION['error'] = '请选择有效的文件夹';
            redirect('/import.php');
        }

        // 路径白名单验证
        $realPath = realpath($folderPath);
        $config = import_config();
        $allowedPaths = $config['allowed_paths'] ?? [];
        $allowed = false;
        foreach ($allowedPaths as $allowedPath) {
            if (strpos($realPath, realpath($allowedPath)) === 0) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            $_SESSION['error'] = '该路径不在允许导入的目录范围内';
            redirect('/import.php');
        }

        $admin = current_admin();
        $adminId = $admin['id'] ?? 0;
        if ($adminId <= 0) {
            $_SESSION['error'] = '请先登录';
            redirect('/login.php');
        }
        $jobId = $this->service->processFolder($folderPath, $adminId);

        $_SESSION['success'] = '导入任务已启动，请在待审核页面查看结果';
        redirect('/import.php');
    }

    /**
     * 待审核列表页
     */
    public function reviewList(): void
    {
        global $pageTitle, $activeNav;
        $pageTitle = '待审核合同';
        $activeNav = 'import';

        require_permission('import.review');

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;

        $contracts = $this->getPendingContracts($page, $perPage);
        $total = $this->getPendingContractsCount();
        $totalPages = ceil($total / $perPage);

        ob_start();
        ?>
        <div class="mf-panel">
            <div class="mf-panel__header">
                <h3>📥 待审核合同列表</h3>
                <div class="mf-panel__actions">
                    <a href="<?= url('import.php') ?>" class="mf-btn mf-btn--default">
                        <i class="bi bi-arrow-left"></i> 返回导入
                    </a>
                </div>
            </div>

            <!-- 页面说明 -->
            <div style="background:#fff3cd;padding:12px 16px;border-bottom:1px solid #ffeeba;">
                <strong>📋 页面说明：</strong>此处显示通过批量导入识别的合同，由于置信度较低（&lt;85%），需要人工审核确认。
                <br><small style="color:#856404;">请核对合同信息，确认无误后点击"审核通过"，或修改信息后保存。</small>
            </div>

            <div class="mf-panel__body">
                <!-- 统计信息 -->
                <div class="mf-row mf-mb-3">
                    <div class="mf-col-md-4">
                        <div style="background:#f8f9fa;padding:15px;border-radius:4px;text-align:center;">
                            <div style="font-size:24px;color:#17a2b8;"><?= e((string)$total) ?></div>
                            <div style="color:#6c757d;">待审核总数</div>
                        </div>
                    </div>
                    <div class="mf-col-md-4">
                        <div style="background:#f8f9fa;padding:15px;border-radius:4px;text-align:center;">
                            <div style="font-size:24px;color:#28a745;"><?= e((string)$this->getApprovedCount()) ?></div>
                            <div style="color:#6c757d;">已通过</div>
                        </div>
                    </div>
                    <div class="mf-col-md-4">
                        <div style="background:#f8f9fa;padding:15px;border-radius:4px;text-align:center;">
                            <div style="font-size:24px;color:#dc3545;"><?= e((string)$this->getRejectedCount()) ?></div>
                            <div style="color:#6c757d;">已驳回</div>
                        </div>
                    </div>
                </div>

                <?php if (isset($_SESSION['success'])): ?>
                <div class="mf-alert mf-alert--success mf-mb-3">
                    <i class="bi bi-check-circle"></i> <?= e($_SESSION['success']) ?>
                </div>
                <?php unset($_SESSION['success']); endif; ?>

                <?php if (empty($contracts)): ?>
                <div class="mf-empty-state" style="padding:40px;text-align:center;">
                    <i class="bi bi-inbox" style="font-size:64px;color:#c0c4cc;"></i>
                    <p style="margin-top:16px;color:#909399;font-size:16px;">暂无待审核合同</p>
                    <p style="color:#c0c4cc;font-size:14px;">所有导入的合同都已审核完毕</p>
                </div>
                <?php else: ?>

                <!-- 操作提示 -->
                <div style="background:#e7f5ff;padding:10px 16px;border-radius:4px;margin-bottom:16px;">
                    <i class="bi bi-info-circle" style="color:#0066cc;"></i>
                    <strong>操作提示：</strong>
                    <span style="color:#495057;">勾选合同后可批量操作，或点击"审核"进入详情页修改信息。</span>
                </div>

                <form id="batchForm" method="post" action="<?= url('import/batch-approve.php') ?>">
                    <div class="mf-table-wrap">
                        <table class="mf-table" style="width:100%;">
                            <thead>
                                <tr style="background:#f5f7fa;">
                                    <th style="width:40px;"><input type="checkbox" id="selectAll"></th>
                                    <th style="width:180px;">合同编号</th>
                                    <th>合同名称</th>
                                    <th style="width:150px;">客户名称</th>
                                    <th style="width:100px;">金额</th>
                                    <th style="width:100px;">置信度</th>
                                    <th style="width:160px;">导入时间</th>
                                    <th style="width:80px;">操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contracts as $c): ?>
                                <tr>
                                    <td><input type="checkbox" name="ids[]" value="<?= e((string)$c['id']) ?>"></td>
                                    <td>
                                        <code style="background:#f5f5f5;padding:2px 6px;border-radius:3px;">
                                            <?= e($c['contract_no'] ?? '-') ?>
                                        </code>
                                    </td>
                                    <td>
                                        <?php if (empty($c['contract_name'])): ?>
                                        <span style="color:#dc3545;"><i class="bi bi-exclamation-triangle"></i> 未识别</span>
                                        <?php else: ?>
                                        <?= e($c['contract_name']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (empty($c['customer_name'])): ?>
                                        <span style="color:#dc3545;"><i class="bi bi-exclamation-triangle"></i> 未识别</span>
                                        <?php else: ?>
                                        <?= e($c['customer_name']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <?php if (!empty($c['amount'])): ?>
                                        <span style="color:#28a745;font-weight:500;">¥<?= number_format((float)$c['amount'], 2) ?></span>
                                        <?php else: ?>
                                        <span style="color:#999;">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:center;">
                                        <?php
                                        $conf = (float) ($c['import_confidence'] ?? 0);
                                        $badgeClass = $conf >= 85 ? 'mf-badge--success' : ($conf >= 60 ? 'mf-badge--warning' : 'mf-badge--danger');
                                        ?>
                                        <span class="mf-badge <?= $badgeClass ?>"><?= number_format($conf, 0) ?>%</span>
                                    </td>
                                    <td style="color:#6c757d;font-size:13px;">
                                        <?= e($c['created_at'] ?? '-') ?>
                                    </td>
                                    <td>
                                        <a href="<?= url('import/review/detail.php?id=' . $c['id']) ?>"
                                           class="mf-btn mf-btn--sm mf-btn--primary"
                                           title="查看详情并审核">
                                            <i class="bi bi-eye"></i> 审核
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mf-flex mf-items-center mf-gap-2 mf-mt-3">
                        <button type="button" class="mf-btn mf-btn--success" onclick="batchApprove()">
                            <i class="bi bi-check-lg"></i> 批量通过
                        </button>
                        <button type="button" class="mf-btn mf-btn--danger" onclick="batchReject()">
                            <i class="bi bi-x-lg"></i> 批量驳回
                        </button>
                        <span style="color:#6c757d;margin-left:16px;">
                            <i class="bi bi-lightbulb"></i> 提示：批量通过将直接确认所有选中的合同
                        </span>
                    </div>
                </form>

                <?php if ($totalPages > 1): ?>
                <div class="mf-pagination mf-mt-3">
                    <span style="color:#6c757d;">共 <?= e((string)$total) ?> 条，</span>
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="mf-pagination__item active"><?= e((string)$i) ?></span>
                        <?php else: ?>
                            <a href="?page=<?= e((string)$i) ?>" class="mf-pagination__item"><?= e((string)$i) ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <script>
        document.getElementById('selectAll')?.addEventListener('change', function() {
            document.querySelectorAll('input[name="ids[]"]').forEach(cb => cb.checked = this.checked);
        });
        function batchApprove() {
            var checked = document.querySelectorAll('input[name="ids[]"]:checked');
            if (checked.length === 0) {
                alert('请先勾选要审核的合同');
                return;
            }
            if (confirm('确定批量通过 ' + checked.length + ' 个合同吗？\n\n注意：批量通过将直接确认所有选中合同，建议逐个审核以确保信息准确。')) {
                document.getElementById('batchForm').action = '<?= url('import/batch-approve.php') ?>';
                document.getElementById('batchForm').submit();
            }
        }
        function batchReject() {
            var checked = document.querySelectorAll('input[name="ids[]"]:checked');
            if (checked.length === 0) {
                alert('请先勾选要驳回的合同');
                return;
            }
            if (confirm('确定批量驳回 ' + checked.length + ' 个合同吗？\n\n注意：驳回后合同将被删除，此操作不可恢复。')) {
                document.getElementById('batchForm').action = '<?= url('import/batch-reject.php') ?>';
                document.getElementById('batchForm').submit();
            }
        }
        </script>
        <?php
        $content = ob_get_clean();
        require dirname(__DIR__, 2) . '/includes/layout.php';
    }

    /**
     * 审核详情页
     */
    public function reviewDetail(int $id): void
    {
        global $pageTitle, $activeNav;
        $pageTitle = '审核合同';
        $activeNav = 'import';

        require_permission('import.review');

        $contract = $this->getContractForReview($id);

        if (!$contract) {
            $_SESSION['error'] = '合同不存在';
            redirect('/import/review.php');
        }

        // 获取识别字段及其置信度
        $fields = json_decode($contract['import_fields'] ?? '', true) ?? [];
        $ocrText = $contract['ocr_raw_text'] ?? '';

        // 获取附件
        $files = $this->getContractFiles($id);

        // 获取合同类型列表
        $types = $this->getContractTypes();

        ob_start();
        ?>
        <div class="mf-panel">
            <div class="mf-panel__header">
                <h3>合同审核详情</h3>
                <div class="mf-panel__actions">
                    <a href="<?= url('import/review.php') ?>" class="mf-btn mf-btn--default">
                        <i class="bi bi-arrow-left"></i> 返回列表
                    </a>
                </div>
            </div>
            <div class="mf-panel__body">
                <div class="mf-row">
                    <div class="mf-col-md-6">
                        <h4>合同信息（可编辑）</h4>
                        <form id="editForm" method="post" action="<?= url('import/update.do.php') ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

                            <div class="mf-form-item">
                                <label class="mf-label">合同编号</label>
                                <input type="text" name="contract_no" class="mf-input" value="<?= e($contract['contract_no'] ?? '') ?>">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">合同名称 <span class="mf-text-danger">*</span></label>
                                <input type="text" name="contract_name" class="mf-input" value="<?= e($contract['contract_name'] ?? '') ?>" required>
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">客户名称 <span class="mf-text-danger">*</span></label>
                                <input type="text" name="customer_name" class="mf-input" value="<?= e($contract['customer_name'] ?? '') ?>" required>
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">签约方</label>
                                <input type="text" name="signer_party" class="mf-input" value="<?= e($contract['signer_party'] ?? '') ?>">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">签约人</label>
                                <input type="text" name="signer_name" class="mf-input" value="<?= e($contract['signer_name'] ?? '') ?>">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">联系电话</label>
                                <input type="text" name="phone" class="mf-input" value="<?= e($contract['phone'] ?? '') ?>">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">合同金额</label>
                                <input type="number" name="amount" class="mf-input" value="<?= e($contract['amount'] ?? 0) ?>" step="0.01">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">签订日期</label>
                                <input type="date" name="signed_date" class="mf-input" value="<?= e($contract['signed_date'] ?? '') ?>">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">生效日期</label>
                                <input type="date" name="effective_date" class="mf-input" value="<?= e($contract['effective_date'] ?? '') ?>">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">截止日期</label>
                                <input type="date" name="expiry_date" class="mf-input" value="<?= e($contract['expiry_date'] ?? '') ?>">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">合同类型</label>
                                <select name="type_id" class="mf-input">
                                    <option value="">-- 请选择 --</option>
                                    <?php foreach ($types as $t): ?>
                                    <option value="<?= e($t['id']) ?>" <?= ($contract['type_id'] ?? '') == $t['id'] ? 'selected' : '' ?>>
                                        <?= e($t['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">款项类型</label>
                                <select name="payment_type" class="mf-input">
                                    <option value="receipt" <?= ($contract['payment_type'] ?? 'receipt') === 'receipt' ? 'selected' : '' ?>>收款</option>
                                    <option value="payment" <?= ($contract['payment_type'] ?? '') === 'payment' ? 'selected' : '' ?>>付款</option>
                                </select>
                            </div>

                            <div class="mf-flex mf-gap-2 mf-mt-3">
                                <button type="submit" class="mf-btn mf-btn--primary">
                                    <i class="bi bi-save"></i> 保存修改
                                </button>
                                <a href="<?= url('import/approve/do.php?id=' . $id) ?>" class="mf-btn mf-btn--success" onclick="return confirm('保存并审核通过？')">
                                    <i class="bi bi-check-lg"></i> 保存并通过
                                </a>
                            </div>
                        </form>
                    </div>
                    <div class="mf-col-md-6">
                        <h4>OCR 识别结果</h4>
                        <?php if (!empty($fields)): ?>
                        <table class="mf-table mf-table--sm">
                            <thead>
                                <tr>
                                    <th>字段</th>
                                    <th>置信度</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fields as $name => $conf): ?>
                                <?php if (is_numeric($conf)): ?>
                                <tr>
                                    <td><?= e($name) ?></td>
                                    <td>
                                        <?php
                                        $confVal = (float) $conf;
                                        $badgeClass = $confVal >= 85 ? 'mf-badge--success' : ($confVal >= 60 ? 'mf-badge--warning' : 'mf-badge--danger');
                                        ?>
                                        <span class="mf-badge <?= $badgeClass ?>"><?= number_format($confVal, 0) ?>%</span>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p class="mf-text-muted">无置信度数据</p>
                        <?php endif; ?>

                        <h4 class="mf-mt-3">原始OCR文本</h4>
                        <div style="background:#f5f5f5;padding:12px;border-radius:4px;max-height:250px;overflow:auto;font-size:12px;">
                            <pre style="white-space:pre-wrap;word-break:break-all;margin:0;"><?= e($ocrText) ?></pre>
                        </div>

                        <h4 class="mf-mt-3">附件文件</h4>
                        <?php if (empty($files)): ?>
                        <p class="mf-text-muted">无附件</p>
                        <?php else: ?>
                        <ul class="mf-list">
                            <?php foreach ($files as $file): ?>
                            <li>
                                <i class="bi bi-file-earmark-pdf"></i>
                                <?= e($file['origin_name'] ?? $file['file_name']) ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>

                        <div class="mf-mt-3">
                            <a href="<?= url('import/reject/do.php?id=' . $id) ?>" class="mf-btn mf-btn--danger" onclick="return confirm('确定要驳回删除此合同吗？');">
                                <i class="bi bi-x-lg"></i> 驳回删除
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // 保存并通过：先保存再通过
        document.querySelector('a[href*="approve/do"]').addEventListener('click', function(e) {
            e.preventDefault();
            var form = document.getElementById('editForm');
            var href = this.href;
            form.action = '<?= url('import/update-approve.do.php') ?>';
            form.submit();
        });
        </script>
        <?php
        $content = ob_get_clean();
        require dirname(__DIR__, 2) . '/includes/layout.php';
    }

    /**
     * 审核通过
     */
    public function approve(int $id): void
    {
        require_permission('import.review.edit');

        $stmt = db()->prepare("UPDATE contracts SET status = 'ongoing' WHERE id = ? AND status = 'pending_review'");
        $stmt->execute([$id]);

        $_SESSION['success'] = '合同已审核通过';
        redirect('/import/review.php');
    }

    /**
     * 更新合同信息
     */
    public function update(): void
    {
        require_permission('import.review.edit');

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = '无效的合同ID';
            redirect('/import/review.php');
        }

        if (!csrf_verify($_POST['csrf'] ?? '')) {
            $_SESSION['error'] = '会话已过期';
            redirect('/import/review/detail.php?id=' . $id);
        }

        $stmt = db()->prepare(
            "UPDATE contracts SET
                contract_no = ?, contract_name = ?, customer_name = ?, signer_party = ?,
                signer_name = ?, phone = ?, amount = ?, signed_date = ?, effective_date = ?,
                expiry_date = ?, type_id = ?, payment_type = ?
            WHERE id = ? AND status = 'pending_review'"
        );

        $stmt->execute([
            trim($_POST['contract_no'] ?? ''),
            trim($_POST['contract_name'] ?? ''),
            trim($_POST['customer_name'] ?? ''),
            trim($_POST['signer_party'] ?? ''),
            trim($_POST['signer_name'] ?? ''),
            trim($_POST['phone'] ?? ''),
            (float) ($_POST['amount'] ?? 0),
            $_POST['signed_date'] ?? null,
            $_POST['effective_date'] ?? null,
            $_POST['expiry_date'] ?? null,
            (int) ($_POST['type_id'] ?? 0) ?: null,
            $_POST['payment_type'] ?? 'receipt',
            $id,
        ]);

        $_SESSION['success'] = '合同信息已更新';
        redirect('/import/review/detail.php?id=' . $id);
    }

    /**
     * 更新并审核通过
     */
    public function updateAndApprove(): void
    {
        require_permission('import.review.edit');

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = '无效的合同ID';
            redirect('/import/review.php');
        }

        if (!csrf_verify($_POST['csrf'] ?? '')) {
            $_SESSION['error'] = '会话已过期';
            redirect('/import/review/detail.php?id=' . $id);
        }

        // 先更新
        $stmt = db()->prepare(
            "UPDATE contracts SET
                contract_no = ?, contract_name = ?, customer_name = ?, signer_party = ?,
                signer_name = ?, phone = ?, amount = ?, signed_date = ?, effective_date = ?,
                expiry_date = ?, type_id = ?, payment_type = ?, status = 'ongoing'
            WHERE id = ? AND status = 'pending_review'"
        );

        $stmt->execute([
            trim($_POST['contract_no'] ?? ''),
            trim($_POST['contract_name'] ?? ''),
            trim($_POST['customer_name'] ?? ''),
            trim($_POST['signer_party'] ?? ''),
            trim($_POST['signer_name'] ?? ''),
            trim($_POST['phone'] ?? ''),
            (float) ($_POST['amount'] ?? 0),
            $_POST['signed_date'] ?? null,
            $_POST['effective_date'] ?? null,
            $_POST['expiry_date'] ?? null,
            (int) ($_POST['type_id'] ?? 0) ?: null,
            $_POST['payment_type'] ?? 'receipt',
            $id,
        ]);

        $_SESSION['success'] = '合同已更新并审核通过';
        redirect('/import/review.php');
    }

    /**
     * 审核驳回
     */
    public function reject(int $id): void
    {
        require_permission('import.review.edit');

        // 删除合同及其附件
        $this->deleteContract($id);

        $_SESSION['success'] = '合同已驳回';
        redirect('/import/review.php');
    }

    /**
     * 批量审核通过
     */
    public function batchApprove(): void
    {
        require_permission('import.review.edit');

        $ids = $_POST['ids'] ?? [];

        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("UPDATE contracts SET status = 'ongoing' WHERE id IN ({$placeholders}) AND status = 'pending_review'");
            $stmt->execute($ids);
        }

        $_SESSION['success'] = '已批量通过 ' . count($ids) . ' 个合同';
        redirect('/import/review.php');
    }

    /**
     * 批量驳回
     */
    public function batchReject(): void
    {
        require_permission('import.review.edit');

        $ids = $_POST['ids'] ?? [];

        if (!empty($ids)) {
            foreach ($ids as $id) {
                $this->deleteContract((int) $id);
            }
        }

        $_SESSION['success'] = '已批量驳回 ' . count($ids) . ' 个合同';
        redirect('/import/review.php');
    }

    // ========== 私有方法 ==========

    private function getPendingContracts(int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        $stmt = db()->prepare(
            "SELECT c.*, u.display_name as created_by_name,
                    ct.name as type_name
             FROM contracts c
             LEFT JOIN admins u ON c.created_by = u.id
             LEFT JOIN contract_types ct ON c.type_id = ct.id
             WHERE c.status = 'pending_review'
             ORDER BY c.id DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$perPage, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getPendingContractsCount(): int
    {
        $stmt = db()->query("SELECT COUNT(*) FROM contracts WHERE status = 'pending_review'");
        return (int) $stmt->fetchColumn();
    }

    private function getPendingReviewCount(): int
    {
        return $this->getPendingContractsCount();
    }

    private function getApprovedCount(): int
    {
        $stmt = db()->query("SELECT COUNT(*) FROM import_files WHERE status = 'success'");
        return (int) $stmt->fetchColumn();
    }

    private function getRejectedCount(): int
    {
        $stmt = db()->query("SELECT COUNT(*) FROM import_files WHERE status = 'failed'");
        return (int) $stmt->fetchColumn();
    }

    private function getContractForReview(int $id): ?array
    {
        $stmt = db()->prepare(
            "SELECT c.*, ct.name as type_name
             FROM contracts c
             LEFT JOIN contract_types ct ON c.type_id = ct.id
             WHERE c.id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function getContractFiles(int $contractId): array
    {
        $stmt = db()->prepare("SELECT * FROM contract_files WHERE contract_id = ?");
        $stmt->execute([$contractId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getContractTypes(): array
    {
        $stmt = db()->query("SELECT id, name FROM contract_types ORDER BY name");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function deleteContract(int $id): void
    {
        // 删除附件文件
        $files = $this->getContractFiles($id);
        foreach ($files as $file) {
            $path = $file['file_path'];
            if (file_exists($path)) {
                unlink($path);
            }
        }

        // 删除数据库记录（外键会级联删除附件）
        $stmt = db()->prepare("DELETE FROM contracts WHERE id = ?");
        $stmt->execute([$id]);
    }
}
