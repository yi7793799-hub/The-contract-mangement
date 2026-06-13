<?php
/**
 * 合同导入异步处理脚本
 * 由后台调用，逐个处理导入队列中的文件
 *
 * 用法: php import-worker.php <job_id>
 */

declare(strict_types=1);

// 设置无超时限制
set_time_limit(0);
ini_set('memory_limit', '512M');

// 确保在命令行运行
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from command line\n";
    exit(1);
}

// 获取任务ID
$jobId = (int) ($argv[1] ?? 0);
if ($jobId <= 0) {
    echo "Usage: php import-worker.php <job_id>\n";
    exit(1);
}

// 加载必要文件
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/includes/auth.php';
require_once $root . '/config/database.php';

// Simple autoloader
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
use App\Services\DocumentParserService;
use App\Services\DeepSeekService;
use App\Services\GiteeAIService;

/**
 * 处理单个导入文件
 */
function processImportFile(PDO $db, array $file, array $config): void
{
    $fileId = $file['id'];
    $filePath = $file['file_path'];
    $jobId = $file['job_id'];
    $userId = $file['user_id'] ?? 1;

    // 更新状态为处理中
    $stmt = $db->prepare("UPDATE import_files SET status = 'processing', started_at = NOW() WHERE id = ?");
    $stmt->execute([$fileId]);

    try {
        $parser = new DocumentParserService();
        $deepseek = new DeepSeekService();
        $giteeAi = new GiteeAIService();

        // 1. 提取文本 - 优先使用 Gitee AI OCR（针对扫描版文档）
        $text = '';
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // 判断是否需要 OCR（扫描版 PDF 或图片）
        $needOcr = in_array($extension, ['jpg', 'jpeg', 'png', 'webp']);

        // 对于 PDF，先尝试直接提取文本，如果提取失败或内容太少则使用 OCR
        if ($extension === 'pdf') {
            $result = $parser->parse($filePath);
            $text = $result['text'] ?? '';
            $imagePaths = $result['image_paths'] ?? [];

            // 如果有图片（扫描版 PDF），使用 Gitee AI OCR
            if (!empty($imagePaths)) {
                echo "[OCR] Using Gitee AI for scanned PDF...\n";
                $allText = [];
                foreach ($imagePaths as $imgPath) {
                    $ocrResult = $giteeAi->ocrImage($imgPath);
                    if (!$ocrResult['error']) {
                        $allText[] = $ocrResult['text'];
                    }
                    // 清理临时图片
                    if (file_exists($imgPath) && strpos($imgPath, sys_get_temp_dir()) !== false) {
                        @unlink($imgPath);
                    }
                }
                $text = implode("\n\n", $allText);
            } elseif (strlen(trim($text)) < 100) {
                // 文本太少，可能是扫描版，尝试 OCR
                echo "[OCR] Text too short, trying Gitee AI OCR...\n";
                // PDF 转图片后 OCR
                $tempDir = sys_get_temp_dir() . '/pdf_ocr_' . time();
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }
                $pythonPath = 'D:/Edge download/Python/Install/python.exe';
                $scriptPath = dirname(__DIR__) . '/scripts/render_pdf_full.py';
                $cmd = sprintf(
                    '%s %s %s %s 2>&1',
                    escapeshellarg($pythonPath),
                    escapeshellarg($scriptPath),
                    escapeshellarg($filePath),
                    escapeshellarg($tempDir)
                );
                exec($cmd, $output, $returnCode);

                $images = glob($tempDir . '/*.png');
                if (empty($images)) {
                    $images = glob($tempDir . '/*.jpg');
                }

                if (!empty($images)) {
                    sort($images);
                    $allText = [];
                    foreach ($images as $imgPath) {
                        $ocrResult = $giteeAi->ocrImage($imgPath);
                        if (!$ocrResult['error']) {
                            $allText[] = $ocrResult['text'];
                        }
                        @unlink($imgPath);
                    }
                    $text = implode("\n\n", $allText);
                    @rmdir($tempDir);
                }
            }
        } elseif ($needOcr) {
            // 图片直接使用 Gitee AI OCR
            echo "[OCR] Using Gitee AI for image...\n";
            $ocrResult = $giteeAi->ocrImage($filePath);
            if ($ocrResult['error']) {
                throw new Exception('OCR failed: ' . $ocrResult['error']);
            }
            $text = $ocrResult['text'];
        } else {
            // 其他格式直接解析
            $result = $parser->parse($filePath);
            $text = $result['text'] ?? '';
        }

        if (empty(trim($text))) {
            throw new Exception('无法从文件中提取文本');
        }

        // 2. 调用 DeepSeek 提取字段
        $fields = $deepseek->extractContractFields($text);

        // 3. 计算置信度
        $confidence = calculateConfidence($fields['confidence'] ?? []);

        // 4. 创建合同记录
        $contractId = createContract($db, $fields, $text, $confidence, $jobId, $fileId, $userId, $config);

        // 5. 保存附件
        saveAttachment($db, $filePath, $contractId);

        // 6. 更新文件状态为成功
        $lowThreshold = $config['low_confidence'] ?? 60;
        $status = $confidence < $lowThreshold ? 'pending_review' : 'success';

        $stmt = $db->prepare(
            "UPDATE import_files SET status = ?, contract_id = ?, confidence = ?, ocr_text = ?,
             raw_api_response = ?, completed_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$status, $contractId, $confidence, $text, json_encode($fields, JSON_UNESCAPED_UNICODE), $fileId]);

        // 7. 更新任务统计
        incrementJobCount($db, $jobId, $confidence);

        echo "[OK] File {$fileId}: {$file['file_name']} - confidence: {$confidence}\n";

    } catch (Exception $e) {
        // 更新失败状态
        $stmt = $db->prepare("UPDATE import_files SET status = 'failed', error_message = ?, completed_at = NOW() WHERE id = ?");
        $stmt->execute([$e->getMessage(), $fileId]);

        // 更新任务失败计数
        $stmt = $db->prepare("UPDATE import_jobs SET failed_count = failed_count + 1 WHERE id = ?");
        $stmt->execute([$jobId]);

        echo "[FAIL] File {$fileId}: {$file['file_name']} - {$e->getMessage()}\n";
    }
}

