<?php
require_once __DIR__ . '/includes/bootstrap.php';

$config = require __DIR__ . '/config/siliconflow.php';

$filePath = 'C:/Users/A/Desktop/测试合同/照片/contract_01_丰谷115井地面建设工程测量设计_扫描版_第1页.jpg';

echo "=== 测试 DeepSeek-OCR 中文提示词 ===\n\n";

$imageData = file_get_contents($filePath);
$base64 = base64_encode($imageData);
$mimeType = 'image/jpeg';

$prompts = [
    '请逐字识别图片中的所有中文文字，保持原文格式输出',
    '这是一份中文合同，请完整识别所有文字内容，直接输出原文，不要翻译',
    'OCR识别：请识别图片中的全部文字，逐字输出，不要遗漏任何内容',
];

foreach ($prompts as $i => $prompt) {
    echo "提示词 " . ($i + 1) . ": $prompt\n";

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
        'max_tokens' => 8000,
    ];

    $result = callApi($config, $data);
    echo "结果长度: " . strlen($result) . "\n";
    echo "内容预览: " . mb_substr($result, 0, 500) . "\n\n";
    echo str_repeat("-", 50) . "\n\n";
}

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
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    return $result['choices'][0]['message']['content'] ?? '(empty)';
}