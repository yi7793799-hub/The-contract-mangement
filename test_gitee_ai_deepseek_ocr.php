<?php
/**
 * 测试 Gitee AI (模力方舟) DeepSeek-OCR-2 API
 *
 * 文档: https://ai.gitee.com/docs/openapi/v1
 * 模型: DeepSeek-OCR-2
 *
 * OpenAI 兼容格式
 * 端点: https://ai.gitee.com/v1/chat/completions
 */

// Gitee AI API Key - 需要在 https://ai.gitee.com 获取
$apiKey = getenv('GITEE_AI_API_KEY') ?: '';

if (empty($apiKey)) {
    echo "请设置 GITEE_AI_API_KEY 环境变量，或直接在脚本中填写 API Key\n";
    echo "获取 API Key: https://ai.gitee.com\n";
    exit(1);
}

$baseUrl = 'https://ai.gitee.com';
$filePath = 'C:/Users/A/Desktop/测试合同/照片/contract_01_丰谷115井地面建设工程测量设计_扫描版_第1页.jpg';

echo "=== Gitee AI DeepSeek-OCR-2 测试 ===\n\n";

// 读取图片
$imageData = file_get_contents($filePath);
$base64Image = base64_encode($imageData);

// OpenAI 兼容格式请求
$requestData = [
    'model' => 'DeepSeek-OCR-2',
    'messages' => [
        [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => 'data:image/jpeg;base64,' . $base64Image
                    ]
                ],
                [
                    'type' => 'text',
                    'text' => '请识别图片中的所有中文文字，保持原文格式输出'
                ]
            ]
        ]
    ],
    'max_tokens' => 8192,
    'temperature' => 0.1
];

echo "发送请求到: $baseUrl/v1/chat/completions\n";
echo "模型: DeepSeek-OCR-2\n\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . '/v1/chat/completions');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
curl_setopt($ch, CURLOPT_TIMEOUT, 180);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$startTime = microtime(true);
$response = curl_exec($ch);
$elapsed = round(microtime(true) - $startTime, 2);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "耗时: {$elapsed}秒\n";
echo "HTTP 状态码: $httpCode\n";

if ($error) {
    echo "CURL 错误: $error\n";
    exit(1);
}

$result = json_decode($response, true);

if (isset($result['error'])) {
    echo "\nAPI 错误: " . $result['error']['message'] . "\n";
    echo "完整响应:\n";
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "\n=== 识别结果 ===\n";

    if (isset($result['usage'])) {
        echo "Token 使用:\n";
        echo "  - Prompt tokens: " . $result['usage']['prompt_tokens'] . "\n";
        echo "  - Completion tokens: " . $result['usage']['completion_tokens'] . "\n";
        echo "  - Total tokens: " . $result['usage']['total_tokens'] . "\n\n";
    }

    $content = $result['choices'][0]['message']['content'] ?? '';
    echo "结果长度: " . strlen($content) . " 字符\n\n";
    echo "=== OCR 文本内容 ===\n";
    echo $content . "\n";
}