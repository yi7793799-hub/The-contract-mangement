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

        // 自动选择模式：Web 模式下使用异步模式（避免 HTTP 超时）
        if ($mode === 'auto') {
            $mode = php_sapi_name() === 'cli' ? 'sync' : 'async';
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
     * 异步执行（后台启动）
     * 不阻塞当前 HTTP 请求
     */
    private function launchAsync(int $jobId): array
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        // 创建标记文件，让轮询脚本检测（作为备份方案）
        $markerFile = $this->pendingJobsDir . '/job_' . $jobId . '.json';
        file_put_contents($markerFile, json_encode([
            'job_id' => $jobId,
            'created_at' => date('Y-m-d H:i:s'),
            'php_path' => $this->phpPath,
            'worker_script' => $this->workerScript,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->log("Job {$jobId} queued for async processing");

        if ($isWindows) {
            // Windows: 使用 start 创建独立进程，输出重定向到日志文件
            $cmd = sprintf(
                'start /MIN "Worker%d" cmd /c "%s" "%s" %d >> "%s" 2>&1',
                $jobId,
                $this->phpPath,
                $this->workerScript,
                $jobId,
                $this->logFile
            );
            // 使用 exec 执行（不等待）
            exec($cmd);

            $this->log("Job {$jobId} launched with start /MIN");

            // 备选方案：直接使用 pclose(popen) 但用完整命令
            $altCmd = sprintf(
                '"%s" "%s" %d >> "%s" 2>&1',
                $this->phpPath,
                $this->workerScript,
                $jobId,
                $this->logFile
            );
            // 立即启动，不等待
            pclose(popen($altCmd, 'r'));
        } else {
            // Linux/Mac: 使用 nohup 后台启动
            $cmd = sprintf(
                'nohup "%s" "%s" %d >> "%s" 2>&1 &',
                $this->phpPath,
                $this->workerScript,
                $jobId,
                $this->logFile
            );
            exec($cmd);

            $this->log("Job {$jobId} launched in background (Unix)");
        }

        return [
            'launched' => true,
            'mode' => 'async',
            'marker_file' => $markerFile,
        ];
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
     * 注意：必须找到 php.exe（CLI版本），而不是 php-cgi.exe
     */
    private function findPhpPath(): string
    {
        // 1. 如果 PHP_BINARY 是 php.exe（CLI模式），直接使用
        if (defined('PHP_BINARY') && file_exists(PHP_BINARY)) {
            $binary = PHP_BINARY;
            // 检查是否是 php.exe（而不是 php-cgi.exe）
            if (strtolower(basename($binary)) === 'php.exe') {
                return $binary;
            }
            // 如果是 php-cgi.exe，尝试在同一目录查找 php.exe
            $phpExe = dirname($binary) . '/php.exe';
            if (file_exists($phpExe)) {
                return $phpExe;
            }
        }

        // 2. 从配置获取
        if (function_exists('app_config')) {
            $config = app_config();
            if (isset($config['php_path']) && file_exists($config['php_path'])) {
                return $config['php_path'];
            }
        }

        // 3. phpStudy 新版路径（小皮面板）
        $phpStudyNew = 'E:/汇总/phpstudyV8/phpstudy_pro/Extensions/php';
        if (is_dir($phpStudyNew)) {
            $phpDirs = glob($phpStudyNew . '/php*') ?: [];
            foreach ($phpDirs as $dir) {
                $phpExe = $dir . '/php.exe';
                if (file_exists($phpExe)) {
                    return $phpExe;
                }
            }
        }

        // 4. phpStudy 旧版路径
        $phpStudyOld = 'D:/phpStudy/PHPTutorial/php';
        if (is_dir($phpStudyOld)) {
            $phpDirs = glob($phpStudyOld . '/php-*') ?: [];
            foreach ($phpDirs as $dir) {
                $phpExe = $dir . '/php.exe';
                if (file_exists($phpExe)) {
                    return $phpExe;
                }
            }
        }

        // 5. 从 PATH 查找
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $result = shell_exec('where php 2>nul');
            if ($result) {
                $paths = explode("\n", trim($result));
                foreach ($paths as $p) {
                    $p = trim($p);
                    if (file_exists($p) && strtolower(basename($p)) === 'php.exe') {
                        return $p;
                    }
                }
            }
        }

        return 'php';
    }
}