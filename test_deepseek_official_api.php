<?php
/**
 * 测试 DeepSeek 官方 API
 * 需要设置 DEEPSEEK_API_KEY 环境变量或在下方填写
 */

// 在这里填写你的 DeepSeek API Key
$apiKey = getenv('DEEPSEEK_API_KEY') ?: 'sk-6dacb6333750454192ed4f61eb57e633';

if (empty($apiKey)) {
    echo "请设置 DEEPSEEK_API_KEY 环境变量，或直接在脚本中填写 API Key\n";
    echo "获取 API Key: https://platform.deepseek.com/api_keys\n";
    exit(1);
}

$baseUrl = 'https://api.deepseek.com';
$filePath = 'C:/Users/A/Desktop/测试合同/照片/contract_01_丰谷115井地面建设工程测量设计_扫描版_第1页.jpg';

echo "=== DeepSeek 官方 API 测试 ===\n\n";

// 读取图片
$imageData = file_get_contents($filePath);
$base64Image = base64_encode($imageData);

// 1. 获取可用模型列表
echo "1. 获取可用模型列表...\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . '/v1/models');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $apiKey,
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
curl_close($ch);

$models = json_decode($response, true);
if (isset($models['data'])) {
    echo "可用模型:\n";
    foreach ($models['data'] as $model) {
        echo "  - " . $model['id'] . "\n";
    }
}
echo "\n";

// 2. 测试视觉模型 (如果有)
echo "2. 测试视觉识别...\n";

// DeepSeek 视觉 API 格式
$requestData = [
    'model' => 'deepseek-chat',  // DeepSeek 主要使用 deepseek-chat
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
    'max_tokens' => 4096,
    'temperature' => 0.1
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . '/v1/chat/completions');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
curl_setopt($ch, CURLOPT_TIMEOUT, 120);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

echo "发送请求...\n";
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "CURL 错误: $error\n";
    exit(1);
}

echo "HTTP 状态码: $httpCode\n";

$result = json_decode($response, true);

if (isset($result['error'])) {
    echo "API 错误: " . $result['error']['message'] . "\n";
    echo "完整响应: " . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
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
    echo "内容:\n";
    echo $content . "\n";
}