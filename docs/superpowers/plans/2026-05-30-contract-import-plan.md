# 合同批量导入功能 - 实现计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 实现合同批量导入功能，支持批量上传文件夹，百度 OCR 提取文本 + DeepSeek 语义校验，自动保存或标记待审核

**Architecture:**
- 新增 `app/Services/` 目录存放 OCR 和 DeepSeek 服务类
- 新增 `app/Controllers/ImportController.php` 处理导入相关页面
- 新增 `app/Views/import/` 目录存放视图模板
- 新增数据库表 `import_jobs` 和 `import_files` 记录导入任务
- 修改现有 `contracts` 表增加待审核状态和相关字段

**Tech Stack:** PHP 原生 + 百度 OCR API + DeepSeek API + MySQL

---

## 文件结构

```
新增文件:
- app/Services/BaiduOcrService.php       # 百度 OCR 服务封装
- app/Services/DeepSeekService.php      # DeepSeek API 服务封装
- app/Services/ContractImportService.php # 导入核心逻辑
- app/Controllers/ImportController.php   # 导入控制器
- app/Views/import/upload.php           # 导入上传页
- app/Views/import/review_list.php      # 待审核列表页
- app/Views/import/review_detail.php    # 审核详情页
- import.php                             # 导入页入口（重定向）

修改文件:
- config/config.php                      # 新增 OCR/DeepSeek 配置
- includes/functions.php                 # 新增 import 权限点
- includes/layout.php                    # 新增导入菜单项
- includes/site_branding.php             # 权限组定义修改

数据库变更:
- contracts 表: 新增 status 枚举值、低置信度字段、OCR原文等
- 新建 import_jobs 表
- 新建 import_files 表
```

---

## Task 1: 数据库变更

**Files:**
- Modify: `includes/functions.php` - 在 mf_ensure_contract_schema() 中添加新表

- [ ] **Step 1: 修改 contracts 表状态枚举**

```php
// 在 includes/functions.php 的 mf_ensure_contract_schema() 函数中
// 找到 ALTER TABLE contracts 语句，修改 status 枚举
$safeExec("ALTER TABLE contracts MODIFY COLUMN status ENUM('ongoing','completed','terminated','expiring','pending_review') NOT NULL DEFAULT 'ongoing'");
```

- [ ] **Step 2: 添加 contracts 表新字段**

```php
// 添加导入相关字段
$safeExec("ALTER TABLE contracts ADD COLUMN import_confidence DECIMAL(5,2) DEFAULT NULL AFTER status");
$safeExec("ALTER TABLE contracts ADD COLUMN import_fields JSON DEFAULT NULL AFTER import_confidence");
$safeExec("ALTER TABLE contracts ADD COLUMN ocr_raw_text LONGTEXT DEFAULT NULL AFTER import_fields");
$safeExec("ALTER TABLE contracts ADD COLUMN import_job_id INT UNSIGNED DEFAULT NULL AFTER ocr_raw_text");
```

- [ ] **Step 3: 创建 import_jobs 表**

```php
$safeExec(
    "CREATE TABLE IF NOT EXISTS import_jobs (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        folder_name VARCHAR(255) NOT NULL,
        status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
        total_files INT UNSIGNED DEFAULT 0,
        success_count INT UNSIGNED DEFAULT 0,
        pending_count INT UNSIGNED DEFAULT 0,
        failed_count INT UNSIGNED DEFAULT 0,
        created_by INT UNSIGNED DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        completed_at DATETIME DEFAULT NULL,
        INDEX idx_status (status),
        INDEX idx_created_by (created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
```

- [ ] **Step 4: 创建 import_files 表**

```php
$safeExec(
    "CREATE TABLE IF NOT EXISTS import_files (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        job_id INT UNSIGNED NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_type VARCHAR(50) NOT NULL,
        status ENUM('pending','success','pending_review','failed') DEFAULT 'pending',
        contract_id INT UNSIGNED DEFAULT NULL,
        confidence DECIMAL(5,2) DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        ocr_text LONGTEXT DEFAULT NULL,
        raw_api_response JSON DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_job_id (job_id),
        INDEX idx_status (status),
        FOREIGN KEY (job_id) REFERENCES import_jobs(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
);
```

