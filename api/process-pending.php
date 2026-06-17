<?php
/**
 * 检查并处理待处理任务
 * 这个脚本可以：
 * 1. 通过定时任务调用
 * 2. 通过浏览器访问触发
 */

require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$admin = current_admin();
if (!$admin) {
    echo json_encode(['error' => '请先登录']);
    exit;
}

// 检查是否有 pending 状态的任务
$pdo = db();
$stmt = $pdo->query("SELECT id, folder_name, total_files FROM import_jobs WHERE status = 'pending' ORDER BY id ASC");
$pendingJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($pendingJobs)) {
    echo json_encode(['message' => '没有待处理的任务', 'count' => 0]);
    exit;
}

// 处理每个待处理任务
$processed = [];
$phpPath = 'E:/汇总/phpstudyV8/phpstudy_pro/Extensions/php/php7.3.4nts/php.exe';
$workerScript = dirname(__DIR__) . '/scripts/import-worker.php';
$logFile = dirname(__DIR__) . '/logs/import_worker.log';

foreach ($pendingJobs as $job) {
    $jobId = $job['id'];

    // 更新状态为 processing
    $pdo->prepare("UPDATE import_jobs SET status = 'processing' WHERE id = ?")->execute([$jobId]);

    // 同步执行 Worker（更可靠）
    $cmd = sprintf('"%s" "%s" %d', $phpPath, $workerScript, $jobId);
    exec($cmd, $output, $returnCode);

    $processed[] = [
        'job_id' => $jobId,
        'files' => $job['total_files'],
        'return_code' => $returnCode,
    ];
}

echo json_encode([
    'success' => true,
    'message' => '已处理 ' . count($processed) . ' 个任务',
    'processed' => $processed,
]);