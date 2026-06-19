<?php
/**
 * 测试 GiteeAIService
 */
require_once __DIR__ . '/includes/bootstrap.php';

use App\Services\GiteeAIService;

$filePath = 'C:/Users/A/Desktop/测试合同/照片/contract_01_丰谷115井地面建设工程测量设计_扫描版_第1页.jpg';

echo "=== 测试 GiteeAIService ===\n\n";

$service = new GiteeAIService();

// 1. 测试连接
echo "1. 测试 API 连接...\n";
$testResult = $service->testConnection();
if ($testResult['success']) {
    echo "   连接成功！可用模型数量: " . count($testResult['models']) . "\n";
    // 显示 OCR 相关模型
    $ocrModels = array_filter($testResult['models'], function($m) {
        return stripos($m, 'ocr') !== false || stripos($m, 'vl') !== false;
    });
    echo "   OCR/VL 模型: " . implode(', ', $ocrModels) . "\n\n";
} else {
    echo "   连接失败: " . $testResult['error'] . "\n\n";
}

// 2. 测试纯 OCR
echo "2. 测试纯 OCR 识别...\n";
$startTime = microtime(true);
$ocrResult = $service->ocrImage($filePath);
$elapsed = round(microtime(true) - $startTime, 2);

echo "   耗时: {$elapsed}秒\n";
if ($ocrResult['error']) {
    echo "   错误: " . $ocrResult['error'] . "\n";
} else {
    echo "   成功！文本长度: " . strlen($ocrResult['text']) . " 字符\n";
    echo "   内容预览:\n";
    echo "   " . mb_substr($ocrResult['text'], 0, 300) . "...\n\n";
}

// 3. 测试结构化 OCR
echo "3. 测试结构化合同识别...\n";
$startTime = microtime(true);
$structuredResult = $service->ocrContractStructured($filePath);
$elapsed = round(microtime(true) - $startTime, 2);

echo "   耗时: {$elapsed}秒\n";
if ($structuredResult['error']) {
    echo "   错误: " . $structuredResult['error'] . "\n";
} else {
    echo "   成功！文本长度: " . strlen($structuredResult['text']) . " 字符\n\n";
    echo "   === 结构化结果 ===\n";
    echo $structuredResult['text'] . "\n";
}

// 保存结果
$outputDir = __DIR__ . '/output';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

if (!$ocrResult['error']) {
    file_put_contents($outputDir . '/gitee_ocr_result.md', $ocrResult['text']);
    echo "\n纯 OCR 结果已保存到: output/gitee_ocr_result.md\n";
}

if (!$structuredResult['error']) {
    file_put_contents($outputDir . '/gitee_structured_result.md', $structuredResult['text']);
    echo "结构化结果已保存到: output/gitee_structured_result.md\n";
}