<?php
/**
 * Worker 触发接口
 * 上传完成后由前端调用此接口启动 Worker
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

require_once __DIR__ . '/../includes/bootstrap.php';

$admin = current_admin();
if (!$admin) {
    echo json_encode(['success' => false, 'error' => '请先登录']);
    exit;
}

$jobId = (int) ($_GET['job_id'] ?? $_POST['job_id'] ?? 0);

if ($jobId <= 0) {
    // 检查标记文件目录，处理所有待处理任务
    $markerDir = dirname(__DIR__) . '/logs/pending_jobs';
    $markerFiles = glob($markerDir . '/job_*.json');

    if (empty($markerFiles)) {
        echo json_encode(['success' => true, 'message' => '没有待处理任务', 'processed' => 0]);
        exit;
    }

    $phpPath = 'E:/汇总/phpstudyV8/phpstudy_pro/Extensions/php/php7.3.4nts/php.exe';
    $processed = [];

    foreach ($markerFiles as $markerFile) {
        $data = json_decode(file_get_contents($markerFile), true);
        if (isset($data['job_id'])) {
            $cmd = sprintf('"%s" "%s" %d', $phpPath, $data['worker_script'] ?? dirname(__DIR__) . '/scripts/import-worker.php', $data['job_id']);
            exec($cmd, $output, $ret);
            $processed[] = ['job_id' => $data['job_id'], 'return_code' => $ret];
            @unlink($markerFile);
        }
    }

    echo json_encode(['success' => true, 'message' => '已处理所有待处理任务', 'processed' => count($processed), 'jobs' => $processed]);
    exit;
}

// 处理指定任务
$phpPath = 'E:/汇总/phpstudyV8/phpstudy_pro/Extensions/php/php7.3.4nts/php.exe';
$workerScript = dirname(__DIR__) . '/scripts/import-worker.php';
$logFile = dirname(__DIR__) . '/logs/import_worker.log';

$cmd = sprintf('"%s" "%s" %d >> "%s" 2>&1', $phpPath, $workerScript, $jobId, $logFile);
exec($cmd, $output, $returnCode);

// 删除标记文件
$markerFile = dirname(__DIR__) . '/logs/pending_jobs/job_' . $jobId . '.json';
@unlink($markerFile);

echo json_encode([
    'success' => true,
    'job_id' => $jobId,
    'message' => 'Worker 已启动',
    'return_code' => $returnCode,
    'output' => implode("\n", $output)
]);