<?php
declare(strict_types=1);

// 设置响应头为JSON
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/bootstrap.php';

use App\Services\ContractImportService;

try {
    // 检查登录 - 使用 require_login 但捕获重定向
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

    // 创建临时目录存放上传的文件
    $tempDir = sys_get_temp_dir() . '/contract_import_' . date('YmdHis') . '_' . uniqid();
    if (!mkdir($tempDir, 0755, true)) {
        echo json_encode(['error' => '创建临时目录失败: ' . $tempDir]);
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
            $errors[] = $name . ': ' . ($errorMessages[$error] ?? '上传失败');
            continue;
        }

        // 检查文件扩展名
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            $errors[] = $name . ': 不支持的文件格式';
            continue;
        }

        // 移动文件到临时目录
        $destPath = $tempDir . '/' . basename($name);
        if (move_uploaded_file($tmpName, $destPath)) {
            $uploadedFiles[] = $destPath;
        } else {
            $errors[] = $name . ': 保存失败';
        }
    }

    if (empty($uploadedFiles)) {
        // 清理临时目录
        @rmdir($tempDir);
        echo json_encode(['error' => '没有有效的文件可导入。' . implode('; ', $errors)]);
        exit;
    }

    // 调用导入服务处理文件
    $admin = current_admin();
    $adminId = $admin['id'] ?? 0;

    if ($adminId <= 0) {
        echo json_encode(['error' => '请先登录']);
        exit;
    }

    $service = new ContractImportService();
    $jobId = $service->processFiles($uploadedFiles, $adminId, $tempDir);

    // 返回结果
    echo json_encode([
        'success' => true,
        'message' => '导入任务已启动',
        'redirect' => url('import/review.php')
    ]);

} catch (Throwable $e) {
    echo json_encode(['error' => '导入出错: ' . $e->getMessage()]);
}
