<?php
declare(strict_types=1);

/**
 * 导入任务状态查询 API
 * 返回任务的进度信息
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/../includes/bootstrap.php';

$admin = current_admin();
if (!$admin) {
    echo json_encode(['error' => '请先登录', 'need_login' => true]);
    exit;
}

$jobId = (int) ($_GET['job_id'] ?? 0);

if ($jobId <= 0) {
    // 返回当前用户最近的导入任务列表
    $stmt = db()->prepare(
        "SELECT id, folder_name, status, total_files, success_count, pending_count, failed_count,
                created_at, completed_at
         FROM import_jobs
         WHERE created_by = ?
         ORDER BY id DESC
         LIMIT 10"
    );
    $stmt->execute([$admin['id']]);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['jobs' => $jobs]);
    exit;
}

// 获取指定任务的详细信息
$stmt = db()->prepare(
    "SELECT id, folder_name, status, total_files, success_count, pending_count, failed_count,
            created_at, completed_at, started_at
     FROM import_jobs
     WHERE id = ? AND created_by = ?"
);
$stmt->execute([$jobId, $admin['id']]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    echo json_encode(['error' => '任务不存在']);
    exit;
}

// 计算进度
$total = (int) ($job['total_files'] ?? 0);
$processed = (int) ($job['success_count'] ?? 0) + (int) ($job['pending_count'] ?? 0) + (int) ($job['failed_count'] ?? 0);
$progress = $total > 0 ? round(($processed / $total) * 100, 1) : 0;

// 获取文件详情（最近处理的）
$stmt = db()->prepare(
    "SELECT id, file_name, status, confidence, error_message, created_at, completed_at
     FROM import_files
     WHERE job_id = ?
     ORDER BY id DESC
     LIMIT 20"
);
$stmt->execute([$jobId]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'job' => $job,
    'progress' => $progress,
    'processed' => $processed,
    'files' => $files,
]);