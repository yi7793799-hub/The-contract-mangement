<?php
/**
 * Gitee AI DeepSeek-OCR-2 纯OCR识别
 */

$apiKey = '0AEECK4OGZX9JPMCHISZQOH80FLUCJQA7J074B18';
$baseUrl = 'https://ai.gitee.com';
$filePath = 'C:/Users/A/Desktop/测试合同/照片/contract_01_丰谷115井地面建设工程测量设计_扫描版_第1页.jpg';

echo "=== Gitee AI DeepSeek-OCR-2 纯OCR识别 ===\n\n";

// 读取图片
$imageData = file_get_contents($filePath);
$base64Image = base64_encode($imageData);

// 简单提示词 - 只要求OCR
$requestData = [
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
                    'text' => '<image>\nFree OCR.'
                ]
            ]
        ]
    ],
    'model' => 'DeepSeek-OCR-2',
    'stream' => false,
    'max_tokens' => 4096,
    'temperature' => 0,
    'top_p' => 1,
    'top_k' => 1,
    'frequency_penalty' => 0
];

echo "发送请求到 Gitee AI...\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . '/v1/chat/completions');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
curl_setopt($ch, CURLOPT_TIMEOUT, 300);
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
    echo "\nAPI 错误: " . ($result['error']['message'] ?? json_encode($result['error'], JSON_UNESCAPED_UNICODE)) . "\n";
    exit(1);
}

echo "\n=== OCR 结果 ===\n";

if (isset($result['usage'])) {
    echo "Token 使用: Prompt=" . $result['usage']['prompt_tokens'] .
         ", Completion=" . $result['usage']['completion_tokens'] .
         ", Total=" . $result['usage']['total_tokens'] . "\n\n";
}

$content = $result['choices'][0]['message']['content'] ?? '';
echo "结果长度: " . strlen($content) . " 字符\n\n";
echo $content . "\n";
