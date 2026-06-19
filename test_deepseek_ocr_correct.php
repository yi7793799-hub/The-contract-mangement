<?php
require_once __DIR__ . '/includes/bootstrap.php';

$config = require __DIR__ . '/config/siliconflow.php';

$filePath = 'C:/Users/A/Desktop/测试合同/照片/contract_01_丰谷115井地面建设工程测量设计_扫描版_第1页.jpg';

echo "=== 测试 DeepSeek-OCR 正确提示词格式 ===\n\n";

$imageData = file_get_contents($filePath);
$base64 = base64_encode($imageData);
$mimeType = 'image/jpeg';

// 根据官方文档的正确提示词格式
$prompts = [
    // 纯文本OCR（无布局）
    '<image>\nFree OCR.',
    // 文档识别转markdown（带布局）
    '<image>\n<|grounding|>Convert the document to markdown.',
    // 图片OCR
    '<image>\n<|grounding|>OCR this image.',
];

foreach ($prompts as $i => $prompt) {
    echo "提示词 " . ($i + 1) . ": " . str_replace('\n', '\\n', $prompt) . "\n";

    $data = [
        'model' => 'deepseek-ai/DeepSeek-OCR',
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]],
                ],
            ],
        ],
        'max_tokens' => 8192,
        'temperature' => 0.0,
    ];

    $result = callApi($config, $data);
    echo "结果长度: " . strlen($result) . "\n";
    echo "内容预览:\n" . mb_substr($result, 0, 800) . "\n\n";
    echo str_repeat("-", 60) . "\n\n";
}

// 对比 Qwen3-VL
echo "=== 对比 Qwen3-VL-8B ===\n";
$data = [
    'model' => 'Qwen/Qwen3-VL-8B-Instruct',
    'messages' => [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => '请识别图片中的所有中文文字，保持原文格式输出'],
                ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]],
            ],
        ],
    ],
    'max_tokens' => 4096,
];

$result = callApi($config, $data);
echo "结果长度: " . strlen($result) . "\n";
echo "内容预览:\n" . mb_substr($result, 0, 800) . "\n";

function callApi($config, $data) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $config['base_url'] . '/v1/chat/completions');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $config['api_key'],
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return "CURL Error: $error";
    }

    $result = json_decode($response, true);

    if (isset($result['error'])) {
        return "API Error: " . $result['error']['message'];
    }

    return $result['choices'][0]['message']['content'] ?? '(empty)';
}