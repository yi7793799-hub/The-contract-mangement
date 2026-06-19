<?php
/**
 * 精确诊断上传 500 错误
 * 直接调用 process-files.php 的核心逻辑
 */

// 错误报告
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/logs/upload_debug.log');

echo "=== 上传 500 错误精确诊断 ===\n\n";

// 模拟 Web 环境
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'multipart/form-data';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// 加载 bootstrap
echo "1. 加载 bootstrap...\n";
require_once __DIR__ . '/includes/bootstrap.php';
echo "   ✓ 完成\n";

// 模拟登录 session
echo "\n2. 模拟登录用户...\n";
$_SESSION['admin_id'] = 1;  // 假设 ID 1 的用户存在
$admin = current_admin();
if ($admin) {
    echo "   ✓ 用户: {$admin['username']}\n";
} else {
    echo "   ✗ 用户不存在，尝试从数据库获取...\n";
    // 直接查询用户
    $stmt = db()->query("SELECT id, username, display_name, role FROM admins WHERE id = 1");
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($admin) {
        $_SESSION['admin_id'] = $admin['id'];
        echo "   ✓ 找到用户: {$admin['username']}\n";
    } else {
        echo "   ✗ 没有找到 ID=1 的用户\n";
        exit(1);
    }
}

// 生成 CSRF token
echo "\n3. 生成 CSRF token...\n";
$csrfToken = csrf_token();
$_POST['csrf'] = $csrfToken;
echo "   Token: $csrfToken\n";
echo "   验证: " . (csrf_verify($csrfToken) ? '✓ 成功' : '✗ 失败') . "\n";

// 创建测试文件模拟上传
echo "\n4. 创建测试文件...\n";
$testContent = "这是一个测试合同内容\n合同编号: TEST-2026-001\n合同名称: 测试合同\n金额: 10000.00";
$testFile = __DIR__ . '/test_upload_contract.txt';
file_put_contents($testFile, $testContent);

// 模拟 FILES 数组
$_FILES['files'] = [
    'name' => ['test_contract.txt'],
    'type' => ['text/plain'],
    'tmp_name' => [$testFile],
    'error' => [UPLOAD_ERR_OK],
    'size' => [strlen($testContent)],
];

echo "   文件: test_contract.txt\n";
echo "   大小: " . strlen($testContent) . " bytes\n";

// 现在直接执行 process-files.php 的核心逻辑
echo "\n5. 执行上传处理逻辑...\n";
echo "   ---\n";

try {
    // 检查请求方法
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('请求方法错误');
    }
    echo "   ✓ POST 方法\n";

    // CSRF 验证
    $csrfToken = $_POST['csrf'] ?? '';
    if (!csrf_verify($csrfToken)) {
        throw new Exception('会话已过期，请刷新页面重试');
    }
    echo "   ✓ CSRF 验证\n";

    // 检查文件
    $files = $_FILES['files'] ?? [];
    if (empty($files) || empty($files['name'][0])) {
        throw new Exception('请选择要导入的文件');
    }
    echo "   ✓ 文件存在\n";

    // 创建任务目录
    $jobDir = dirname(__DIR__) . '/uploads/import_jobs/' . date('YmdHis') . '_' . uniqid();
    echo "   任务目录: $jobDir\n";

    if (!mkdir($jobDir, 0755, true)) {
        throw new Exception('创建任务目录失败');
    }
    echo "   ✓ 目录创建成功\n";

    // 支持的扩展名
    $allowedExts = ['doc', 'docx', 'pdf', 'jpg', 'jpeg', 'png', 'webp', 'txt']; // 加 txt 用于测试
    $uploadedFiles = [];
    $errors = [];

    // 处理文件
    $fileCount = count($files['name']);
    echo "   文件数量: $fileCount\n";

    for ($i = 0; $i < $fileCount; $i++) {
        $name = $files['name'][$i];
        $tmpName = $files['tmp_name'][$i];
        $error = $files['error'][$i];
        $size = $files['size'][$i];

        echo "   处理文件: $name\n";

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
            echo "      ✗ 上传错误: $error\n";
            continue;
        }
        echo "      ✓ 上传状态正常\n";

        // 检查扩展名
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExts)) {
            $errors[] = $name . ': 不支持的文件格式';
            echo "      ✗ 不支持扩展名: $ext\n";
            continue;
        }
        echo "      ✓ 扩展名: $ext\n";

        // 移动文件
        $destPath = $jobDir . '/' . basename($name);
        // 注意：move_uploaded_file 对 CLI 测试文件无效，用 copy 替代
        if (copy($tmpName, $destPath)) {
            $uploadedFiles[] = $destPath;
            echo "      ✓ 文件保存成功: $destPath\n";
        } else {
            $errors[] = $name . ': 保存失败';
            echo "      ✗ 文件保存失败\n";
        }
    }

    if (empty($uploadedFiles)) {
        @rmdir($jobDir);
        throw new Exception('没有有效的文件可导入: ' . implode('; ', $errors));
    }
    echo "   ✓ 有效文件: " . count($uploadedFiles) . "\n";

    // 创建导入任务
    $adminId = $admin['id'] ?? 0;
    if ($adminId <= 0) {
        throw new Exception('请先登录');
    }
    echo "   用户 ID: $adminId\n";

    $stmt = db()->prepare(
        "INSERT INTO import_jobs (folder_name, total_files, created_by, status) VALUES (?, ?, ?, 'pending')"
    );
    $stmt->execute([basename($jobDir), count($uploadedFiles), $adminId]);
    $jobId = (int) db()->lastInsertId();
    echo "   ✓ 任务创建成功, ID: $jobId\n";

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
    echo "   ✓ 文件记录保存成功\n";

    // 测试 WorkerLauncher
    echo "\n6. 测试 WorkerLauncher...\n";
    require_once __DIR__ . '/app/Services/WorkerLauncher.php';
    $launcher = new \App\Services\WorkerLauncher();

    $result = $launcher->launch($jobId);
    echo "   Launch 结果:\n";
    echo "      launched: " . ($result['launched'] ? 'true' : 'false') . "\n";
    if (!$result['launched']) {
        echo "      error: " . ($result['error'] ?? 'unknown') . "\n";
    } else {
        echo "      mode: " . ($result['mode'] ?? 'unknown') . "\n";
        echo "      return_code: " . ($result['return_code'] ?? 'N/A') . "\n";
    }

    // 清理测试数据
    echo "\n7. 清理测试数据...\n";
    // 删除任务
    db()->prepare("DELETE FROM import_files WHERE job_id = ?")->execute([$jobId]);
    db()->prepare("DELETE FROM import_jobs WHERE id = ?")->execute([$jobId]);
    // 删除文件
    foreach ($uploadedFiles as $f) {
        @unlink($f);
    }
    @rmdir($jobDir);
    echo "   ✓ 清理完成\n";

    echo "\n=== 测试成功，没有发现错误 ===\n";

} catch (Throwable $e) {
    echo "\n*** 发现错误 ***\n";
    echo "错误类型: " . get_class($e) . "\n";
    echo "错误消息: " . $e->getMessage() . "\n";
    echo "文件: " . $e->getFile() . "\n";
    echo "行号: " . $e->getLine() . "\n";
    echo "\n堆栈跟踪:\n";
    echo $e->getTraceAsString() . "\n";

    // 写入日志
    error_log("Upload 500 error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
}

// 清理测试文件
@unlink($testFile);