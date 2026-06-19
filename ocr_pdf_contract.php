<?php
/**
 * 使用 Gitee AI DeepSeek-OCR-2 识别 PDF 合同
 */
require_once __DIR__ . '/includes/bootstrap.php';

use App\Services\GiteeAIService;
use App\Services\DocumentParserService;

$filePath = 'C:/Users/A/Desktop/测试合同/正常pdf/contract_01_丰谷115井地面建设工程测量设计.pdf';

echo "=== PDF 合同 OCR 识别 ===\n\n";

// 检查文件
if (!file_exists($filePath)) {
    echo "错误: 文件不存在\n";
    exit(1);
}

echo "文件: " . basename($filePath) . "\n";
echo "大小: " . round(filesize($filePath) / 1024, 2) . " KB\n\n";

// 方法 1: 使用 DocumentParserService 将 PDF 转为图片，然后用 Gitee AI 识别
echo "【方法 1】PDF 转图片后 OCR\n";
echo str_repeat("-", 50) . "\n\n";

$parser = new DocumentParserService();
$giteeService = new GiteeAIService();

echo "1. 解析 PDF...\n";
$startTime = microtime(true);
$parseResult = $parser->parse($filePath);
$parseElapsed = round(microtime(true) - $startTime, 2);

echo "   解析耗时: {$parseElapsed}秒\n";

if (isset($parseResult['error']) && $parseResult['error']) {
    echo "   解析错误: " . $parseResult['error'] . "\n";
}

$text = $parseResult['text'] ?? '';
$pages = $parseResult['pages'] ?? 1;
$imagePaths = $parseResult['image_paths'] ?? [];

echo "   页数: {$pages}\n";
echo "   提取文本长度: " . strlen($text) . " 字符\n";
echo "   图片数量: " . count($imagePaths) . "\n\n";

// 如果有图片，使用 Gitee AI 进行 OCR
if (!empty($imagePaths)) {
    echo "2. 使用 Gitee AI 识别图片...\n";
    $allText = [];
    $totalOcrTime = 0;

    foreach ($imagePaths as $i => $imgPath) {
        echo "   识别第 " . ($i + 1) . " 页...\n";
        $startTime = microtime(true);
        $ocrResult = $giteeService->ocrImage($imgPath);
        $ocrElapsed = round(microtime(true) - $startTime, 2);
        $totalOcrTime += $ocrElapsed;

        if ($ocrResult['error']) {
            echo "      错误: " . $ocrResult['error'] . "\n";
        } else {
            echo "      成功: " . strlen($ocrResult['text']) . " 字符 ({$ocrElapsed}秒)\n";
            $allText[] = $ocrResult['text'];
        }

        // 清理临时图片
        if (file_exists($imgPath) && strpos($imgPath, sys_get_temp_dir()) !== false) {
            @unlink($imgPath);
        }
    }

    echo "\n   OCR 总耗时: " . round($totalOcrTime, 2) . "秒\n";
    echo "   总文本长度: " . strlen(implode("\n\n", $allText)) . " 字符\n\n";

    // 合并所有页面的文本
    $fullText = implode("\n\n" . str_repeat("=", 50) . "\n\n", $allText);

    // 保存结果
    $outputDir = __DIR__ . '/output';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $outputFile = $outputDir . '/contract_01_full_text.md';
    file_put_contents($outputFile, $fullText);
    echo "完整文本已保存到: $outputFile\n\n";

    // 显示预览
    echo "=== 文本预览 ===\n";
    echo mb_substr($fullText, 0, 1000) . "...\n";
} else {
    // 如果没有图片，直接使用解析出来的文本
    echo "2. PDF 直接提取的文本:\n";
    echo str_repeat("-", 50) . "\n";
    echo mb_substr($text, 0, 2000) . "...\n";

    // 保存
    $outputDir = __DIR__ . '/output';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    $outputFile = $outputDir . '/contract_01_full_text.md';
    file_put_contents($outputFile, $text);
    echo "\n文本已保存到: $outputFile\n";
}
