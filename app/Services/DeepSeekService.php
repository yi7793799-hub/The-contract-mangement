<?php
declare(strict_types=1);

namespace App\Services;

class DeepSeekService
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $model;

    public function __construct()
    {
        $config = deepseek_config();
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'deepseek-chat';
    }

    /**
     * 从合同文本提取关键字段
     */
    public function extractContractFields(string $text): array
    {
        $prompt = $this->buildExtractPrompt($text);
        $response = $this->chat([
            ['role' => 'system', 'content' => '你是一个专业的合同信息提取助手。从合同文本中提取关键信息，并以JSON格式返回。'],
            ['role' => 'user', 'content' => $prompt],
        ]);

        return $this->parseJsonResponse($response);
    }

    /**
     * 构建字段提取 Prompt
     */
    private function buildExtractPrompt(string $text): string
    {
        return <<<EOT
请从以下合同文本中提取关键信息，返回JSON格式。如果某字段无法提取，设为null。

必须返回的JSON格式：
{
    "contract_no": "合同编号，如HT-2026-001",
    "contract_name": "合同名称",
    "customer_name": "客户名称",
    "signer_party": "签约方",
    "signer_name": "签约人姓名",
    "phone": "联系电话",
    "amount": "合同金额（数字）",
    "signed_date": "签订日期（YYYY-MM-DD格式）",
    "effective_date": "生效日期（YYYY-MM-DD格式）",
    "expiry_date": "截止日期（YYYY-MM-DD格式）",
    "payment_type": "款项类型，receipt表示收款，payment表示付款",
    "confidence": {
        "contract_no": 置信度0-100,
        "contract_name": 置信度0-100,
        "customer_name": 置信度0-100,
        "signer_party": 置信度0-100,
        "signer_name": 置信度0-100,
        "phone": 置信度0-100,
        "amount": 置信度0-100,
        "signed_date": 置信度0-100,
        "effective_date": 置信度0-100,
        "expiry_date": 置信度0-100,
        "payment_type": 置信度0-100
    }
}

合同文本如下：
{$text}

只返回JSON，不要有其他内容。
EOT;
    }

    /**
     * 调用 Chat API
     */
    public function chat(array $messages): string
    {
        $url = 'https://api.deepseek.com/v1/chat/completions';

        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.1,
        ];

        $response = $this->httpPost($url, $data);

        $result = json_decode($response, true);

        if (isset($result['error'])) {
            throw new Exception('DeepSeek API error: ' . ($result['error']['message'] ?? 'Unknown error'));
        }

        return $result['choices'][0]['message']['content'] ?? '';
    }

    /**
     * 解析 JSON 响应
     */
    private function parseJsonResponse(string $response): array
    {
        // 尝试提取 JSON 部分
        if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
            $data = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $data;
            }
        }

        throw new Exception('Failed to parse DeepSeek response as JSON');
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('HTTP request failed: ' . $error);
        }

        return $response;
    }
}
