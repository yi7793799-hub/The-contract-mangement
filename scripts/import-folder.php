<?php
/**
 * 命令行导入脚本 - 直接从文件夹导入合同
 * 用法: php import-folder.php <文件夹路径> [用户ID]
 */

declare(strict_types=1);

set_time_limit(0);
ini_set('memory_limit', '512M');

if (php_sapi_name() !== 'cli') {
    echo "This script must be run from command line\n";
    exit(1);
}

$folderPath = $argv[1] ?? '';
$userId = (int) ($argv[2] ?? 1);

if (empty($folderPath) || !is_dir($folderPath)) {
    echo "Usage: php import-folder.php <folder_path> [user_id]\n";
    echo "Example: php import-folder.php \"C:/Users/A/Desktop/测试合同/扫描版pdf\" 1\n";
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/config/database.php';

spl_autoload_register(function (string $class) {
    $prefixes = [
        'App\\Services\\' => '/app/Services/',
    ];
    foreach ($prefixes as $prefix => $path) {
        if (strpos($class, $prefix) === 0) {
            $relative = substr($class, strlen($prefix));
            $file = dirname(__DIR__) . $path . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }
});

use App\Services\ContractImportService;

echo "========================================\n";
echo "  合同批量导入工具\n";
echo "========================================\n\n";
echo "文件夹: $folderPath\n";
echo "用户ID: $userId\n\n";

// 扫描文件
$supported = ['.doc', '.docx', '.pdf', '.jpg', '.jpeg', '.png', '.webp'];
$files = [];

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($folderPath, RecursiveDirectoryIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if ($file->isFile()) {
        $ext = strtolower('.' . $file->getExtension());
        if (in_array($ext, $supported)) {
            $files[] = $file->getPathname();
        }
    }
}

if (empty($files)) {
    echo "未找到支持的文件\n";
    exit(1);
}

echo "找到 " . count($files) . " 个文件:\n";
foreach ($files as $f) {
    echo "  - " . basename($f) . "\n";
}
echo "\n";

// 创建导入任务
$db = db();
$stmt = $db->prepare("INSERT INTO import_jobs (folder_name, created_by, total_files, status) VALUES (?, ?, ?, 'pending')");
$stmt->execute([basename($folderPath), $userId, count($files)]);
$jobId = (int) $db->lastInsertId();

echo "创建任务 ID: $jobId\n\n";

// 创建文件记录
foreach ($files as $filePath) {
    $stmt = $db->prepare(
        "INSERT INTO import_files (job_id, file_name, file_path, file_type, status) VALUES (?, ?, ?, ?, 'pending')"
    );
    $stmt->execute([
        $jobId,
        basename($filePath),
        $filePath,
        pathinfo($filePath, PATHINFO_EXTENSION),
    ]);
}

// 处理文件
$service = new ContractImportService();

echo "开始处理...\n\n";

$startTime = microtime(true);
$service->processFolder($folderPath, $userId);
$elapsed = microtime(true) - $startTime;

// 获取结果
$stmt = $db->prepare("SELECT * FROM import_jobs WHERE id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

echo "\n========================================\n";
echo "  导入完成\n";
echo "========================================\n";
echo "总文件数: " . ($job['total_files'] ?? 0) . "\n";
echo "成功: " . ($job['success_count'] ?? 0) . "\n";
echo "待审核: " . ($job['pending_count'] ?? 0) . "\n";
echo "失败: " . ($job['failed_count'] ?? 0) . "\n";
echo "耗时: " . round($elapsed, 1) . " 秒\n";
echo "\n请访问 http://127.0.0.1/import/review.php 查看结果\n";
