<?php
require_once __DIR__ . '/includes/bootstrap.php';

use App\Services\SiliconFlowService;

$filePath = 'C:/Users/A/Desktop/测试合同/照片/contract_01_丰谷115井地面建设工程测量设计_扫描版_第1页.jpg';

echo "=== 测试 DeepSeek-OCR 模型 ===\n\n";

$service = new SiliconFlowService();

// 通过反射修改私有属性 ocrModel
$reflection = new ReflectionClass($service);
$ocrModelProperty = $reflection->getProperty('ocrModel');
$ocrModelProperty->setAccessible(true);
$ocrModelProperty->setValue($service, 'deepseek-ai/DeepSeek-OCR');

echo "当前OCR模型: " . $ocrModelProperty->getValue($service) . "\n\n";

echo "正在识别图片...\n";
$startTime = microtime(true);

$result = $service->ocrImage($filePath);

$elapsed = round(microtime(true) - $startTime, 2);

echo "\n=== 结果 ===\n";
echo "耗时: {$elapsed}秒\n";
echo "错误: " . ($result['error'] ?? '无') . "\n";
echo "文本长度: " . strlen($result['text'] ?? '') . " 字符\n\n";

echo "=== 识别文本 ===\n";
echo $result['text'] ?? '(无内容)';