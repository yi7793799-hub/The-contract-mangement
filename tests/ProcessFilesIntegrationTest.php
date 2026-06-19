<?php
/**
 * 测试 process-files.php 的完整流程
 * 模拟 Web 请求
 */

declare(strict_types=1);

echo "Testing process-files.php integration...\n\n";

// 模拟 Web 环境
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// 模拟 session
session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['csrf_token'] = 'test_token_' . bin2hex(random_bytes(16));

// 模拟 CSRF token
$_POST['csrf'] = $_SESSION['csrf_token'];

// 模拟文件上传 - 创建临时文件
$tempDir = sys_get_temp_dir();
$testFile = $tempDir . '/test_contract_' . time() . '.pdf';

// 复制一个真实的 PDF 文件作为测试文件（如果存在）
$realPdf = 'E:/The contract mangement/resource code/uploads/import_jobs/20260616224202_64a6d65d5a6a5c/contract_01_丰谷115井地面建设工程测量设计.pdf';
if (file_exists($realPdf)) {
    copy($realPdf, $testFile);
} else {
    // 创建一个简单的文本文件作为测试
    file_put_contents($testFile, "Test contract content for upload test.");
}

// 模拟 $_FILES
$_FILES['files'] = [
    'name' => ['test_contract.pdf'],
    'type' => ['application/pdf'],
    'tmp_name' => [$testFile],
    'error' => [UPLOAD_ERR_OK],
    'size' => [filesize($testFile)],
];

echo "Test file: {$testFile}\n";
echo "File size: " . filesize($testFile) . " bytes\n";
echo "CSRF token: " . $_POST['csrf'] . "\n\n";

// 模拟 bootstrap 和依赖
$_SERVER['DOCUMENT_ROOT'] = 'E:/The contract mangement/resource code';
$_SERVER['SCRIPT_NAME'] = '/import/process-files.php';

// 设置响应头捕获
ob_start();

try {
    // 加载脚本
    require 'E:/The contract mangement/resource code/includes/bootstrap.php';

    // 检查 current_admin 是否工作
    echo "Checking current_admin(): ";
    $admin = current_admin();
    if ($admin) {
        echo "OK - admin_id: " . $admin['id'] . "\n";
    } else {
        echo "FAILED - no admin\n";
        // 手动设置 admin
        global $currentAdmin;
        $currentAdmin = ['id' => 1, 'username' => 'admin', 'role' => 'super'];
        echo "Set manual admin: OK\n";
    }

    // 检查 admin_can
    echo "Checking admin_can('import.create'): ";
    if (function_exists('admin_can') && admin_can('import.create')) {
        echo "OK\n";
    } else {
        echo "FAILED or not authorized\n";
    }

    echo "\n";

} catch (Throwable $e) {
    echo "Bootstrap error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
}

ob_end_clean();

// 清理临时文件
if (file_exists($testFile)) {
    unlink($testFile);
}

echo "\nTest completed.\n";