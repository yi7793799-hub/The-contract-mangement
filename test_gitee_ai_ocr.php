<?php
/**
 * 测试 Gitee AI DeepSeek-OCR-2 API
 * 官方示例格式
 */

// 填写你的 Gitee AI API Key
$apiKey = '0AEECK4OGZX9JPMCHISZQOH80FLUCJQA7J074B18';

if (empty($apiKey)) {
    echo "请填写 Gitee AI API Key\n";
    echo "获取地址: https://ai.gitee.com\n";
    exit(1);
}

$baseUrl = 'https://ai.gitee.com';
$filePath = 'C:/Users/A/Desktop/测试合同/照片/contract_01_丰谷115井地面建设工程测量设计_扫描版_第1页.jpg';

echo "=== Gitee AI DeepSeek-OCR-2 测试 ===\n\n";

// 读取图片并编码
$imageData = file_get_contents($filePath);
$base64Image = base64_encode($imageData);

// 按照官方示例格式构建请求
$requestData = [
    'messages' => [
        [
            'role' => 'system',
            'content' => 'You are a helpful and harmless assistant. You should think step-by-step.'
        ],
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
    'model' => 'DeepSeek-OCR-2',
    'stream' => false,
    'max_tokens' => 8192,
    'temperature' => 0,
    'top_p' => 1,
    'top_k' => 1,
    'frequency_penalty' => 0
];

echo "发送请求...\n";

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
    echo "\nAPI 错误: " . ($result['error']['message'] ?? json_encode($result['error'])) . "\n";
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