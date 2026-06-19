<?php
declare(strict_types=1);

namespace App\Services;

/**
 * SiliconFlow API 服务
 * 支持 DeepSeek、Qwen 等多种模型
 * API 文档: https://api-docs.siliconflow.cn/docs/api/chat-completions-post
 */
class SiliconFlowService
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $baseUrl;
    /** @var string */
    private $model;
    /** @var string */
    private $ocrModel;
    /** @var int */
    private $timeout;
    /** @var float */
    private $temperature;

    public function __construct()
    {
        $config = siliconflow_config();
        $this->apiKey = $config['api_key'] ?? '';
        $this->baseUrl = $config['base_url'] ?? 'https://api.siliconflow.cn';
        $this->model = $config['model'] ?? 'deepseek-ai/DeepSeek-V3';
        $this->ocrModel = $config['ocr_model'] ?? 'deepseek-ai/DeepSeek-OCR';
        $this->timeout = (int) ($config['timeout'] ?? 120);
        $this->temperature = (float) ($config['temperature'] ?? 0.1);
    }

    /**
     * 调用 Chat Completions API
     */
    public function chat(string $prompt, string $systemPrompt = '', array $options = []): string
    {
        $messages = [];

        if (!empty($systemPrompt)) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        $messages[] = [
            'role' => 'user',
            'content' => $prompt,
        ];

        return $this->callChatApi($messages, $options);
    }

    /**
     * 提取合同字段
     */
    public function extractContractFields(string $text): array
    {
        $systemPrompt = <<<'PROMPT'
你是一个合同信息提取专家。请从合同文本中提取以下字段，并返回JSON格式：
{
    "contract_no": "合同编号",
    "contract_name": "合同名称",
    "customer_name": "甲方/发包人名称",
    "signer_party": "乙方/设计人/承包人名称",
    "signer_name": "签约代表姓名",
    "phone": "联系电话",
    "amount": "合同含税金额（单位：万元，数字）",
    "signed_date": "签订日期（YYYY-MM-DD格式）",
    "effective_date": "生效日期（YYYY-MM-DD格式）",
    "expiry_date": "到期/终止日期（YYYY-MM-DD格式）",
    "payment_type": "收款类型（receipt付款方/outgoing付款方）",
    "confidence": {
        "contract_no": 0-100的置信度,
        "contract_name": 0-100的置信度,
        "customer_name": 0-100的置信度,
        "amount": 0-100的置信度
    }
}

提取规则：
1. 如果字段未找到，设为null
2. 金额只提取数字，去除逗号和单位
3. 日期转换为YYYY-MM-DD格式，如果只有年月则设为YYYY-MM-01
4. payment_type判断：如果当前用户角色是收款方则为receipt，否则为outgoing
5. 置信度根据文本清晰度评估，0表示完全不确定，100表示非常确定

【重要】金额提取说明：
- 提取的是合同含税总金额（包含增值税的完整金额）
- 如果合同明确标注"含税"、"税前"、"税后"，优先提取含税金额
- 如果合同有多个金额（如税前金额+税额），提取合计总金额
- 如果只标注一个金额，默认视为含税金额

【重要】金额单位转换规则（系统使用万元）：
- 合同原文为"元"：金额数字 ÷ 10000 = 万元
- 合同原文为"万元"：直接提取金额数字
- 合同原文为"佰万元"：金额数字 × 100 = 万元
- 合同原文为"千万元"：金额数字 × 1000 = 万元
- 合同原文为"亿元"：金额数字 × 10000 = 万元
示例：
  - 原文"4624568元（含税）" → 输出 462.4568
  - 原文"462.4568万元（含税价）" → 输出 462.4568
  - 原文"税前金额400万元，税额62万元，合计462万元" → 输出 462.0000
  - 原文"肆佰陆拾贰万肆仟伍佰陆拾捌元整" → 输出 462.4568

【重要】乙方信息识别说明：
在收款业务场景中，以下字段应提取乙方（承包方/服务提供方）的信息：
- signer_party（签约方）：乙方公司名称
- signer_name（签约人）：乙方法定代表人或授权代表姓名
- phone（联系电话）：乙方的联系电话

识别技巧：
1. 首先定位"甲乙双方"条款，甲方是付款方（发包方），乙方是收款方（承包方）
2. 在签署页，"乙方（盖章）"处对应的是乙方公司名称和签约人签名
3. 乙方的联系电话通常与乙方公司信息在同一区域
4. 注意区分甲乙双方的信息，不要混淆

只返回JSON，不要添加任何解释。
PROMPT;

        $response = $this->chat($text, $systemPrompt, ['max_tokens' => 2000]);

        // 解析 JSON
        $result = $this->parseJsonResponse($response);

        return $result;
    }

    /**
     * 校验合同信息
     */
    public function validateContract(array $contractData): array
    {
        $prompt = json_encode($contractData, JSON_UNESCAPED_UNICODE);

        $systemPrompt = <<<'PROMPT'
你是一个合同审核专家。请校验以下合同数据是否合理，返回JSON格式：
{
    "valid": true/false,
    "issues": ["问题1", "问题2"],
    "suggestions": ["建议1", "建议2"],
    "risk_level": "low/medium/high"
}

校验要点：
1. 合同编号格式是否规范
2. 金额是否合理（不为0或异常大）
3. 日期逻辑是否正确（生效日期≤到期日期）
4. 必要字段是否完整
5. 公司名称是否看起来真实（非乱码）

只返回JSON，不要添加任何解释。
PROMPT;

        $response = $this->chat($prompt, $systemPrompt, ['max_tokens' => 1000]);

        return $this->parseJsonResponse($response);
    }

    /**
     * OCR 识别图片（使用视觉模型）
     */
    public function ocrImage(string $imagePath): array
    {
        // 读取图片并转为 base64
        $imageData = file_get_contents($imagePath);
        $base64 = base64_encode($imageData);
        $mimeType = $this->getMimeType($imagePath);

        $ocrPrompt = <<<'PROMPT'
这是一份合同文档的扫描图片。请完整、准确地识别所有文字内容。

## 识别要求

1. **完整识别**：不要遗漏任何文字，包括标题、正文、表格、附注、签章信息等
2. **保持格式**：尽量保持原文的排版结构，表格内容用制表符分隔
3. **准确数字**：金额、日期、编号等关键数字必须准确识别，注意区分：
   - 数字0和字母O
   - 数字1和字母I/l
   - 数字8和字母B
   - 年份如"2024"不要误识别为"2029"
4. **金额格式**：保留原文格式，包括税额信息，如：
   - "肆佰陆拾贰万肆仟伍佰陆拾捌元整（含税）"
   - "4,624,568.00元（含税价）"
   - "税前金额400万元，税额62万元，合计462万元"
   - 如有多个金额（税前、税额、合计），全部识别
5. **日期格式**：保留原文格式，如"2024年5月10日"或"二〇二四年五月十日"
6. **公司名称**：完整识别公司全称，不要遗漏"有限公司"、"股份有限公司"等后缀
7. **签章信息**：识别签名、盖章、日期等签署信息

## 输出格式
直接输出识别的文字内容，保持原文排版。不要添加任何解释、注释或额外内容。

特别注意以下关键信息，务必准确识别：
- 合同编号（通常在首页标题附近）
- 合同含税金额（如果有税前税额信息，识别合计总金额）
- 签订日期（通常在签署页）
- 甲乙双方名称（发包方/承包方）
- 税率信息（如"税率6%"、"增值税"等）
PROMPT;

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => $ocrPrompt,
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$mimeType};base64,{$base64}",
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = $this->callVisionApi($messages, ['max_tokens' => 8000]);
            return [
                'text' => $response,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'text' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * 调用 Chat API
     */
    private function callChatApi(array $messages, array $options = []): string
    {
        $url = $this->baseUrl . '/v1/chat/completions';

        $data = [
            'model' => $options['model'] ?? $this->model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? $this->temperature,
            'max_tokens' => $options['max_tokens'] ?? 4000,
        ];

        $response = $this->httpPost($url, $data);
        $result = json_decode($response, true);

        if (isset($result['error'])) {
            throw new \Exception('SiliconFlow API error: ' . ($result['error']['message'] ?? 'Unknown error'));
        }

        return $result['choices'][0]['message']['content'] ?? '';
    }

    /**
     * 获取文件MIME类型
     */
    private function getMimeType(string $filePath): string
    {
        if (function_exists('mime_content_type')) {
            return mime_content_type($filePath);
        }

        if (class_exists('finfo')) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            return $finfo->file($filePath);
        }

        // 根据扩展名返回默认类型
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $types = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        ];
        return $types[$ext] ?? 'application/octet-stream';
    }

    /**
     * 调用视觉模型 API (OCR)
     */
    private function callVisionApi(array $messages, array $options = []): string
    {
        $url = $this->baseUrl . '/v1/chat/completions';

        $data = [
            'model' => $this->ocrModel,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? 8000,
        ];

        $response = $this->httpPost($url, $data);
        $result = json_decode($response, true);

        if (isset($result['error'])) {
            throw new \Exception('SiliconFlow Vision API error: ' . ($result['error']['message'] ?? 'Unknown error'));
        }

        return $result['choices'][0]['message']['content'] ?? '';
    }

    /**
     * 解析 JSON 响应
     */
    private function parseJsonResponse(string $response): array
    {
        // 尝试直接解析
        $result = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $result;
        }

        // 尝试提取 JSON 部分
        if (preg_match('/\{[\s\S]*\}/m', $response, $matches)) {
            $result = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $result;
            }
        }

        return ['error' => 'Failed to parse JSON response', 'raw' => $response];
    }

    /**
     * HTTP POST 请求
     */
    private function httpPost(string $url, array $data): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apiKey,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception('HTTP request failed: ' . $error);
        }

        return $response;
    }
}