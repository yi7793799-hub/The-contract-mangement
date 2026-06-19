<?php
/**
 * 合同 OCR 识别示例
 * 演示如何使用 Gitee AI DeepSeek-OCR-2 进行合同识别
 */
require_once __DIR__ . '/includes/bootstrap.php';

use App\Services\ContractOcrService;
use App\Services\GiteeAIService;

// 图片路径
$filePath = 'C:/Users/A/Desktop/测试合同/照片/contract_01_丰谷115井地面建设工程测量设计_扫描版_第1页.jpg';

echo "=== 合同 OCR 识别示例 ===\n\n";

// 方式 1: 直接使用 GiteeAIService
echo "【方式 1】直接使用 GiteeAIService\n";
echo str_repeat("-", 50) . "\n\n";

$giteeService = new GiteeAIService();

// 测试连接
echo "1. 测试 API 连接...\n";
$testResult = $giteeService->testConnection();
if ($testResult['success']) {
    echo "   连接成功！\n\n";
} else {
    echo "   连接失败: " . $testResult['error'] . "\n\n";
}

// 纯 OCR 识别
echo "2. 纯 OCR 识别...\n";
$startTime = microtime(true);
$ocrResult = $giteeService->ocrImage($filePath);
$elapsed = round(microtime(true) - $startTime, 2);
echo "   耗时: {$elapsed}秒\n";
if ($ocrResult['error']) {
    echo "   错误: " . $ocrResult['error'] . "\n";
} else {
    echo "   成功！文本长度: " . strlen($ocrResult['text']) . " 字符\n";
}
echo "\n";

// 结构化合同识别
echo "3. 结构化合同识别...\n";
$startTime = microtime(true);
$structuredResult = $giteeService->ocrContractStructured($filePath);
$elapsed = round(microtime(true) - $startTime, 2);
echo "   耗时: {$elapsed}秒\n";
if ($structuredResult['error']) {
    echo "   错误: " . $structuredResult['error'] . "\n";
} else {
    echo "   成功！文本长度: " . strlen($structuredResult['text']) . " 字符\n";
}
echo "\n";

// 方式 2: 使用 ContractOcrService
echo "【方式 2】使用 ContractOcrService\n";
echo str_repeat("-", 50) . "\n\n";

$contractService = new ContractOcrService();

// 显示当前 OCR 提供商
echo "当前 OCR 提供商: " . $contractService->getOcrProvider() . "\n\n";

// 使用 Gitee AI 进行图片 OCR
echo "使用 Gitee AI 进行图片 OCR...\n";
$startTime = microtime(true);
$result = $contractService->ocrImageWithGitee($filePath);
$elapsed = round(microtime(true) - $startTime, 2);
echo "耗时: {$elapsed}秒\n";

if ($result['error']) {
    echo "错误: " . $result['error'] . "\n";
} else {
    echo "成功！文本长度: " . strlen($result['text']) . " 字符\n\n";

    // 保存结果
    $outputDir = __DIR__ . '/output';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }

    $outputFile = $outputDir . '/contract_ocr_result.md';
    file_put_contents($outputFile, $result['text']);
    echo "结果已保存到: $outputFile\n\n";

    // 显示预览
    echo "=== 文本预览 ===\n";
    echo mb_substr($result['text'], 0, 500) . "...\n";
}

// 方式 3: 切换 OCR 提供商
echo "\n【方式 3】切换 OCR 提供商\n";
echo str_repeat("-", 50) . "\n\n";

// 切换到 SiliconFlow
$contractService->setOcrProvider('siliconflow');
echo "已切换到: " . $contractService->getOcrProvider() . "\n";

// 切换回 Gitee
$contractService->setOcrProvider('gitee');
echo "已切换到: " . $contractService->getOcrProvider() . "\n";