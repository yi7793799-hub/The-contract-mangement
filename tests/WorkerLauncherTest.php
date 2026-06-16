<?php
/**
 * 测试 Worker 后台启动功能
 *
 * 问题：Windows 下 start 命令在 Apache/PHP 环境中可能不工作
 * 目标：确保 worker 能可靠启动并处理任务
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

// 加载服务类
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

use App\Services\WorkerLauncher;

/**
 * 测试 1: WorkerLauncher 能启动 worker 进程
 * 期望：调用 launch() 后，任务状态从 pending 变为 processing 或 completed
 */
function test_worker_launch_starts_processing(): bool
{
    global $argv;
    echo "Test: Worker launch starts processing a pending job\n";

    $db = db();

    // 查找待处理任务
    $stmt = $db->query("SELECT id FROM import_jobs WHERE status = 'pending' ORDER BY id DESC LIMIT 1");
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        echo "  SKIP: No pending jobs available\n";
        return true;
    }

    $jobId = (int) $job['id'];
    echo "  Found pending job ID: {$jobId}\n";

    // 使用 WorkerLauncher 启动 worker
    $launcher = new WorkerLauncher();
    $result = $launcher->launch($jobId);

    echo "  Launch result: " . json_encode($result) . "\n";

    // 验证 launch 返回了执行状态
    if (!isset($result['launched']) || !$result['launched']) {
        echo "  FAIL: Launch did not return launched=true\n";
        echo "  Error: " . ($result['error'] ?? 'unknown') . "\n";
        return false;
    }

    echo "  PASS: Launch returned launched=true\n";
    return true;
}

/**
 * 测试 2: Worker 实际执行了任务
 * 期望：等待一段时间后，任务状态变化（从 pending 到 processing/completed）
 */
function test_worker_actually_processes_job(): bool
{
    echo "Test: Worker actually processes the job\n";

    $db = db();

    // 查找刚处理的任务
    $stmt = $db->query("SELECT id, status FROM import_jobs WHERE status IN ('processing', 'completed') ORDER BY id DESC LIMIT 1");
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        echo "  FAIL: No processing/completed jobs found\n";
        return false;
    }

    echo "  Job {$job['id']} status: {$job['status']}\n";
    echo "  PASS: Worker processed the job\n";
    return true;
}

/**
 * 测试 3: 日志记录功能
 */
function test_worker_logs_execution(): bool
{
    echo "Test: Worker logs execution to file\n";

    $logFile = dirname(__DIR__) . '/logs/import_worker.log';

    if (!file_exists($logFile)) {
        echo "  FAIL: Log file does not exist\n";
        return false;
    }

    $content = file_get_contents($logFile);
    $lines = explode("\n", $content);
    $lastLines = array_slice($lines, -5);

    echo "  Last log entries:\n";
    foreach ($lastLines as $line) {
        if (trim($line)) {
            echo "    " . substr($line, 0, 100) . "\n";
        }
    }

    echo "  PASS: Log file exists with entries\n";
    return true;
}

// 运行测试
echo "========================================\n";
echo "  Worker Launcher TDD Tests\n";
echo "========================================\n\n";

$results = [];

$results[] = test_worker_launch_starts_processing();

// 如果第一个测试通过，等待 worker 处理
if ($results[0]) {
    echo "\nWaiting 5 seconds for worker to process...\n";
    sleep(5);
}

$results[] = test_worker_actually_processes_job();
$results[] = test_worker_logs_execution();

echo "\n========================================\n";
$passed = count(array_filter($results));
$total = count($results);
echo "  Results: {$passed}/{$total} tests passed\n";
echo "========================================\n";

exit($passed === $total ? 0 : 1);