- [ ] **Step 5: 验证数据库变更**

```bash
# 访问 http://127.0.0.1/dashboard.php 触发 schema 更新
# 然后用 MySQL 客户端检查
"/d/phpStudy/PHPTutorial/MySQL/bin/mysql.exe" -u root -p123456aa htgl -e "DESCRIBE contracts" | grep -E "import_|status"
# 预期输出应包含: import_confidence, import_fields, ocr_raw_text, import_job_id, pending_review
```

---

## Task 2: 配置文件更新

**Files:**
- Modify: `config/config.php`

- [ ] **Step 1: 添加 OCR 和 DeepSeek 配置项**

在 `config/config.php` 的 return array 中添加：

```php
return [
    // ... 现有配置 ...

    // 百度 OCR 配置
    'baidu_ocr' => [
        'ak' => 'your_ak',      // API Key
        'sk' => 'your_sk',      // Secret Key
    ],

    // DeepSeek 配置
    'deepseek' => [
        'api_key' => 'your_key',
        'model' => 'deepseek-chat',
    ],

    // 导入置信度阈值
    'import' => [
        'high_confidence' => 85,
        'low_confidence' => 60,
    ],
];
```

- [ ] **Step 2: 创建配置获取辅助函数**

在 `includes/functions.php` 中添加：

```php
function baidu_ocr_config(): array
{
    return app_config()['baidu_ocr'] ?? [];
}

function deepseek_config(): array
{
    return app_config()['deepseek'] ?? [];
}

function import_config(): array
{
    return app_config()['import'] ?? ['high_confidence' => 85, 'low_confidence' => 60];
}
```

---

## Task 3: 百度 OCR 服务封装

**Files:**
- Create: `app/Services/BaiduOcrService.php`

- [ ] **Step 1: 创建 BaiduOcrService.php**

```php
<?php
declare(strict_types=1);

class BaiduOcrService
{
    private string $ak;
    private string $sk;
    private string $accessToken;
    private int $tokenExpire;

    public function __construct()
    {
        $config = baidu_ocr_config();
        $this->ak = $config['ak'] ?? '';
        $this->sk = $config['sk'] ?? '';
        $this->accessToken = '';
        $this->tokenExpire = 0;
    }

    /**
     * 获取 Access Token
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken && time() < $this->tokenExpire - 300) {
            return $this->accessToken;
        }

        $url = 'https://aip.baidubce.com/oauth/2.0/token';
        $params = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->ak,
            'client_secret' => $this->sk,
        ];

        $response = $this->httpPost($url, $params);
        $data = json_decode($response, true);

        if (isset($data['access_token'])) {
            $this->accessToken = $data['access_token'];
            $this->tokenExpire = time() + ($data['expires_in'] ?? 2592000);
            return $this->accessToken;
        }

        throw new Exception('Failed to get Baidu access token: ' . ($data['error_description'] ?? 'Unknown error'));
    }

    /**
     * 识别图片文件中的文字
     */
    public function recognizeImage(string $imagePath): array
    {
        $token = $this->getAccessToken();
        $url = 'https://aip.baidubce.com/rest/2.0/ocr/v1/general_basic';

        $image = base64_encode(file_get_contents($imagePath));

        $response = $this->httpPost($url . '?access_token=' . $token, [
            'image' => $image,
        ]);

        return $this->parseResponse($response);
    }

    /**
     * 识别 PDF 文件（每页转图片）
     */
    public function recognizePdf(string $pdfPath): array
    {
        // 对于 PDF，先尝试提取文字
        $text = $this->extractPdfText($pdfPath);

        if (!empty($text)) {
            return ['text' => $text, 'is_ocr' => false];
        }

        // 如果没有文字，使用 OCR（需要 PDF 转图片库，这里简化处理）
        throw new Exception('PDF contains no extractable text and OCR not supported for PDF directly');
    }

    /**
     * 从 PDF 提取文字（简化版）
     */
    private function extractPdfText(string $pdfPath): string
    {
        // 尝试使用 pdftotext 命令
        $tmpFile = tempnam(sys_get_temp_dir(), 'pdf_');
        $output = [];
        $returnCode = 0;

        exec('pdftotext "' . $pdfPath . '" - 2>/dev/null', $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        return '';
    }

    /**
     * 解析 API 响应
     */
    private function parseResponse(string $response): array
    {
        $data = json_decode($response, true);

        if (isset($data['error_code'])) {
            throw new Exception('Baidu OCR API error: ' . ($data['error_msg'] ?? 'Unknown error'));
        }

        $text = '';
        if (isset($data['words_result'])) {
            foreach ($data['words_result'] as $item) {
                $text .= ($item['words'] ?? '') . "\n";
            }
        }

        return [
            'text' => trim($text),
            'raw' => $data,
        ];
    }

    /**
     * HTTP POST 请求
     */
    private function httpPost(string $url, array $data): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('HTTP request failed: ' . $error);
        }

        return $response;
    }
}
```

