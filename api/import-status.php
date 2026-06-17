<?php
declare(strict_types=1);

/**
 * 导入任务状态查询 API
 * 返回任务的进度信息和详细处理步骤
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
            created_at, completed_at
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

// 获取所有文件详情（按处理顺序）
$stmt = db()->prepare(
    "SELECT id, file_name, status, confidence, error_message, created_at, completed_at,
            CASE status
                WHEN 'pending' THEN 0
                WHEN 'processing' THEN 1
                WHEN 'success' THEN 2
                WHEN 'failed' THEN 3
                ELSE 4
            END as status_order
     FROM import_files
     WHERE job_id = ?
     ORDER BY status_order ASC, id ASC
     LIMIT 50"
);
$stmt->execute([$jobId]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取当前正在处理的文件
$processingFile = null;
foreach ($files as $f) {
    if ($f['status'] === 'processing') {
        $processingFile = $f;
        break;
    }
}

// 生成当前步骤描述
$currentStep = '';
$stepDetails = [];

if ($job['status'] === 'pending') {
    $currentStep = '等待开始处理';
    $stepDetails = [
        ['step' => '上传完成', 'status' => 'done'],
        ['step' => '启动处理', 'status' => 'doing'],
    ];
} elseif ($job['status'] === 'processing') {
    $done = $processed;
    $left = $total - $processed;

    if ($processingFile) {
        $currentStep = "正在处理: " . $processingFile['file_name'];
    } else {
        $currentStep = "处理中... ({$done}/{$total})";
    }

    // 简化步骤显示：根据已处理文件数显示进度
    // 已处理完成数 / 总数 代表整体进度
    $stepDetails = [
        ['step' => '上传完成', 'status' => 'done'],
        ['step' => '处理文件', 'status' => 'doing'],
    ];

    // 如果已处理数量等于总数，说明完成了
    if ($processed >= $total && $total > 0) {
        $stepDetails[1]['status'] = 'done';
    }
} elseif ($job['status'] === 'completed') {
    $currentStep = '处理完成';
    $stepDetails = [
        ['step' => '上传完成', 'status' => 'done'],
        ['step' => '处理文件', 'status' => 'done'],
    ];
} elseif ($job['status'] === 'failed') {
    $currentStep = '处理失败';
    $stepDetails = [
        ['step' => '上传完成', 'status' => 'done'],
        ['step' => '处理文件', 'status' => 'error'],
    ];
}

// 统计信息
$stats = [
    'success' => (int) $job['success_count'],
    'pending_review' => (int) $job['pending_count'],
    'failed' => (int) $job['failed_count'],
];

echo json_encode([
    'job' => $job,
    'progress' => $progress,
    'processed' => $processed,
    'total' => $total,
    'files' => $files,
    'processing_file' => $processingFile ? $processingFile['file_name'] : null,
    'current_step' => $currentStep,
    'step_details' => $stepDetails,
    'stats' => $stats,
], JSON_UNESCAPED_UNICODE);