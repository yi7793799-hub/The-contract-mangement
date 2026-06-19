<?php
require_once __DIR__ . '/includes/bootstrap.php';

// 测试 SiliconFlow API 获取可用模型列表
$config = require __DIR__ . '/config/siliconflow.php';

$apiKey = $config['api_key'] ?? '';
$baseUrl = $config['base_url'] ?? 'https://api.siliconflow.cn';

echo "=== 测试 SiliconFlow API ===\n\n";

// 调用 models API
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $baseUrl . '/v1/models');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo "请求失败: $error\n";
} else {
    $data = json_decode($response, true);

    if (isset($data['data'])) {
        echo "可用模型列表:\n\n";

        // 筛选包含 deepseek、ocr、vl、vision 的模型
        foreach ($data['data'] as $model) {
            $id = $model['id'] ?? '';
            if (stripos($id, 'deepseek') !== false || stripos($id, 'ocr') !== false || stripos($id, 'vl') !== false || stripos($id, 'vision') !== false) {
                echo "- " . $id . "\n";
            }
        }
    } else {
        echo "响应:\n";
        echo $response . "\n";
    }
}