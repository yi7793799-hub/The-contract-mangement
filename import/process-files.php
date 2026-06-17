<?php
/**
 * 合同批量导入处理接口
 * 上传后立即返回，Worker 通过独立进程启动
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_time_limit(300);
ini_set('memory_limit', '512M');

header('Content-Type: application/json; charset=utf-8');

// 致命错误处理 - 在任何代码之前注册
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // 清除任何已输出的内容
        ob_end_clean();
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'error' => '服务器内部错误',
            'code' => 500
        ]);
    }
});

try {
    require_once __DIR__ . '/../includes/bootstrap.php';

    $admin = current_admin();
    if (!$admin) {
        echo json_encode(['success' => false, 'error' => '请先登录', 'need_login' => true, 'code' => 401]);
        exit;
    }

    if (!admin_can('import.create')) {
        echo json_encode(['success' => false, 'error' => '您没有导入权限', 'code' => 403]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['success' => false, 'error' => '请求方法错误', 'code' => 400]);
        exit;
    }

    $csrfToken = $_POST['csrf'] ?? '';
    if (!csrf_verify($csrfToken)) {
        echo json_encode(['success' => false, 'error' => '会话已过期，请刷新页面重试', 'code' => 403]);
        exit;
    }

    $files = $_FILES['files'] ?? [];
    $fileCount = isset($files['name']) && is_array($files['name']) ? count($files['name']) : 0;

    if ($fileCount === 0) {
        echo json_encode(['success' => false, 'error' => '请选择要导入的文件', 'code' => 400]);
        exit;
    }

    // 创建任务目录
    $jobDir = dirname(__DIR__) . '/uploads/import_jobs/' . date('YmdHis') . '_' . uniqid();
    if (!mkdir($jobDir, 0755, true)) {
        echo json_encode(['success' => false, 'error' => '创建任务目录失败', 'code' => 500]);
        exit;
    }

    $allowedExts = ['doc', 'docx', 'pdf', 'jpg', 'jpeg', 'png', 'webp'];
    $uploadedFiles = [];
    $errors = [];

    for ($i = 0; $i < $fileCount; $i++) {
        $name = $files['name'][$i];
        $tmpName = $files['tmp_name'][$i];
        $error = $files['error'][$i];

        if ($error !== UPLOAD_ERR_OK) {
            continue;
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            continue;
        }

        $destPath = $jobDir . '/' . basename($name);
        if (move_uploaded_file($tmpName, $destPath)) {
            $uploadedFiles[] = $destPath;
        }
    }

    if (empty($uploadedFiles)) {
        @rmdir($jobDir);
        echo json_encode(['success' => false, 'error' => '没有有效的文件可导入', 'code' => 400]);
        exit;
    }

    $adminId = $admin['id'] ?? 0;
    if ($adminId <= 0) {
        @rmdir($jobDir);
        echo json_encode(['success' => false, 'error' => '请先登录', 'code' => 401]);
        exit;
    }

    // 创建数据库记录
    $stmt = db()->prepare(
        "INSERT INTO import_jobs (folder_name, total_files, created_by, status) VALUES (?, ?, ?, 'pending')"
    );
    $stmt->execute([basename($jobDir), count($uploadedFiles), $adminId]);
    $jobId = (int) db()->lastInsertId();

    foreach ($uploadedFiles as $filePath) {
        $stmt = db()->prepare(
            "INSERT INTO import_files (job_id, file_name, file_path, file_type, status) VALUES (?, ?, ?, ?, 'pending')"
        );
        $stmt->execute([$jobId, basename($filePath), $filePath, pathinfo($filePath, PATHINFO_EXTENSION)]);
    }

    // ========== 创建 Worker 启动标记文件 ==========
    $markerDir = dirname(__DIR__) . '/logs/pending_jobs';
    if (!is_dir($markerDir)) {
        mkdir($markerDir, 0755, true);
    }
    $markerFile = $markerDir . '/job_' . $jobId . '.json';
    file_put_contents($markerFile, json_encode([
        'job_id' => $jobId,
        'created_at' => date('Y-m-d H:i:s'),
        'php_path' => 'E:/汇总/phpstudyV8/phpstudy_pro/Extensions/php/php7.3.4nts/php.exe',
        'worker_script' => dirname(__DIR__) . '/scripts/import-worker.php',
    ]));

    // ========== 返回成功响应（不再启动 Worker，由前端触发）==========
    echo json_encode([
        'success' => true,
        'job_id' => $jobId,
        'total_files' => count($uploadedFiles),
        'poll_url' => url('api/import-status.php?job_id=' . $jobId),
        'trigger_url' => url('api/trigger-worker.php?job_id=' . $jobId),
        'message' => '文件上传成功',
        'errors' => $errors,
        'code' => 200
    ]);

} catch (Throwable $e) {
    error_log('Import error: ' . $e->getMessage());
    ob_end_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'error' => '导入出错: ' . $e->getMessage(),
        'code' => 500
    ]);
}