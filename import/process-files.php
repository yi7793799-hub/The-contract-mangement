<?php
declare(strict_types=1);

/**
 * 合同批量导入处理接口（异步模式）
 *
 * 流程：
 * 1. 接收上传文件
 * 2. 创建导入任务，立即返回 job_id
 * 3. 后台启动 worker 处理
 * 4. 前端轮询 import-status API 获取进度
 */

// 设置响应头为JSON
header('Content-Type: application/json; charset=utf-8');

// 增加超时时间（用于文件上传阶段）
set_time_limit(300);
ini_set('memory_limit', '512M');

// 关闭 PHP 错误输出（避免污染 JSON 响应）
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/bootstrap.php';

try {
    // 检查登录
    $admin = current_admin();
    if (!$admin) {
        echo json_encode(['error' => '请先登录', 'need_login' => true]);
        exit;
    }

    // 检查权限
    if (!admin_can('import.create')) {
        echo json_encode(['error' => '您没有导入权限']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['error' => '请求方法错误']);
        exit;
    }

    // CSRF 验证
    $csrfToken = $_POST['csrf'] ?? '';
    if (!csrf_verify($csrfToken)) {
        echo json_encode(['error' => '会话已过期，请刷新页面重试']);
        exit;
    }

    $files = $_FILES['files'] ?? [];

    if (empty($files) || empty($files['name'][0])) {
        echo json_encode(['error' => '请选择要导入的文件']);
        exit;
    }

    // 创建任务目录存放上传的文件
    $jobDir = dirname(__DIR__) . '/uploads/import_jobs/' . date('YmdHis') . '_' . uniqid();
    if (!mkdir($jobDir, 0755, true)) {
        echo json_encode(['error' => '创建任务目录失败']);
        exit;
    }

    // 支持的文件扩展名
    $allowedExts = ['doc', 'docx', 'pdf', 'jpg', 'jpeg', 'png', 'webp'];
    $uploadedFiles = [];
    $errors = [];

    // 处理每个上传的文件
    $fileCount = count($files['name']);
    for ($i = 0; $i < $fileCount; $i++) {
        $name = $files['name'][$i];
        $tmpName = $files['tmp_name'][$i];
        $error = $files['error'][$i];
        $size = $files['size'][$i];

        // 检查上传错误
        if ($error !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => '文件大小超过服务器限制',
                UPLOAD_ERR_FORM_SIZE => '文件大小超过表单限制',
                UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
                UPLOAD_ERR_NO_FILE => '没有文件被上传',
                UPLOAD_ERR_NO_TMP_DIR => '缺少临时文件夹',
                UPLOAD_ERR_CANT_WRITE => '文件写入失败',
                UPLOAD_ERR_EXTENSION => '文件上传被扩展阻止',
            ];
            $errors[] = $name . ': ' . ($errorMessages[$error] ?? '上传失败(error=' . $error . ')');
            continue;
        }

        // 检查文件扩展名
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            $errors[] = $name . ': 不支持的文件格式';
            continue;
        }

        // 移动文件到任务目录
        $destPath = $jobDir . '/' . basename($name);
        if (move_uploaded_file($tmpName, $destPath)) {
            $uploadedFiles[] = $destPath;
        } else {
            $errors[] = $name . ': 保存失败';
        }
    }

    if (empty($uploadedFiles)) {
        // 清理空目录
        @rmdir($jobDir);
        echo json_encode(['error' => '没有有效的文件可导入', 'details' => $errors]);
        exit;
    }

    // 创建导入任务
    $adminId = $admin['id'] ?? 0;
    if ($adminId <= 0) {
        echo json_encode(['error' => '请先登录']);
        exit;
    }

    $stmt = db()->prepare(
        "INSERT INTO import_jobs (folder_name, total_files, created_by, status) VALUES (?, ?, ?, 'pending')"
    );
    $stmt->execute([basename($jobDir), count($uploadedFiles), $adminId]);
    $jobId = (int) db()->lastInsertId();

    // 保存文件记录
    foreach ($uploadedFiles as $filePath) {
        $stmt = db()->prepare(
            "INSERT INTO import_files (job_id, file_name, file_path, file_type, status) VALUES (?, ?, ?, ?, 'pending')"
        );
        $stmt->execute([
            $jobId,
            basename($filePath),
            $filePath,
            pathinfo($filePath, PATHINFO_EXTENSION),
        ]);
    }

    // 立即返回任务 ID，然后使用 fastcgi_finish_request 在后台处理
    $response = json_encode([
        'success' => true,
        'job_id' => $jobId,
        'total_files' => count($uploadedFiles),
        'poll_url' => url('api/import-status.php?job_id=' . $jobId),
        'message' => '任务已创建，正在后台处理',
        'errors' => $errors,
    ]);

    // 发送响应并关闭连接
    if (function_exists('fastcgi_finish_request')) {
        echo $response;
        fastcgi_finish_request();
        // 在后台启动 worker
        require_once dirname(__DIR__) . '/app/Services/WorkerLauncher.php';
        $launcher = new \App\Services\WorkerLauncher();
        $launcher->launch($jobId);
    } else {
        // 不支持 fastcgi_finish_request，使用异步模式
        require_once dirname(__DIR__) . '/app/Services/WorkerLauncher.php';
        $launcher = new \App\Services\WorkerLauncher();
        $launchResult = $launcher->launch($jobId, 'async');

        if (!$launchResult['launched']) {
            error_log("Worker launch failed for job {$jobId}: " . ($launchResult['error'] ?? 'unknown'));
        }

        echo $response;
    }

} catch (Throwable $e) {
    error_log('Import error: ' . $e->getMessage());
    echo json_encode(['error' => '导入出错: ' . $e->getMessage()]);
}