function calculateConfidence(array $confidences): float
{
    if (empty($confidences)) {
        return 0;
    }
    $values = array_values($confidences);
    return array_sum($values) / count($values);
}

function createContract(PDO $db, array $fields, string $ocrText, float $confidence, int $jobId, int $fileId, int $userId, array $config): int
{
    $highThreshold = $config['high_confidence'] ?? 85;
    $status = $confidence >= $highThreshold ? 'ongoing' : 'pending_review';

    // 处理合同编号
    $contractNo = trim($fields['contract_no'] ?? '');
    if (empty($contractNo)) {
        $contractNo = 'IMP' . date('ymdHis') . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
    } else {
        $stmt = $db->prepare("SELECT id FROM contracts WHERE contract_no = ?");
        $stmt->execute([$contractNo]);
        if ($stmt->fetch()) {
            $contractNo = 'IMP' . date('ymdHis') . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT) . '(' . substr($fields['contract_no'], 0, 10) . ')';
        }
    }

    $stmt = $db->prepare(
        "INSERT INTO contracts (
            contract_no, contract_name, customer_name, signer_party, signer_name, phone,
            amount, signed_date, effective_date, expiry_date, status,
            payment_type, import_confidence, import_fields, ocr_raw_text, import_job_id, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    $stmt->execute([
        $contractNo,
        $fields['contract_name'] ?? '',
        $fields['customer_name'] ?? '',
        $fields['signer_party'] ?? '',
        $fields['signer_name'] ?? '',
        $fields['phone'] ?? '',
        $fields['amount'] ?? 0,
        $fields['signed_date'] ?? null,
        $fields['effective_date'] ?? null,
        $fields['expiry_date'] ?? null,
        $status,
        $fields['payment_type'] ?? 'receipt',
        $confidence,
        json_encode($fields['confidence'] ?? [], JSON_UNESCAPED_UNICODE),
        $ocrText,
        $jobId,
        $userId,
    ]);

    return (int) $db->lastInsertId();
}

