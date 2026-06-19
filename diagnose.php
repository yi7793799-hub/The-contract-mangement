<?php
/**
 * 诊断脚本 - 检查待审核合同问题
 */
header('Content-Type: text/plain; charset=utf-8');

require_once __DIR__ . '/includes/bootstrap.php';

echo "=== 诊断报告 ===\n\n";

// 1. 检查数据库连接
try {
    $db = db();
    echo "[OK] 数据库连接正常\n";
} catch (Exception $e) {
    echo "[FAIL] 数据库连接失败: " . $e->getMessage() . "\n";
    exit;
}

// 2. 检查最近的导入任务
echo "\n--- 最近导入任务 ---\n";
$stmt = $db->query('SELECT id, folder_name, status, total_files, success_count, pending_count, failed_count, created_at FROM import_jobs ORDER BY id DESC LIMIT 5');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "Job #{$row['id']}: {$row['folder_name']} - status={$row['status']}, total={$row['total_files']}, success={$row['success_count']}, pending={$row['pending_count']}\n";
}

// 3. 检查待审核合同数量
echo "\n--- 待审核合同数量 ---\n";
$stmt = $db->query('SELECT COUNT(*) FROM contracts WHERE status = "pending_review"');
$pendingCount = (int) $stmt->fetchColumn();
echo "待审核合同: {$pendingCount} 条\n";

// 4. 检查合同状态分布
echo "\n--- 合同状态分布 ---\n";
$stmt = $db->query('SELECT status, COUNT(*) as cnt FROM contracts GROUP BY status');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['status']}: {$row['cnt']} 条\n";
}

// 5. 检查最近创建的合同
echo "\n--- 最近创建的合同 (前10条) ---\n";
$stmt = $db->query('SELECT id, contract_no, contract_name, status, import_confidence, created_at FROM contracts ORDER BY id DESC LIMIT 10');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $conf = round((float) $row['import_confidence'], 1);
    echo "#{$row['id']}: {$row['contract_no']} - status={$row['status']}, confidence={$conf}%, name={$row['contract_name']}\n";
}

// 6. 检查 import_files 状态
echo "\n--- import_files 状态分布 ---\n";
$stmt = $db->query('SELECT status, COUNT(*) as cnt FROM import_files GROUP BY status');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "{$row['status']}: {$row['cnt']} 条\n";
}

// 7. 检查最近的 import_files
echo "\n--- 最近 import_files (前10条) ---\n";
$stmt = $db->query('SELECT id, file_name, status, contract_id, confidence, error_message, completed_at FROM import_files ORDER BY id DESC LIMIT 10');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $conf = round((float) $row['confidence'], 1);
    $errMsg = $row['error_message'] ? " [ERROR: {$row['error_message']}]" : '';
    echo "#{$row['id']}: {$row['file_name']} - status={$row['status']}, contract={$row['contract_id']}, conf={$conf}{$errMsg}\n";
}

echo "\n=== 诊断完成 ===\n";