- [ ] **Step 2: 测试 BaiduOcrService 加载**

验证文件可以正常加载（需要配置 AK/SK 后才能测试 OCR 功能）

---

## Task 4: DeepSeek 服务封装

**Files:**
- Create: `app/Services/DeepSeekService.php`

- [ ] **Step 1: 创建 DeepSeekService.php**

```php
<?php
declare(strict_types=1);

class DeepSeekService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $config = deepseek_config();
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'deepseek-chat';
    }

    /**
     * 从合同文本提取关键字段
     */
    public function extractContractFields(string $text): array
    {
        $prompt = $this->buildExtractPrompt($text);
        $response = $this->chat([
            ['role' => 'system', 'content' => '你是一个专业的合同信息提取助手。从合同文本中提取关键信息，并以JSON格式返回。'],
            ['role' => 'user', 'content' => $prompt],
        ]);

        return $this->parseJsonResponse($response);
    }

    /**
     * 构建字段提取 Prompt
     */
    private function buildExtractPrompt(string $text): string
    {
        return <<<EOT
请从以下合同文本中提取关键信息，返回JSON格式。如果某字段无法提取，设为null。

必须返回的JSON格式：
{
    "contract_no": "合同编号，如HT-2026-001",
    "contract_name": "合同名称",
    "customer_name": "客户名称",
    "signer_party": "签约方",
    "signer_name": "签约人姓名",
    "phone": "联系电话",
    "amount": "合同金额（数字）",
    "signed_date": "签订日期（YYYY-MM-DD格式）",
    "effective_date": "生效日期（YYYY-MM-DD格式）",
    "expiry_date": "截止日期（YYYY-MM-DD格式）",
    "payment_type": "款项类型，receipt表示收款，payment表示付款",
    "confidence": {
        "contract_no": 置信度0-100,
        "contract_name": 置信度0-100,
        "customer_name": 置信度0-100,
        "signer_party": 置信度0-100,
        "signer_name": 置信度0-100,
        "phone": 置信度0-100,
        "amount": 置信度0-100,
        "signed_date": 置信度0-100,
        "effective_date": 置信度0-100,
        "expiry_date": 置信度0-100,
        "payment_type": 置信度0-100
    }
}

合同文本如下：
{$text}

只返回JSON，不要有其他内容。
EOT;
    }

    /**
     * 调用 Chat API
     */
    public function chat(array $messages): string
    {
        $url = 'https://api.deepseek.com/v1/chat/completions';

        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.1,
        ];

        $response = $this->httpPost($url, $data);

        $result = json_decode($response, true);

        if (isset($result['error'])) {
            throw new Exception('DeepSeek API error: ' . ($result['error']['message'] ?? 'Unknown error'));
        }

        return $result['choices'][0]['message']['content'] ?? '';
    }

    /**
     * 解析 JSON 响应
     */
    private function parseJsonResponse(string $response): array
    {
        // 尝试提取 JSON 部分
        if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
            $data = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }

        throw new Exception('Failed to parse DeepSeek response as JSON');
    }

    /**
     * HTTP POST 请求
     */
    private function httpPost(string $url, array $data): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('HTTP request failed: ' . $error);
        }

        return $response;
    }
}
```

- [ ] **Step 2: 测试 DeepSeekService 加载**

验证文件可以正常加载

---