function saveAttachment(PDO $db, string $sourcePath, int $contractId): void
{
    $ext = pathinfo($sourcePath, PATHINFO_EXTENSION);
    $newName = 'import_' . $contractId . '_' . time() . '.' . $ext;
    $destDir = dirname(__DIR__, 2) . '/uploads/attachments';
    $destPath = $destDir . '/' . $newName;

    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    if (!copy($sourcePath, $destPath)) {
        throw new Exception('Failed to copy attachment file');
    }

    $stmt = $db->prepare(
        "INSERT INTO contract_files (contract_id, origin_name, file_path, mime_type, file_size, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    );

    // 获取 MIME 类型（兼容无 mime_content_type 的环境）
    $mimeType = 'application/octet-stream';
    if (function_exists('mime_content_type')) {
        $mimeType = mime_content_type($sourcePath) ?: $mimeType;
    } elseif (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($sourcePath) ?: $mimeType;
    } else {
        // 根据扩展名判断
        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $types = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];
        $mimeType = $types[$ext] ?? $mimeType;
    }

    $stmt->execute([
        $contractId,
        basename($sourcePath),
        $destPath,
        $mimeType,
        filesize($sourcePath),
    ]);
}

function incrementJobCount(PDO $db, int $jobId, float $confidence): void
{
    $config = [
        'low_confidence' => 60,
    ];
    $lowThreshold = $config['low_confidence'] ?? 60;
    $isPending = $confidence < $lowThreshold ? 1 : 0;

    $stmt = $db->prepare(
        "UPDATE import_jobs SET
            pending_count = pending_count + ?,
            success_count = success_count + ?
        WHERE id = ?"
    );
    $stmt->execute([$isPending, 1 - $isPending, $jobId]);
}

// ========== 主逻辑 ==========

echo "Starting import worker for job {$jobId}\n";

$db = db();

// 获取任务信息
$stmt = $db->prepare("SELECT * FROM import_jobs WHERE id = ?");
$stmt->execute([$jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    echo "Job {$jobId} not found\n";
    exit(1);
}

// 更新任务状态为处理中
$stmt = $db->prepare("UPDATE import_jobs SET status = 'processing' WHERE id = ?");
$stmt->execute([$jobId]);

// 获取导入配置
$config = [
    'high_confidence' => 85,
    'low_confidence' => 60,
];

// 获取待处理的文件
$stmt = $db->prepare(
    "SELECT f.*, j.created_by as user_id FROM import_files f
     LEFT JOIN import_jobs j ON f.job_id = j.id
     WHERE f.job_id = ? AND f.status = 'pending'
     ORDER BY f.id ASC"
);
$stmt->execute([$jobId]);
$files = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($files)) {
    echo "No pending files found for job {$jobId}\n";
    $stmt = $db->prepare("UPDATE import_jobs SET status = 'completed', completed_at = NOW() WHERE id = ?");
    $stmt->execute([$jobId]);
    exit(0);
}

echo "Found " . count($files) . " files to process\n";

// 逐个处理文件
foreach ($files as $file) {
    processImportFile($db, $file, $config);

    // 每处理完一个文件，检查任务是否被取消
    $stmt = $db->prepare("SELECT status FROM import_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $currentStatus = $stmt->fetchColumn();

    if ($currentStatus === 'cancelled') {
        echo "Job {$jobId} was cancelled\n";
        exit(0);
    }
}

// 更新任务完成状态
$stmt = $db->prepare("UPDATE import_jobs SET status = 'completed', completed_at = NOW() WHERE id = ?");
$stmt->execute([$jobId]);

echo "Job {$jobId} completed\n";
