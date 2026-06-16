<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Worker 启动器服务
 *
 * 由于 Windows + Apache 环境下后台启动进程不可靠，
 * 采用多种策略确保 worker 能运行：
 *
 * 1. 同步模式：直接执行（阻塞，但最可靠）
 * 2. 创建标记文件：让定时任务检查并启动 worker
 */
class WorkerLauncher
{
    /** @var string */
    private $phpPath;
    /** @var string */
    private $workerScript;
    /** @var string */
    private $logFile;
    /** @var string */
    private $pendingJobsDir;

    public function __construct()
    {
        $this->phpPath = $this->findPhpPath();
        $this->workerScript = dirname(__DIR__, 2) . '/scripts/import-worker.php';
        $this->logFile = dirname(__DIR__, 2) . '/logs/import_worker.log';
        $this->pendingJobsDir = dirname(__DIR__, 2) . '/logs/pending_jobs';

        // 确保目录存在
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        if (!is_dir($this->pendingJobsDir)) {
            @mkdir($this->pendingJobsDir, 0755, true);
        }
    }

    /**
     * 启动 worker 处理指定任务
     *
     * @param int $jobId 任务ID
     * @param string $mode 启动模式: 'sync'|'async'|'auto'
     * @return array 启动结果
     */
    public function launch(int $jobId, string $mode = 'auto'): array
    {
        if ($jobId <= 0) {
            return ['launched' => false, 'error' => 'Invalid job ID'];
        }

        if (!file_exists($this->workerScript)) {
            return ['launched' => false, 'error' => 'Worker script not found'];
        }

        // 自动选择模式
        if ($mode === 'auto') {
            // Web 模式下使用同步模式（最可靠）
            // CLI 模式下也可以使用同步模式
            $mode = 'sync';
        }

        switch ($mode) {
            case 'async':
                return $this->launchAsync($jobId);
            case 'sync':
            default:
                return $this->launchSync($jobId);
        }
    }

    /**
     * 同步执行（阻塞模式）
     * 最可靠的方式，但会阻塞当前请求
     */
    private function launchSync(int $jobId): array
    {
        $cmd = sprintf(
            '"%s" "%s" %d',
            $this->phpPath,
            $this->workerScript,
            $jobId
        );

        // 记录启动日志
        $this->log("Launching job {$jobId} in sync mode");

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        $this->log("Job {$jobId} completed with return code {$returnCode}");

        return [
            'launched' => true,
            'mode' => 'sync',
            'return_code' => $returnCode,
            'output' => implode("\n", $output),
        ];
    }

    /**
     * 异步执行（尝试多种方式）
     * Windows + Apache 环境下可能不可靠
     */
    private function launchAsync(int $jobId): array
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        // 创建标记文件，让轮询脚本检测
        $markerFile = $this->pendingJobsDir . '/job_' . $jobId . '.txt';
        file_put_contents($markerFile, json_encode([
            'job_id' => $jobId,
            'created_at' => date('Y-m-d H:i:s'),
        ]));

        if ($isWindows) {
            // Windows: 尝试多种方式
            // 方式1: start /MIN
            $cmd = sprintf(
                'start /MIN "ImportWorker%d" "%s" "%s" %d',
                $jobId,
                $this->phpPath,
                $this->workerScript,
                $jobId
            );
            exec($cmd);

            return [
                'launched' => true,
                'mode' => 'async',
                'method' => 'start_min',
                'marker_file' => $markerFile,
            ];
        } else {
            // Linux/Mac
            $cmd = sprintf(
                '"%s" "%s" %d >> "%s" 2>&1 &',
                $this->phpPath,
                $this->workerScript,
                $jobId,
                $this->logFile
            );
            exec($cmd);

            return [
                'launched' => true,
                'mode' => 'async',
                'method' => 'background',
            ];
        }
    }

    /**
     * 处理所有待处理任务
     * 可由定时任务或手动调用
     */
    public function processAllPending(): array
    {
        // 检查标记文件
        $markerFiles = glob($this->pendingJobsDir . '/job_*.txt');
        $processed = [];

        foreach ($markerFiles as $markerFile) {
            $data = json_decode(file_get_contents($markerFile), true);
            if (isset($data['job_id'])) {
                $result = $this->launchSync((int) $data['job_id']);
                $processed[] = [
                    'job_id' => $data['job_id'],
                    'result' => $result,
                ];
                // 删除标记文件
                @unlink($markerFile);
            }
        }

        return [
            'processed' => $processed,
            'count' => count($processed),
        ];
    }

    /**
     * 写入日志
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $line = "[{$timestamp}] [Launcher] {$message}\n";
        @file_put_contents($this->logFile, $line, FILE_APPEND);
    }

    /**
     * 查找 PHP 可执行文件路径
     */
    private function findPhpPath(): string
    {
        // 优先使用当前运行的 PHP（最可靠）
        if (defined('PHP_BINARY') && file_exists(PHP_BINARY)) {
            return PHP_BINARY;
        }

        // 尝试使用函数获取
        if (function_exists('php_executable_path')) {
            $path = php_executable_path();
            if ($path && file_exists($path)) {
                return $path;
            }
        }

        // 从配置获取
        if (function_exists('app_config')) {
            $config = app_config();
            if (isset($config['php_path']) && file_exists($config['php_path'])) {
                return $config['php_path'];
            }
        }

        // phpStudy 可能的路径（动态检测）
        $phpStudyRoot = 'D:/phpStudy/PHPTutorial';
        if (is_dir($phpStudyRoot . '/php')) {
            $phpDirs = glob($phpStudyRoot . '/php/php-*');
            foreach ($phpDirs as $dir) {
                $phpExe = $dir . '/php.exe';
                if (file_exists($phpExe)) {
                    return $phpExe;
                }
            }
        }

        // 从 PATH 查找
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $result = shell_exec('where php 2>nul');
            if ($result) {
                $paths = explode("\n", trim($result));
                foreach ($paths as $p) {
                    $p = trim($p);
                    if (file_exists($p)) {
                        return $p;
                    }
                }
            }
        }

        return 'php';
    }
}