## Task 5: 合同导入核心服务

**Files:**
- Create: `app/Services/ContractImportService.php`

- [ ] **Step 1: 创建 ContractImportService.php**

```php
<?php
declare(strict_types=1);

class ContractImportService
{
    private BaiduOcrService $ocr;
    private DeepSeekService $deepseek;
    private PDO $db;
    private array $config;

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
            $this->processFile($file, $jobId);
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
            "INSERT INTO import_jobs (folder_name, created_by, created_at) VALUES (?, ?, NOW())"
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

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folderPath, RecursiveDirectoryIterator::SKIP_DOTS)
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
    public function processFile(string $filePath, int $jobId): void
    {
        // 1. 保存文件记录
        $fileId = $this->createFileRecord($filePath, $jobId);

        try {
            // 2. 根据文件类型提取文本
            $text = $this->extractText($filePath);

            // 3. 调用 DeepSeek 提取字段
            $fields = $this->deepseek->extractContractFields($text);

            // 4. 计算置信度
            $confidence = $this->calculateConfidence($fields['confidence'] ?? []);

            // 5. 保存合同
            $contractId = $this->createContract($fields, $text, $confidence, $jobId, $fileId);

            // 6. 复制文件到附件目录
            $this->saveAsAttachment($filePath, $contractId);

            // 7. 更新文件状态
            $this->updateFileSuccess($fileId, $contractId, $confidence, $text, $fields);

            // 8. 更新任务统计
            $this->incrementJobCount($jobId, $confidence);

        } catch (Exception $e) {
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
                // 简化处理，实际可能需要转换
                return $this->ocr->recognizeImage($filePath)['text'] ?? '';
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
    private function createContract(array $fields, string $ocrText, float $confidence, int $jobId, int $fileId): int
    {
        $highThreshold = $this->config['high_confidence'] ?? 85;
        $lowThreshold = $this->config['low_confidence'] ?? 60;

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
            $_SESSION['admin_id'] ?? null,
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
        $destPath = 'uploads/attachments/' . $newName;

        copy($sourcePath, $destPath);

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
            "INSERT INTO import_files (job_id, file_name, file_path, file_type, status, created_at)
             VALUES (?, ?, ?, ?, 'pending', NOW())"
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
        $status = $confidence < ($this->config['low_confidence'] ?? 60) ? 'pending_review' : 'success';

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
        $field = $confidence < $lowThreshold ? 'pending_count' : 'success_count';

        $stmt = $this->db->prepare(
            "UPDATE import_jobs SET {$field} = {$field} + 1 WHERE id = ?"
        );
        $stmt->execute([$jobId]);
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
```

---

## Task 6: 导入控制器

**Files:**
- Create: `app/Controllers/ImportController.php`

- [ ] **Step 1: 创建 ImportController.php**

