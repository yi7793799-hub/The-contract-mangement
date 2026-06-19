<?php
/**
 * 测试不同提示词对 SiliconFlow DeepSeek-OCR 的效果
 */
require_once __DIR__ . '/includes/bootstrap.php';

$apiKey = 'sk-aseznbkvypgobpjyxmwrfuvjgbenrzcwsotdjsivncoxtigy';
$baseUrl = 'https://api.siliconflow.cn';

$filePath = 'C:/Users/A/Desktop/测试合同/照片/contract_01_丰谷115井地面建设工程测量设计_扫描版_第1页.jpg';
$imageData = file_get_contents($filePath);
$base64Image = base64_encode($imageData);

echo "=== 测试不同提示词 ===\n\n";

// 测试不同的提示词
$prompts = [
    'Please transcribe all text from this image verbatim.',
    '请将图片中的文字逐字抄写出来',
    'Extract all text',
    '请提取图片中的全部文字内容',
];

foreach ($prompts as $i => $prompt) {
    echo "测试 " . ($i + 1) . ": $prompt\n";
    echo "发送请求...\n";

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
                        'text' => $prompt
                    ]
                ]
            ]
        ],
        'max_tokens' => 2048,  // 减少最大token来限制重复
        'temperature' => 0.0,
        'top_p' => 0.1,
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

    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    $content = $result['choices'][0]['message']['content'] ?? '';

    // 检查是否有重复
    $lines = explode("\n", $content);
    $uniqueLines = array_unique($lines);
    $repetitionRate = count($lines) > 0 ? round((count($lines) - count($uniqueLines)) / count($lines) * 100, 1) : 0;

    echo "结果长度: " . strlen($content) . " 字符\n";
    echo "行数: " . count($lines) . ", 重复率: " . $repetitionRate . "%\n";
    echo "内容预览 (前500字):\n" . mb_substr($content, 0, 500) . "\n";
    echo str_repeat("-", 50) . "\n\n";
}

// 对比 Qwen3-VL
echo "=== 对比: Qwen3-VL-8B ===\n";
$requestData = [
    'model' => 'Qwen/Qwen3-VL-8B-Instruct',
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
    'temperature' => 0.1,
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

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
$content = $result['choices'][0]['message']['content'] ?? '';

$lines = explode("\n", $content);
$uniqueLines = array_unique($lines);
$repetitionRate = count($lines) > 0 ? round((count($lines) - count($uniqueLines)) / count($lines) * 100, 1) : 0;

echo "结果长度: " . strlen($content) . " 字符\n";
echo "行数: " . count($lines) . ", 重复率: " . $repetitionRate . "%\n";
echo "内容预览:\n" . mb_substr($content, 0, 800) . "\n";