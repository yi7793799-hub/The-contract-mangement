<?php
/**
 * 测试上传接口 - 模拟 POST 请求诊断
 */

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "=== 上传接口诊断测试 ===\n\n";

// 1. 检查 bootstrap 是否能正常加载
echo "1. 测试 bootstrap.php 加载...\n";
try {
    require_once __DIR__ . '/includes/bootstrap.php';
    echo "   ✓ bootstrap.php 加载成功\n";
} catch (Throwable $e) {
    echo "   ✗ bootstrap.php 加载失败: " . $e->getMessage() . "\n";
    echo "   文件: " . $e->getFile() . " 行: " . $e->getLine() . "\n";
    exit(1);
}

// 2. 检查数据库连接
echo "\n2. 测试数据库连接...\n";
try {
    $pdo = db();
    $pdo->query("SELECT 1");
    echo "   ✓ 数据库连接正常\n";
} catch (Throwable $e) {
    echo "   ✗ 数据库连接失败: " . $e->getMessage() . "\n";
    exit(1);
}

// 3. 检查 session
echo "\n3. 检查 session...\n";
if (session_status() === PHP_SESSION_ACTIVE) {
    echo "   ✓ Session 已启动\n";
} else {
    echo "   ✗ Session 未启动\n";
}

// 4. 检查用户登录状态
echo "\n4. 检查用户登录...\n";
$admin = current_admin();
if ($admin) {
    echo "   ✓ 用户已登录: " . ($admin['username'] ?? $admin['display_name'] ?? 'unknown') . "\n";
    echo "   用户 ID: " . ($admin['id'] ?? 'N/A') . "\n";
} else {
    echo "   ✗ 用户未登录\n";
    echo "   注意: 这个接口需要登录才能使用\n";
}

// 5. 检查权限
echo "\n5. 检查导入权限...\n";
if ($admin && admin_can('import.create')) {
    echo "   ✓ 用户有导入权限\n";
} else {
    echo "   ✗ 用户没有导入权限\n";
}

// 6. 检查上传目录
echo "\n6. 检查上传目录...\n";
$uploadDir = dirname(__DIR__) . '/uploads/import_jobs';
if (is_dir($uploadDir)) {
    echo "   ✓ 上传目录存在: $uploadDir\n";
    // 检查是否可写
    $testFile = $uploadDir . '/test_write_' . uniqid() . '.txt';
    if (@file_put_contents($testFile, 'test') && @unlink($testFile)) {
        echo "   ✓ 上传目录可写\n";
    } else {
        echo "   ✗ 上传目录不可写\n";
    }
} else {
    echo "   ✗ 上传目录不存在: $uploadDir\n";
    // 尝试创建
    if (@mkdir($uploadDir, 0755, true)) {
        echo "   ✓ 已创建上传目录\n";
    } else {
        echo "   ✗ 无法创建上传目录\n";
    }
}

// 7. 检查 WorkerLauncher
echo "\n7. 检查 WorkerLauncher...\n";
try {
    require_once __DIR__ . '/app/Services/WorkerLauncher.php';
    $launcher = new \App\Services\WorkerLauncher();
    echo "   ✓ WorkerLauncher 加载成功\n";
} catch (Throwable $e) {
    echo "   ✗ WorkerLauncher 加载失败: " . $e->getMessage() . "\n";
}

// 8. 模拟上传请求 - 直接调用 process-files.php 的逻辑
echo "\n8. 模拟上传请求...\n";

// 模拟 POST 数据
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['csrf'] = csrf_token();

// 创建测试文件（模拟上传）
$testFile = __DIR__ . '/test_upload_sample.txt';
file_put_contents($testFile, 'Test content for upload simulation');

// 模拟 FILES 数组
$_FILES['files'] = [
    'name' => ['test_sample.txt'],
    'type' => ['text/plain'],
    'tmp_name' => [$testFile],
    'error' => [UPLOAD_ERR_OK],
    'size' => [strlen('Test content for upload simulation')],
];

echo "   CSRF token: " . $_POST['csrf'] . "\n";
echo "   模拟文件: test_sample.txt\n";

// 验证 CSRF
echo "\n9. 验证 CSRF...\n";
if (csrf_verify($_POST['csrf'])) {
    echo "   ✓ CSRF 验证成功\n";
} else {
    echo "   ✗ CSRF 验证失败\n";
}

// 10. 尝试创建任务目录
echo "\n10. 尝试创建任务目录...\n";
$jobDir = dirname(__DIR__) . '/uploads/import_jobs/test_' . date('YmdHis') . '_' . uniqid();
if (@mkdir($jobDir, 0755, true)) {
    echo "   ✓ 任务目录创建成功: $jobDir\n";
    @rmdir($jobDir); // 清理
} else {
    echo "   ✗ 任务目录创建失败\n";
    echo "   错误: " . error_get_last()['message'] . "\n";
}

// 11. 检查 process-files.php 文件是否存在语法错误
echo "\n11. 检查 process-files.php 语法...\n";
$processFile = __DIR__ . '/import/process-files.php';
$output = [];
$returnCode = 0;
exec("php -l \"$processFile\"", $output, $returnCode);
if ($returnCode === 0) {
    echo "   ✓ process-files.php 语法正确\n";
} else {
    echo "   ✗ process-files.php 语法错误:\n";
    echo "   " . implode("\n   ", $output) . "\n";
}

// 清理测试文件
@unlink($testFile);

echo "\n=== 诊断完成 ===\n";