<?php
declare(strict_types=1);

namespace App\Services;

class ContractImportService
{
    /** @var BaiduOcrService */
    private $ocr;
    /** @var DeepSeekService */
    private $deepseek;
    /** @var PDO */
    private $db;
    /** @var array */
    private $config;

    public function __construct()
    {
        $this->ocr = new BaiduOcrService();
        $this->deepseek = new DeepSeekService();
        $this->db = db();
        $this->config = import_config();
    }

    /**
     * 处理文件夹导入
     */
    public function processFolder(string $folderPath, int $userId): int
    {
        // 1. 创建导入任务
        $jobId = $this->createJob($folderPath, $userId);

        // 2. 扫描文件夹获取文件
        $files = $this->scanFolder($folderPath);

        if (empty($files)) {
            $this->updateJobStatus($jobId, 'completed');
            return $jobId;
        }

        // 3. 更新任务文件数量
        $this->updateJobTotalFiles($jobId, count($files));

        // 4. 逐个处理文件
        foreach ($files as $file) {
            $this->processFile($file, $jobId, $userId);
        }

        // 5. 更新任务完成状态
        $this->updateJobStatus($jobId, 'completed');

        // 6. 记录通知
        $this->recordNotification($jobId);

        return $jobId;
    }

    /**
     * 创建导入任务
     */
    private function createJob(string $folderPath, int $userId): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO import_jobs (folder_name, created_by) VALUES (?, ?)"
        );
        $stmt->execute([basename($folderPath), $userId]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * 扫描文件夹获取支持的文件
     */
    private function scanFolder(string $folderPath): array
    {
        $supported = ['.doc', '.docx', '.pdf', '.jpg', '.jpeg', '.png', '.webp'];
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($folderPath, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower('.' . $file->getExtension());
                if (in_array($ext, $supported)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /**
     * 处理单个文件
     */
    public function processFile(string $filePath, int $jobId, int $userId): void
    {
        // 1. 保存文件记录
        $fileId = $this->createFileRecord($filePath, $jobId);

        try {
            $this->db->beginTransaction();

            // 2. 根据文件类型提取文本
            $text = $this->extractText($filePath);

            // 3. 调用 DeepSeek 提取字段
            $fields = $this->deepseek->extractContractFields($text);

            // 4. 计算置信度
            $confidence = $this->calculateConfidence($fields['confidence'] ?? []);

            // 5. 保存合同
            $contractId = $this->createContract($fields, $text, $confidence, $jobId, $fileId, $userId);

            // 6. 复制文件到附件目录
            $this->saveAsAttachment($filePath, $contractId);

            // 7. 更新文件状态
            $this->updateFileSuccess($fileId, $contractId, $confidence, $text, $fields);

            // 8. 更新任务统计
            $this->incrementJobCount($jobId, $confidence);

            $this->db->commit();

        } catch (Exception $e) {
            $this->db->rollBack();
            $this->updateFileFailed($fileId, $e->getMessage());
            $this->incrementJobFailed($jobId);
        }
    }

    /**
     * 根据文件类型提取文本
     */
    private function extractText(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        switch ($ext) {
            case 'docx':
                return $this->extractDocxText($filePath);
            case 'doc':
                // .doc 是旧格式二进制文件，需要特殊处理
                // 尝试用 antiword 或 catdoc 命令行工具
                $text = $this->extractDocText($filePath);
                if (!empty($text)) {
                    return $text;
                }
                throw new Exception('无法处理 .doc 文件，请先转换为 .docx 格式');
            case 'pdf':
                return $this->ocr->recognizePdf($filePath)['text'] ?? '';
            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'webp':
                return $this->ocr->recognizeImage($filePath)['text'] ?? '';
            default:
                throw new Exception('Unsupported file type: ' . $ext);
        }
    }

    /**
     * 提取 DOC 文本（尝试使用命令行工具）
     */
    private function extractDocText(string $filePath): string
    {
        // 尝试使用 antiword
        $output = [];
        $returnCode = 0;
        exec('antiword ' . escapeshellarg($filePath) . ' 2>/dev/null', $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        // 尝试使用 catdoc
        exec('catdoc ' . escapeshellarg($filePath) . ' 2>/dev/null', $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        return '';
    }

    /**
     * 提取 DOCX 文本
     */
    private function extractDocxText(string $filePath): string
    {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception('Failed to open DOCX file');
        }

        $content = $zip->getFromName('word/document.xml');
        $zip->close();

        // 去除 XML 标签
        $content = strip_tags($content);
        $content = preg_replace('/\s+/', ' ', $content);

        return trim($content);
    }

    /**
     * 计算置信度
     */
    private function calculateConfidence(array $confidences): float
    {
        if (empty($confidences)) {
            return 0;
        }

        $values = array_values($confidences);
        return array_sum($values) / count($values);
    }

    /**
     * 创建合同记录
     */
    private function createContract(array $fields, string $ocrText, float $confidence, int $jobId, int $fileId, int $userId): int
    {
        $highThreshold = $this->config['high_confidence'] ?? 85;

        // 根据置信度决定状态
        $status = $confidence >= $highThreshold ? 'ongoing' : 'pending_review';

        $stmt = $this->db->prepare(
            "INSERT INTO contracts (
                contract_no, contract_name, customer_name, signer_party, signer_name, phone,
                amount, signed_date, effective_date, expiry_date, status,
                payment_type, import_confidence, import_fields, ocr_raw_text, import_job_id, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->execute([
            $fields['contract_no'] ?? '',
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

        return (int) $this->db->lastInsertId();
    }

    /**
     * 保存为合同附件
     */
    private function saveAsAttachment(string $sourcePath, int $contractId): void
    {
        $ext = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $newName = 'import_' . $contractId . '_' . time() . '.' . $ext;
        $destDir = dirname(__DIR__, 2) . '/uploads/attachments';
        $destPath = $destDir . '/' . $newName;

        // 确保目录存在
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }

        if (!copy($sourcePath, $destPath)) {
            throw new Exception('Failed to copy attachment file');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO contract_files (contract_id, origin_name, file_path, mime_type, file_size, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $contractId,
            basename($sourcePath),
            $destPath,
            mime_content_type($sourcePath),
            filesize($sourcePath),
        ]);
    }

    /**
     * 创建文件记录
     */
    private function createFileRecord(string $filePath, int $jobId): int
    {
        $stmt = $this->db->prepare(
            "INSERT INTO import_files (job_id, file_name, file_path, file_type, status) VALUES (?, ?, ?, ?, 'pending')"
        );
        $stmt->execute([
            $jobId,
            basename($filePath),
            $filePath,
            pathinfo($filePath, PATHINFO_EXTENSION),
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * 更新文件成功状态
     */
    private function updateFileSuccess(int $fileId, int $contractId, float $confidence, string $ocrText, array $fields): void
    {
        $lowThreshold = $this->config['low_confidence'] ?? 60;
        $status = $confidence < $lowThreshold ? 'pending_review' : 'success';

        $stmt = $this->db->prepare(
            "UPDATE import_files SET status = ?, contract_id = ?, confidence = ?, ocr_text = ?,
             raw_api_response = ?, created_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$status, $contractId, $confidence, $ocrText, json_encode($fields), $fileId]);
    }

    /**
     * 更新文件失败状态
     */
    private function updateFileFailed(int $fileId, string $error): void
    {
        $stmt = $this->db->prepare(
            "UPDATE import_files SET status = 'failed', error_message = ?, created_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$error, $fileId]);
    }

    /**
     * 更新任务统计
     */
    private function updateJobTotalFiles(int $jobId, int $total): void
    {
        $stmt = $this->db->prepare("UPDATE import_jobs SET total_files = ? WHERE id = ?");
        $stmt->execute([$total, $jobId]);
    }

    private function incrementJobCount(int $jobId, float $confidence): void
    {
        $lowThreshold = $this->config['low_confidence'] ?? 60;
        $isPending = $confidence < $lowThreshold ? 1 : 0;

        $stmt = $this->db->prepare(
            "UPDATE import_jobs SET
                pending_count = pending_count + ?,
                success_count = success_count + ?
            WHERE id = ?"
        );
        $stmt->execute([$isPending, 1 - $isPending, $jobId]);
    }

    private function incrementJobFailed(int $jobId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE import_jobs SET failed_count = failed_count + 1 WHERE id = ?"
        );
        $stmt->execute([$jobId]);
    }

    private function updateJobStatus(int $jobId, string $status): void
    {
        $completedAt = $status === 'completed' ? ', completed_at = NOW()' : '';
        $stmt = $this->db->prepare(
            "UPDATE import_jobs SET status = ?{$completedAt} WHERE id = ?"
        );
        $stmt->execute([$status, $jobId]);
    }

    /**
     * 记录通知
     */
    private function recordNotification(int $jobId): void
    {
        $stmt = $this->db->prepare("SELECT * FROM import_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        $_SESSION['import_notification'] = [
            'job_id' => $jobId,
            'success' => $job['success_count'] ?? 0,
            'pending' => $job['pending_count'] ?? 0,
            'failed' => $job['failed_count'] ?? 0,
        ];
    }
}