```php
<?php
declare(strict_types=1);

class ImportController
{
    private ContractImportService $service;

    public function __construct()
    {
        require_login();
        require_permission('import.view');
        $this->service = new ContractImportService();
    }

    /**
     * 导入上传页
     */
    public function index(): void
    {
        // 检查是否有通知消息
        $notification = $_SESSION['import_notification'] ?? null;
        unset($_SESSION['import_notification']);

        $data = [
            'notification' => $notification,
            'pending_count' => $this->getPendingReviewCount(),
        ];

        $this->view('import/upload', $data);
    }

    /**
     * 处理导入请求
     */
    public function process(): void
    {
        require_permission('import.create');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/import');
        }

        $folderPath = $_POST['folder_path'] ?? '';

        if (empty($folderPath) || !is_dir($folderPath)) {
            $_SESSION['error'] = '请选择有效的文件夹';
            redirect('/import');
        }

        // 后台处理（这里简化处理，实际应该用队列）
        $jobId = $this->service->processFolder($folderPath, $_SESSION['admin_id']);

        $_SESSION['success'] = '导入任务已启动，请在待审核页面查看结果';
        redirect('/import');
    }

    /**
     * 待审核列表页
     */
    public function reviewList(): void
    {
        require_permission('import.review');

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;

        $contracts = $this->getPendingContracts($page, $perPage);
        $total = $this->getPendingContractsCount();
        $totalPages = ceil($total / $perPage);

        $data = [
            'contracts' => $contracts,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ];

        $this->view('import/review_list', $data);
    }

    /**
     * 审核详情页
     */
    public function reviewDetail(int $id): void
    {
        require_permission('import.review');

        $contract = $this->getContractForReview($id);

        if (!$contract) {
            $_SESSION['error'] = '合同不存在';
            redirect('/import/review');
        }

        // 获取识别字段及其置信度
        $fields = json_decode($contract['import_fields'], true) ?? [];
        $ocrText = $contract['ocr_raw_text'] ?? '';

        // 获取附件
        $files = $this->getContractFiles($id);

        $data = [
            'contract' => $contract,
            'fields' => $fields,
            'ocr_text' => $ocrText,
            'files' => $files,
        ];

        $this->view('import/review_detail', $data);
    }

    /**
     * 审核通过
     */
    public function approve(int $id): void
    {
        require_permission('import.review.edit');

        $stmt = db()->prepare("UPDATE contracts SET status = 'ongoing' WHERE id = ? AND status = 'pending_review'");
        $stmt->execute([$id]);

        $_SESSION['success'] = '合同已审核通过';
        redirect('/import/review');
    }

    /**
     * 审核驳回
     */
    public function reject(int $id): void
    {
        require_permission('import.review.edit');

        // 删除合同及其附件
        $this->deleteContract($id);

        $_SESSION['success'] = '合同已驳回';
        redirect('/import/review');
    }

    /**
     * 批量审核通过
     */
    public function batchApprove(): void
    {
        require_permission('import.review.edit');

        $ids = $_POST['ids'] ?? [];

        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("UPDATE contracts SET status = 'ongoing' WHERE id IN ({$placeholders}) AND status = 'pending_review'");
            $stmt->execute($ids);
        }

        $_SESSION['success'] = '已批量通过 ' . count($ids) . ' 个合同';
        redirect('/import/review');
    }

    /**
     * 批量驳回
     */
    public function batchReject(): void
    {
        require_permission('import.review.edit');

        $ids = $_POST['ids'] ?? [];

        if (!empty($ids)) {
            foreach ($ids as $id) {
                $this->deleteContract((int) $id);
            }
        }

        $_SESSION['success'] = '已批量驳回 ' . count($ids) . ' 个合同';
        redirect('/import/review');
    }

    // ========== 私有方法 ==========

    private function getPendingContracts(int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        $stmt = db()->prepare(
            "SELECT c.*, u.display_name as created_by_name,
                    ct.name as type_name
             FROM contracts c
             LEFT JOIN admins u ON c.created_by = u.id
             LEFT JOIN contract_types ct ON c.type_id = ct.id
             WHERE c.status = 'pending_review'
             ORDER BY c.id DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$perPage, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getPendingContractsCount(): int
    {
        $stmt = db()->query("SELECT COUNT(*) FROM contracts WHERE status = 'pending_review'");
        return (int) $stmt->fetchColumn();
    }

    private function getPendingReviewCount(): int
    {
        return $this->getPendingContractsCount();
    }

    private function getContractForReview(int $id): ?array
    {
        $stmt = db()->prepare(
            "SELECT c.*, ct.name as type_name
             FROM contracts c
             LEFT JOIN contract_types ct ON c.type_id = ct.id
             WHERE c.id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getContractFiles(int $contractId): array
    {
        $stmt = db()->prepare("SELECT * FROM contract_files WHERE contract_id = ?");
        $stmt->execute([$contractId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function deleteContract(int $id): void
    {
        // 删除附件文件
        $files = $this->getContractFiles($id);
        foreach ($files as $file) {
            $path = $file['file_path'];
            if (file_exists($path)) {
                unlink($path);
            }
        }

        // 删除数据库记录（外键会级联删除附件）
        $stmt = db()->prepare("DELETE FROM contracts WHERE id = ?");
        $stmt->execute([$id]);
    }

    protected function view(string $template, array $data = []): void
    {
        extract($data);

        $viewPath = __DIR__ . '/../Views/' . $template . '.php';
        if (!is_file($viewPath)) {
            throw new Exception('View not found: ' . $viewPath);
        }

        require $viewPath;
    }
}
```

