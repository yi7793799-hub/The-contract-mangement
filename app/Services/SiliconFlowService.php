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
    "amount": "合同金额（数字，单位元）",
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

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => '请识别这张图片中的所有文字内容，保持原文格式输出。这是一份合同文档，请完整准确地识别所有文字，包括标题、正文、表格、金额、日期等。只输出识别的文字，不要添加任何解释。',
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