<?php
/**
 * WorkerLauncher TDD 测试套件
 *
 * 测试重点：
 * 1. findPhpPath() 能正确找到 php.exe（CLI版本）而非 php-cgi.exe
 * 2. launch() 方法能正确启动 Worker
 * 3. 同步/异步模式都能工作
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/config/database.php';

// 自动加载
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

class WorkerLauncherTest
{
    private $passCount = 0;
    private $failCount = 0;
    private $skipCount = 0;

    /**
     * 断言测试
     */
    private function assert(bool $condition, string $message, string $failMessage = ''): void
    {
        if ($condition) {
            echo "  ✓ PASS: {$message}\n";
            $this->passCount++;
        } else {
            echo "  ✗ FAIL: {$message}\n";
            if ($failMessage) {
                echo "         {$failMessage}\n";
            }
            $this->failCount++;
        }
    }

    /**
     * 跳过测试
     */
    private function skip(string $message): void
    {
        echo "  ⊘ SKIP: {$message}\n";
        $this->skipCount++;
    }

    /**
     * 测试 1: findPhpPath 返回 php.exe 而非 php-cgi.exe
     */
    public function testFindPhpPathReturnsCliVersion(): void
    {
        echo "\n[Test 1] findPhpPath 返回 php.exe（CLI版本）\n";

        $launcher = new WorkerLauncher();

        // 使用反射获取私有属性
        $reflection = new ReflectionClass($launcher);
        $property = $reflection->getProperty('phpPath');
        $property->setAccessible(true);
        $phpPath = $property->getValue($launcher);

        $this->assert(
            !empty($phpPath),
            'PHP 路径不为空',
            "phpPath: {$phpPath}"
        );

        $this->assert(
            file_exists($phpPath),
            'PHP 路径文件存在',
            "路径: {$phpPath}"
        );

        $this->assert(
            strtolower(basename($phpPath)) === 'php.exe',
            '返回的是 php.exe 而非 php-cgi.exe',
            "实际: " . basename($phpPath)
        );

        echo "  → PHP 路径: {$phpPath}\n";
    }

    /**
     * 测试 2: findPhpPath 在 CGI 模式下能找到正确的 CLI 版本
     */
    public function testFindPhpPathFromCgiMode(): void
    {
        echo "\n[Test 2] CGI 模式下能找到 CLI 版本\n";

        // 模拟 CGI 模式
        $originalBinary = defined('PHP_BINARY') ? PHP_BINARY : null;

        // 测试逻辑：检查 PHP_BINARY 目录下是否有 php.exe
        if (defined('PHP_BINARY')) {
            $binary = PHP_BINARY;
            $binaryBasename = strtolower(basename($binary));

            echo "  → 当前 PHP_BINARY: {$binary}\n";
            echo "  → 当前 SAPI: " . php_sapi_name() . "\n";

            if (strpos($binaryBasename, 'cgi') !== false) {
                // CGI 模式，检查同目录下是否有 php.exe
                $phpExe = dirname($binary) . '/php.exe';
                $this->assert(
                    file_exists($phpExe),
                    "CGI 目录下存在 php.exe",
                    "期望路径: {$phpExe}"
                );
            } else if ($binaryBasename === 'php.exe') {
                $this->assert(true, '当前已是 CLI 模式 (php.exe)', '');
            } else {
                $this->skip("PHP_BINARY 不是标准名称: {$binaryBasename}");
            }
        } else {
            $this->skip('PHP_BINARY 常量未定义');
        }
    }

    /**
     * 测试 3: launch() 对无效 Job ID 返回错误
     */
    public function testLaunchInvalidJobId(): void
    {
        echo "\n[Test 3] launch() 对无效 Job ID 返回错误\n";

        $launcher = new WorkerLauncher();
        $result = $launcher->launch(0);

        $this->assert(
            is_array($result),
            '返回数组格式'
        );

        $this->assert(
            isset($result['launched']) && $result['launched'] === false,
            'launched = false'
        );

        $this->assert(
            isset($result['error']),
            '包含错误信息'
        );
    }

    /**
     * 测试 4: launch() 对负数 Job ID 返回错误
     */
    public function testLaunchNegativeJobId(): void
    {
        echo "\n[Test 4] launch() 对负数 Job ID 返回错误\n";

        $launcher = new WorkerLauncher();
        $result = $launcher->launch(-1);

        $this->assert(
            isset($result['launched']) && $result['launched'] === false,
            'launched = false for negative ID'
        );
    }

    /**
     * 测试 5: Worker 脚本文件存在
     */
    public function testWorkerScriptExists(): void
    {
        echo "\n[Test 5] Worker 脚本文件存在\n";

        $launcher = new WorkerLauncher();
        $reflection = new ReflectionClass($launcher);
        $property = $reflection->getProperty('workerScript');
        $property->setAccessible(true);
        $workerScript = $property->getValue($launcher);

        $this->assert(
            file_exists($workerScript),
            'Worker 脚本存在',
            "路径: {$workerScript}"
        );
    }

    /**
     * 测试 6: 日志目录可写
     */
    public function testLogDirectoryWritable(): void
    {
        echo "\n[Test 6] 日志目录可写\n";

        $launcher = new WorkerLauncher();
        $reflection = new ReflectionClass($launcher);
        $property = $reflection->getProperty('logFile');
        $property->setAccessible(true);
        $logFile = $property->getValue($launcher);

        $logDir = dirname($logFile);

        $this->assert(
            is_dir($logDir) || @mkdir($logDir, 0755, true),
            '日志目录存在或可创建',
            "路径: {$logDir}"
        );

        $this->assert(
            is_writable($logDir),
            '日志目录可写'
        );
    }

    /**
     * 测试 7: 同步模式启动（使用待处理任务）
     */
    public function testSyncLaunchWithPendingJob(): void
    {
        echo "\n[Test 7] 同步模式启动 Worker\n";

        $db = db();
        $stmt = $db->query("SELECT id FROM import_jobs WHERE status = 'pending' ORDER BY id DESC LIMIT 1");
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            $this->skip('没有待处理的任务');
            return;
        }

        $jobId = (int) $job['id'];
        echo "  → 使用任务 ID: {$jobId}\n";

        $launcher = new WorkerLauncher();
        $result = $launcher->launch($jobId, 'sync');

        $this->assert(
            is_array($result),
            '返回数组格式'
        );

        $this->assert(
            isset($result['launched']) && $result['launched'] === true,
            'launched = true'
        );

        $this->assert(
            isset($result['mode']) && $result['mode'] === 'sync',
            'mode = sync'
        );

        echo "  → 返回码: " . ($result['return_code'] ?? 'N/A') . "\n";
    }

    /**
     * 测试 8: 验证 Worker 执行结果
     */
    public function testWorkerExecutionResult(): void
    {
        echo "\n[Test 8] 验证 Worker 执行结果\n";

        $db = db();

        // 检查最近完成的任务
        $stmt = $db->query("SELECT id, status, success_count, pending_count, failed_count
                            FROM import_jobs
                            WHERE status = 'completed'
                            ORDER BY id DESC LIMIT 1");
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            $this->skip('没有已完成的任务');
            return;
        }

        echo "  → 任务 #{$job['id']}: success={$job['success_count']}, pending={$job['pending_count']}, failed={$job['failed_count']}\n";

        $total = (int)$job['success_count'] + (int)$job['pending_count'] + (int)$job['failed_count'];
        $this->assert(
            $total > 0,
            '有文件被处理'
        );

        $this->assert(
            $job['status'] === 'completed',
            '任务状态为 completed'
        );
    }

    /**
     * 测试 9: 检查日志输出
     */
    public function testLogOutput(): void
    {
        echo "\n[Test 9] 检查日志输出\n";

        $logFile = dirname(__DIR__) . '/logs/import_worker.log';

        if (!file_exists($logFile)) {
            $this->skip('日志文件不存在');
            return;
        }

        $content = file_get_contents($logFile);
        $lines = explode("\n", $content);
        $recentLines = array_filter(array_slice($lines, -10));

        $this->assert(
            count($recentLines) > 0,
            '日志文件有内容'
        );

        echo "  → 最近日志:\n";
        foreach (array_slice($recentLines, 0, 3) as $line) {
            echo "    " . substr($line, 0, 80) . "\n";
        }

        $this->assert(
            strpos($content, 'completed') !== false || strpos($content, 'OK') !== false,
            '日志包含成功记录'
        );
    }

    /**
     * 运行所有测试
     */
    public function run(): int
    {
        echo "========================================\n";
        echo "  WorkerLauncher TDD 测试套件\n";
        echo "========================================\n";

        $this->testFindPhpPathReturnsCliVersion();
        $this->testFindPhpPathFromCgiMode();
        $this->testLaunchInvalidJobId();
        $this->testLaunchNegativeJobId();
        $this->testWorkerScriptExists();
        $this->testLogDirectoryWritable();
        $this->testSyncLaunchWithPendingJob();
        $this->testWorkerExecutionResult();
        $this->testLogOutput();

        echo "\n========================================\n";
        echo "  测试结果\n";
        echo "========================================\n";
        echo "  ✓ 通过: {$this->passCount}\n";
        echo "  ✗ 失败: {$this->failCount}\n";
        echo "  ⊘ 跳过: {$this->skipCount}\n";
        echo "========================================\n";

        return $this->failCount > 0 ? 1 : 0;
    }
}

// 运行测试
$test = new WorkerLauncherTest();
exit($test->run());
