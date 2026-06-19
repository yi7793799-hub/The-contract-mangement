<?php
/**
 * 命令行批量导入合同脚本
 * 用法: php import-folder.php "文件夹路径" [用户ID]
 */

require_once __DIR__ . '/includes/bootstrap.php';

use App\Services\ContractImportService;

// 检查命令行模式
if (php_sapi_name() !== 'cli') {
    echo "此脚本只能在命令行下运行\n";
    exit(1);
}

// 获取参数
$folderPath = $argv[1] ?? null;
$userId = (int) ($argv[2] ?? 1);

if (empty($folderPath)) {
    echo "用法: php import-folder.php \"文件夹路径\" [用户ID]\n";
    echo "示例: php import-folder.php \"C:\\Users\\A\\Desktop\\测试合同\\正常pdf\" 1\n";
    exit(1);
}

// 验证路径
if (!is_dir($folderPath)) {
    echo "错误: 文件夹不存在: $folderPath\n";
    exit(1);
}

$realPath = realpath($folderPath);
echo "========================================\n";
echo "合同批量导入\n";
echo "========================================\n";
echo "文件夹: $realPath\n";
echo "用户ID: $userId\n";
echo "----------------------------------------\n";

// 检查路径白名单
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
    echo "错误: 该路径不在允许导入的目录范围内\n";
    echo "允许的路径: " . implode(', ', $allowedPaths) . "\n";
    exit(1);
}

// 扫描文件
$files = glob($realPath . '/*.{pdf,doc,docx,jpg,jpeg,png,webp}', GLOB_BRACE);
if (empty($files)) {
    echo "错误: 文件夹中没有找到支持的文件\n";
    exit(1);
}

echo "找到 " . count($files) . " 个文件:\n";
foreach ($files as $file) {
    echo "  - " . basename($file) . "\n";
}
echo "----------------------------------------\n";

// 确认导入
echo "是否开始导入? (y/n): ";
$confirm = trim(fgets(STDIN));
if (strtolower($confirm) !== 'y') {
    echo "已取消\n";
    exit(0);
}

echo "\n开始导入...\n";
echo "----------------------------------------\n";

// 执行导入
$service = new ContractImportService();
$jobId = $service->processFolder($realPath, $userId);

echo "----------------------------------------\n";
echo "导入任务已完成!\n";
echo "任务ID: $jobId\n";
echo "请在系统中查看待审核合同: " . url('import/review.php') . "\n";
echo "========================================\n";
