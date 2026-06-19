<?php
/**
 * 测试批量导入 OCR 流程（使用 Gitee AI）
 */
require_once __DIR__ . '/includes/bootstrap.php';

use App\Services\DocumentParserService;
use App\Services\DeepSeekService;
use App\Services\GiteeAIService;

// 测试文件列表
$testFiles = [
    // 正常 PDF
    'C:/Users/A/Desktop/测试合同/正常pdf/contract_01_丰谷115井地面建设工程测量设计.pdf',
    // 扫描版 PDF
    'C:/Users/A/Desktop/测试合同/扫描版pdf/contract_04_涪陵焦页88号平台页岩气集输工程设计_扫描版.pdf',
    // 图片
    'C:/Users/A/Desktop/测试合同/照片/contract_01_丰谷115井地面建设工程测量设计_扫描版_第1页.jpg',
];

echo "=== 测试批量导入 OCR 流程 ===\n\n";

$parser = new DocumentParserService();
$deepseek = new DeepSeekService();
$giteeAi = new GiteeAIService();

foreach ($testFiles as $filePath) {
    if (!file_exists($filePath)) {
        echo "文件不存在: " . basename($filePath) . "\n\n";
        continue;
    }

    echo "处理: " . basename($filePath) . "\n";
    echo "大小: " . round(filesize($filePath) / 1024, 2) . " KB\n";

    $startTime = microtime(true);
    $text = '';
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    try {
        // 判断是否需要 OCR
        $needOcr = in_array($extension, ['jpg', 'jpeg', 'png', 'webp']);

        if ($extension === 'pdf') {
            $result = $parser->parse($filePath);
            $text = $result['text'] ?? '';
            $imagePaths = $result['image_paths'] ?? [];

            // 如果有图片（扫描版 PDF），使用 Gitee AI OCR
            if (!empty($imagePaths)) {
                echo "  -> 扫描版 PDF，使用 Gitee AI OCR...\n";
                $allText = [];
                foreach ($imagePaths as $imgPath) {
                    $ocrResult = $giteeAi->ocrImage($imgPath);
                    if (!$ocrResult['error']) {
                        $allText[] = $ocrResult['text'];
                    }
                    if (file_exists($imgPath) && strpos($imgPath, sys_get_temp_dir()) !== false) {
                        @unlink($imgPath);
                    }
                }
                $text = implode("\n\n", $allText);
            } elseif (strlen(trim($text)) < 100) {
                echo "  -> 文本太少，尝试 Gitee AI OCR...\n";
                $tempDir = sys_get_temp_dir() . '/pdf_ocr_' . time();
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }
                $pythonPath = 'D:/Edge download/Python/Install/python.exe';
                $scriptPath = __DIR__ . '/scripts/render_pdf_full.py';
                $cmd = sprintf(
                    '%s %s %s %s 2>&1',
                    escapeshellarg($pythonPath),
                    escapeshellarg($scriptPath),
                    escapeshellarg($filePath),
                    escapeshellarg($tempDir)
                );
                exec($cmd, $output, $returnCode);

                $images = glob($tempDir . '/*.png') ?: glob($tempDir . '/*.jpg');
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
            } else {
                echo "  -> 正常 PDF，直接提取文本\n";
            }
        } elseif ($needOcr) {
            echo "  -> 图片，使用 Gitee AI OCR\n";
            $ocrResult = $giteeAi->ocrImage($filePath);
            if ($ocrResult['error']) {
                throw new Exception('OCR failed: ' . $ocrResult['error']);
            }
            $text = $ocrResult['text'];
        } else {
            echo "  -> 其他格式，直接解析\n";
            $result = $parser->parse($filePath);
            $text = $result['text'] ?? '';
        }

        $elapsed = round(microtime(true) - $startTime, 2);
        echo "  -> 文本长度: " . strlen($text) . " 字符\n";
        echo "  -> 耗时: {$elapsed}秒\n";

        // 提取字段
        if (!empty($text)) {
            $fields = $deepseek->extractContractFields($text);
            echo "  -> 提取字段:\n";
            echo "     - 合同编号: " . ($fields['contract_no'] ?? '无') . "\n";
            echo "     - 合同名称: " . mb_substr($fields['contract_name'] ?? '无', 0, 50) . "\n";
            echo "     - 甲方: " . mb_substr($fields['customer_name'] ?? '无', 0, 30) . "\n";
            echo "     - 乙方: " . mb_substr($fields['signer_party'] ?? '无', 0, 30) . "\n";
            echo "     - 金额: " . ($fields['amount'] ?? '无') . "\n";
        }

    } catch (Exception $e) {
        echo "  -> 错误: " . $e->getMessage() . "\n";
    }

    echo "\n" . str_repeat("-", 60) . "\n\n";
}

echo "测试完成！\n";