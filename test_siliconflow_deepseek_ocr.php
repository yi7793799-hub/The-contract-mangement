<?php
/**
 * 测试 SiliconFlow DeepSeek-OCR API 标准调用
 * 参考: https://api-docs.siliconflow.cn/docs/api/chat-completions-post
 */
require_once __DIR__ . '/includes/bootstrap.php';

$apiKey = 'sk-aseznbkvypgobpjyxmwrfuvjgbenrzcwsotdjsivncoxtigy';
$baseUrl = 'https://api.siliconflow.cn';

$filePath = 'C:/Users/A/Desktop/测试合同/照片/contract_01_丰谷115井地面建设工程测量设计_扫描版_第1页.jpg';

echo "=== SiliconFlow DeepSeek-OCR 标准调用测试 ===\n\n";

// 读取图片并编码
$imageData = file_get_contents($filePath);
$base64Image = base64_encode($imageData);

// 标准 OpenAI 兼容格式调用
$requestData = [
    'model' => 'deepseek-ai/DeepSeek-OCR',
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
                    'text' => 'OCR'
                ]
            ]
        ]
    ],
    'max_tokens' => 4096,
    'temperature' => 0.1
];

echo "请求格式:\n";
echo json_encode($requestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
echo "发送请求...\n";

// 发送请求
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
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "HTTP状态码: $httpCode\n";

if ($error) {
    echo "CURL错误: $error\n";
    exit(1);
}

echo "\n=== 响应内容 ===\n";
$result = json_decode($response, true);

if (isset($result['error'])) {
    echo "API错误: " . $result['error']['message'] . "\n";
    echo "错误类型: " . $result['error']['type'] . "\n";
    echo "完整响应:\n";
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "成功!\n\n";

    if (isset($result['usage'])) {
        echo "Token使用:\n";
        echo "  - Prompt tokens: " . $result['usage']['prompt_tokens'] . "\n";
        echo "  - Completion tokens: " . $result['usage']['completion_tokens'] . "\n";
        echo "  - Total tokens: " . $result['usage']['total_tokens'] . "\n\n";
    }

    $content = $result['choices'][0]['message']['content'] ?? '';
    echo "识别结果长度: " . strlen($content) . " 字符\n";
    echo "\n=== OCR文本内容 ===\n";
    echo $content . "\n";
}