---

## Task 7: 视图模板

**Files:**
- Create: `app/Views/import/upload.php`
- Create: `app/Views/import/review_list.php`
- Create: `app/Views/import/review_detail.php`

- [ ] **Step 1: 创建导入上传页 app/Views/import/upload.php**

```php
<?php
$notification = $notification ?? null;
$pendingCount = $pendingCount ?? 0;
?>
<div class="page-header">
    <h1>合同批量导入</h1>
</div>

<?php if ($notification): ?>
<div class="alert alert-success">
    导入完成！成功: <?= $notification['success'] ?> | 待审核: <?= $notification['pending'] ?> | 失败: <?= $notification['failed'] ?>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success"><?= e($_SESSION['success']) ?></div>
<?php unset($_SESSION['success']); endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<div class="alert alert-danger"><?= e($_SESSION['error']) ?></div>
<?php unset($_SESSION['error']); endif; ?>

<div class="card">
    <div class="card-body">
        <form method="post" action="<?= url('/import/process') ?>">
            <div class="mb-3">
                <label class="form-label">文件夹路径</label>
                <input type="text" name="folder_path" class="form-control"
                       placeholder="请输入包含合同文件的文件夹路径，如 D:\contracts"
                       required>
                <div class="form-text">支持 .doc, .docx, .pdf, .jpg, .png, .webp 格式</div>
            </div>

            <button type="submit" class="btn btn-primary">
                <i class="bi bi-upload"></i> 开始导入
            </button>

            <?php if ($pendingCount > 0): ?>
            <a href="<?= url('/import/review') ?>" class="btn btn-warning">
                查看待审核合同 (<?= $pendingCount ?>)
            </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card mt-4">
    <div class="card-body">
        <h5>导入说明</h5>
        <ul>
            <li>请确保文件夹内的文件格式为支持的格式</li>
            <li>系统将自动识别合同文本并提取关键字段</li>
            <li>使用百度 OCR 识别图片和扫描版 PDF</li>
            <li>使用 DeepSeek 大模型进行语义校验</li>
            <li>低置信度合同将标记为待审核状态</li>
            <li>原始文件将保存为合同附件</li>
        </ul>
    </div>
</div>
```

- [ ] **Step 2: 创建待审核列表页 app/Views/import/review_list.php**

```php
<?php
$contracts = $contracts ?? [];
$page = $page ?? 1;
$totalPages = $totalPages ?? 1;
$total = $total ?? 0;
?>
<div class="page-header">
    <h1>待审核合同</h1>
</div>

<?php if (isset($_SESSION['success'])): ?>
<div class="alert alert-success"><?= e($_SESSION['success']) ?></div>
<?php unset($_SESSION['success']); endif; ?>

<div class="card">
    <div class="card-body">
        <?php if (empty($contracts)): ?>
        <p class="text-muted">暂无待审核合同</p>
        <?php else: ?>
        <form method="post" action="<?= url('/import/batch-approve') ?>">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>文件名</th>
                        <th>合同编号</th>
                        <th>合同名称</th>
                        <th>客户名称</th>
                        <th>金额</th>
                        <th>置信度</th>
                        <th>导入时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contracts as $c): ?>
                    <tr>
                        <td><input type="checkbox" name="ids[]" value="<?= $c['id'] ?>"></td>
                        <td><?= e($c['contract_no']) ?></td>
                        <td><?= e($c['contract_name']) ?></td>
                        <td><?= e($c['customer_name']) ?></td>
                        <td><?= number_format($c['amount'], 2) ?></td>
                        <td>
                            <?php
                            $conf = (float) $c['import_confidence'];
                            $color = $conf >= 85 ? 'success' : ($conf >= 60 ? 'warning' : 'danger');
                            ?>
                            <span class="badge bg-<?= $color ?>"><?= number_format($conf, 1) ?>%</span>
                        </td>
                        <td><?= date('Y-m-d H:i', strtotime($c['created_at'])) ?></td>
                        <td>
                            <a href="<?= url('/import/review/' . $c['id']) ?>" class="btn btn-sm btn-primary">
                                审核
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="mt-3">
                <button type="submit" class="btn btn-success">批量通过</button>
                <button type="button" class="btn btn-danger" onclick="batchReject()">批量驳回</button>
            </div>
        </form>

        <?php if ($totalPages > 1): ?>
        <nav>
            <ul class="pagination">
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= url('/import/review?page=' . $i) ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('selectAll').onclick = function() {
    var checkboxes = document.querySelectorAll('input[name="ids[]"]');
    checkboxes.forEach(function(cb) { cb.checked = document.getElementById('selectAll').checked; });
};

function batchReject() {
    if (confirm('确定要驳回选中的合同吗？')) {
        var form = document.createElement('form');
        form.method = 'post';
        form.action = '<?= url('/import/batch-reject') ?>';

        document.querySelectorAll('input[name="ids[]"]:checked').forEach(function(cb) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'ids[]';
            input.value = cb.value;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
    }
}
</script>
```

