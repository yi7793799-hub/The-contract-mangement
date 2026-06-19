<?php
/**
 * Gitee AI DeepSeek-OCR-2 合同识别
 * 使用结构化提示词进行合同OCR和格式化
 */

$apiKey = '0AEECK4OGZX9JPMCHISZQOH80FLUCJQA7J074B18';
$baseUrl = 'https://ai.gitee.com';
$filePath = 'C:/Users/A/Desktop/测试合同/照片/contract_01_丰谷115井地面建设工程测量设计_扫描版_第1页.jpg';

echo "=== Gitee AI DeepSeek-OCR-2 合同结构化识别 ===\n\n";

// 读取图片
$imageData = file_get_contents($filePath);
$base64Image = base64_encode($imageData);

// 结构化提示词
$systemPrompt = <<<'PROMPT'
## 任务说明
你将接收经过OCR识别的合同原始文字，存在分行破碎、字符识别偏差、标点丢失。你的工作是清洗并结构化输出完整合同。

## 不可突破约束
1. 信息真实性优先：仅修正肉眼可判定的OCR识别错误（如0/O、1/l、公司简称错字、金额数字）；无法100%确认的文字原样保留，添加标记【疑似OCR识别偏差：原文XXX】
2. 严禁脑补、新增、删减、改写合同权利义务、金额、期限、甲乙双方主体信息
3. 不生成任何合同解读、法律分析、风险提示，仅做文本格式化

## 结构识别与输出规范（Markdown）
1. # 合同标题：提取完整合同名称
2. ## 合同基础信息
    - 甲方（全称、统一社会信用代码、地址、联系人、联系方式）
    - 乙方（同上）
    - 签订日期、签订地点、合同编号（如有）
3. ## 鉴于/前言（合同开头说明背景）
4. ## 正文条款
    按「第X条 → 第X款 → （X）项」分层，使用### 分条，段落有序列表展示细分项
5. ## 其他约定（不可抗力、违约责任、争议解决、生效条件、通知送达等独立章节）
6. ## 附件（附件名称、编号）
7. ## 签署页（甲乙双方盖章、代表人、日期栏）

## 输出限制
仅输出结构化Markdown合同文本，前置后置无额外说明文字。
PROMPT;

// 请求格式
$requestData = [
    'messages' => [
        [
            'role' => 'system',
            'content' => $systemPrompt
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
                    'text' => '请识别图片中的合同内容并按规范结构化输出'
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

echo "\n=== 结构化合同 ===\n";

if (isset($result['usage'])) {
    echo "Token 使用: Prompt=" . $result['usage']['prompt_tokens'] .
         ", Completion=" . $result['usage']['completion_tokens'] .
         ", Total=" . $result['usage']['total_tokens'] . "\n\n";
}

$content = $result['choices'][0]['message']['content'] ?? '';
echo $content . "\n";

// 保存结果
$outputFile = 'E:/The contract mangement/resource code/output/contract_structured.md';
if (!is_dir(dirname($outputFile))) {
    mkdir(dirname($outputFile), 0755, true);
}
file_put_contents($outputFile, $content);
echo "\n结果已保存到: $outputFile\n";