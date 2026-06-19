<?php
/**
 * 使用 Gitee AI DeepSeek-OCR-2 识别扫描版 PDF 合同
 */
require_once __DIR__ . '/includes/bootstrap.php';

use App\Services\GiteeAIService;

$filePath = 'C:/Users/A/Desktop/测试合同/扫描版pdf/合同1.pdf';

echo "=== 扫描版 PDF 合同 OCR 识别 ===\n\n";

// 检查文件
if (!file_exists($filePath)) {
    echo "错误: 文件不存在\n";
    exit(1);
}

echo "文件: " . basename($filePath) . "\n";
echo "大小: " . round(filesize($filePath) / 1024, 2) . " KB\n\n";

// 使用 Python 脚本将 PDF 转为图片
echo "1. 将 PDF 转换为图片...\n";

$pythonPath = 'D:/Edge download/Python/Install/python.exe';
$scriptPath = __DIR__ . '/scripts/render_pdf_full.py';
$outputDir = __DIR__ . '/output/pdf_images_' . time();

// 确保输出目录存在
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$cmd = sprintf(
    '%s %s %s %s 2>&1',
    escapeshellarg($pythonPath),
    escapeshellarg($scriptPath),
    escapeshellarg($filePath),
    escapeshellarg($outputDir)
);

echo "执行命令...\n";
exec($cmd, $output, $returnCode);

if ($returnCode !== 0) {
    echo "PDF 转图片失败:\n";
    echo implode("\n", $output) . "\n";
}

// 查找生成的图片
$images = glob($outputDir . '/*.png');
if (empty($images)) {
    $images = glob($outputDir . '/*.jpg');
}

if (empty($images)) {
    echo "错误: 未生成图片\n";
    exit(1);
}

sort($images);
echo "生成 " . count($images) . " 张图片\n\n";

// 使用 Gitee AI 识别每张图片
echo "2. 使用 Gitee AI DeepSeek-OCR-2 识别...\n";
echo str_repeat("-", 50) . "\n\n";

$giteeService = new GiteeAIService();
$allText = [];
$totalTime = 0;

foreach ($images as $i => $imgPath) {
    $pageNum = $i + 1;
    echo "第 {$pageNum} 页: ";

    $startTime = microtime(true);
    $result = $giteeService->ocrImage($imgPath);
    $elapsed = round(microtime(true) - $startTime, 2);
    $totalTime += $elapsed;

    if ($result['error']) {
        echo "错误 - " . $result['error'] . "\n";
    } else {
        $textLen = strlen($result['text']);
        echo "成功 ({$elapsed}秒, {$textLen}字符)\n";
        $allText[] = "--- 第{$pageNum}页 ---\n" . $result['text'];
    }
}

echo "\n总耗时: " . round($totalTime, 2) . "秒\n";
echo "总文本: " . strlen(implode("\n\n", $allText)) . " 字符\n\n";

// 合并所有文本
$fullText = implode("\n\n", $allText);

// 保存结果
$outputFile = __DIR__ . '/output/合同1_full_text.md';
file_put_contents($outputFile, $fullText);
echo "完整文本已保存到: $outputFile\n\n";

// 显示完整内容
echo "=== 完整识别内容 ===\n";
echo str_repeat("=", 60) . "\n\n";
echo $fullText . "\n";

// 清理临时图片
echo "\n清理临时图片...\n";
foreach ($images as $imgPath) {
    @unlink($imgPath);
}
@rmdir($outputDir);
echo "完成！\n";