- [ ] **Step 3: 创建审核详情页 app/Views/import/review_detail.php**

```php
<?php
$contract = $contract ?? [];
$fields = $fields ?? [];
$ocrText = $ocrText ?? '';
$files = $files ?? [];

$highThreshold = 85;
$lowThreshold = 60;
?>
<div class="page-header">
    <h1>审核合同</h1>
    <a href="<?= url('/import/review') ?>" class="btn btn-secondary">返回列表</a>
</div>

<?php if (isset($_SESSION['error'])): ?>
<div class="alert alert-danger"><?= e($_SESSION['error']) ?></div>
<?php unset($_SESSION['error']); endif; ?>

<div class="card">
    <div class="card-header">
        <h5>识别结果</h5>
    </div>
    <div class="card-body">
        <?php if (!empty($files)): ?>
        <p>
            <strong>原始文件:</strong>
            <?php foreach ($files as $file): ?>
            <a href="<?= asset_url($file['file_path']) ?>" target="_blank" class="btn btn-sm btn-info">
                <?= e($file['origin_name']) ?>
            </a>
            <?php endforeach; ?>
        </p>
        <?php endif; ?>

        <table class="table table-bordered mt-3">
            <thead>
                <tr>
                    <th>字段</th>
                    <th>识别值</th>
                    <th>置信度</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $fieldLabels = [
                    'contract_no' => '合同编号',
                    'contract_name' => '合同名称',
                    'customer_name' => '客户名称',
                    'signer_party' => '签约方',
                    'signer_name' => '签约人',
                    'phone' => '联系电话',
                    'amount' => '金额',
                    'signed_date' => '签订日期',
                    'effective_date' => '生效日期',
                    'expiry_date' => '截止日期',
                    'payment_type' => '款项类型',
                ];

                foreach ($fieldLabels as $key => $label):
                    $value = $contract[$key] ?? '';
                    $conf = $fields[$key] ?? null;
                    $confValue = is_numeric($conf) ? (float) $conf : null;
                    $confClass = $confValue === null ? 'secondary' : ($confValue >= $highThreshold ? 'success' : ($confValue >= $lowThreshold ? 'warning' : 'danger'));
                ?>
                <tr>
                    <td><strong><?= e($label) ?></strong></td>
                    <td><?= e($value) ?: '-' ?></td>
                    <td>
                        <?php if ($confValue !== null): ?>
                        <span class="badge bg-<?= $confClass ?>"><?= number_format($confValue, 1) ?>%</span>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        $lowConfCount = 0;
        foreach ($fields as $conf) {
            if (is_numeric($conf) && $conf < $lowThreshold) {
                $lowConfCount++;
            }
        }
        ?>

        <?php if ($lowConfCount > 0): ?>
        <div class="alert alert-warning mt-3">
            有 <?= $lowConfCount ?> 个字段置信度较低，请核实后保存
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="mt-3">
    <form method="post" action="<?= url('/import/reject/' . $contract['id']) ?>" style="display:inline;">
        <button type="submit" class="btn btn-danger" onclick="return confirm('确定要驳回此合同吗？')">
            驳回
        </button>
    </form>

    <form method="post" action="<?= url('/import/approve/' . $contract['id']) ?>" style="display:inline;">
        <button type="submit" class="btn btn-success">
            审核通过
        </button>
    </form>
</div>

<?php if ($ocrText): ?>
<div class="card mt-4">
    <div class="card-header">
        <h5>原始 OCR 文本</h5>
    </div>
    <div class="card-body">
        <pre style="max-height: 300px; overflow-y: auto;"><?= e($ocrText) ?></pre>
    </div>
</div>
<?php endif; ?>
```

