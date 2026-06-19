<?php
require_once __DIR__ . '/includes/bootstrap.php';

$config = require __DIR__ . '/config/siliconflow.php';

$filePath = 'C:/Users/A/Desktop/测试合同/照片/contract_01_丰谷115井地面建设工程测量设计_扫描版_第1页.jpg';

echo "=== 直接测试 DeepSeek-OCR API ===\n\n";

// 读取图片
$imageData = file_get_contents($filePath);
$base64 = base64_encode($imageData);
$mimeType = 'image/jpeg';

// 方式1: 标准视觉 API 格式
echo "方式1: 标准视觉 API 格式\n";
$data1 = [
    'model' => 'deepseek-ai/DeepSeek-OCR',
    'messages' => [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => '请识别图片中的所有文字'],
                ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]],
            ],
        ],
    ],
];

$result1 = callApi($config, $data1);
echo "结果长度: " . strlen($result1) . "\n";
echo "内容预览: " . mb_substr($result1, 0, 200) . "\n\n";

// 方式2: 简单文本格式（可能不支持图片）
echo "方式2: 尝试直接文本提示\n";
$data2 = [
    'model' => 'deepseek-ai/DeepSeek-OCR',
    'messages' => [
        ['role' => 'user', 'content' => '你好，请介绍一下你自己'],
    ],
];

$result2 = callApi($config, $data2);
echo "结果: " . $result2 . "\n\n";

// 对比: Qwen VL 模型
echo "方式3: 对比 Qwen3-VL-8B 模型\n";
$data3 = [
    'model' => 'Qwen/Qwen3-VL-8B-Instruct',
    'messages' => [
        [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => '请识别图片中的所有文字'],
                ['type' => 'image_url', 'image_url' => ['url' => "data:{$mimeType};base64,{$base64}"]],
            ],
        ],
    ],
];

$result3 = callApi($config, $data3);
echo "结果长度: " . strlen($result3) . "\n";
echo "内容预览: " . mb_substr($result3, 0, 300) . "\n";

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
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return "Error: $error";
    }

    $result = json_decode($response, true);

    if (isset($result['error'])) {
        return "API Error: " . $result['error']['message'];
    }

    return $result['choices'][0]['message']['content'] ?? '(empty)';
}