---

## Task 8: 入口文件

**Files:**
- Create: `import.php` (重定向到新架构)

- [ ] **Step 1: 创建 import.php 入口文件**

```php
<?php
// 导入上传页入口
require_once __DIR__ . '/includes/bootstrap.php';

$controller = new ImportController();
$controller->index();
```

- [ ] **Step 2: 创建其他入口文件**

创建以下重定向文件：
- `import/review.php` → `ImportController::reviewList()`
- `import/review/{id}.php` → `ImportController::reviewDetail(id)`
- `import/process.php` → `ImportController::process()`
- `import/approve/{id}.php` → `ImportController::approve(id)`
- `import/reject/{id}.php` → `ImportController::reject(id)`
- `import/batch-approve.php` → `ImportController::batchApprove()`
- `import/batch-reject.php` → `ImportController::batchReject()`

---

## Task 9: 权限和菜单配置

**Files:**
- Modify: `includes/functions.php` - 添加 import 权限点
- Modify: `includes/layout.php` - 添加导入菜单项

- [ ] **Step 1: 在权限目录中添加 import 权限**

在 `includes/functions.php` 的 `mf_permission_catalog()` 函数中添加：

```php
// 在 mf_permission_catalog() 返回数组中添加
'import' => [
    'import.view',
    'import.create',
    'import.review',
    'import.review.edit',
],
```

- [ ] **Step 2: 在布局菜单中添加导入入口**

在 `includes/layout.php` 侧边栏菜单中添加：

```html
<li class="nav-item">
    <a class="nav-link" href="<?= url('/import') ?>">
        <i class="bi bi-upload"></i> 批量导入
    </a>
</li>
```

---

## Task 10: 测试验证

**Files:**
- 无文件变更

- [ ] **Step 1: 测试页面访问**

在浏览器中访问：
1. `http://127.0.0.1/import` - 导入上传页
2. `http://127.0.0.1/import/review` - 待审核列表页

- [ ] **Step 2: 测试功能流程**

1. 上传包含合同文件的文件夹
2. 检查是否创建了 import_jobs 记录
3. 检查是否创建了 contracts 记录
4. 检查待审核列表是否正确显示

- [ ] **Step 3: 测试审核功能**

1. 点击审核按钮进入详情页
2. 检查字段识别结果和置信度
3. 点击"审核通过"，确认合同状态变为 ongoing
4. 或点击"驳回"，确认合同被删除

---

## 验证清单

| 功能 | 验证点 |
|------|--------|
| 数据库 | contracts 表有新字段，import_jobs 和 import_files 表存在 |
| 配置 | config/config.php 包含 baidu_ocr、deepseek、import 配置 |
| 权限 | 需要 import.view 权限才能访问导入页面 |
| 菜单 | 侧边栏显示"批量导入"菜单项 |
| 导入 | 输入文件夹路径后，系统处理文件并创建合同 |
| 待审核 | 低置信度合同显示在待审核列表 |
| 审核详情 | 显示识别字段、置信度、原始文件 |
| 审核通过 | 合同状态从 pending_review 改为 ongoing |
| 审核驳回 | 合同及其附件被删除 |

---

## 注意事项

1. **API 配置**: 使用前需在 `config/config.php` 配置百度 OCR 和 DeepSeek 的 API Key
2. **PHP 扩展**: 需要 ZipArchive（用于读取 .docx）和 cURL
3. **文件权限**: `uploads/attachments/` 目录需要可写权限
4. **后台处理**: 当前实现是同步处理，大文件夹可能需要较长时间，后续可优化为异步队列
5. **.doc 格式**: 旧版 Word 格式直接走 OCR 识别，准确率可能较低

---

**Plan created:** docs/superpowers/plans/2026-05-30-contract-import-plan.md
