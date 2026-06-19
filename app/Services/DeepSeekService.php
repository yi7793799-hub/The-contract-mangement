<?php
declare(strict_types=1);

namespace App\Services;

class DeepSeekService
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $model;
    /** @var string */
    private $baseUrl;
    /** @var float */
    private $temperature;
    /** @var int */
    private $timeout;

    public function __construct()
    {
        $config = deepseek_config();
        $this->apiKey = $config['api_key'] ?? '';
        $this->model = $config['model'] ?? 'deepseek-v4-pro';
        $this->baseUrl = $config['base_url'] ?? 'https://api.deepseek.com';
        $this->temperature = (float) ($config['temperature'] ?? 0.1);
        $this->timeout = (int) ($config['timeout'] ?? 60);
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
你是一个专业的合同信息提取专家。请从合同文本中精确提取关键信息，并以JSON格式返回。

## 字段提取规则

### 合同编号 (contract_no)
- 格式特征：通常为"数字-字母-数字"或"HT-年份-编号"格式
- 常见位置：合同首页标题附近、右上角
- 示例：93810-24AJHZX6-2424、HT-2024-001、XMHT-2024-0123
- 置信度评估：明确标注"合同编号"字样=90+；根据格式推断=70-89；无法确定=50-69

### 合同名称 (contract_name)
- 格式特征：包含"合同"二字，通常描述合同性质
- 常见关键词：勘察、设计、施工、采购、服务、工程、建设等
- 如果原文没有明确名称，根据合同内容推断一个合理的名称
- 置信度评估：原文明确标注=90+；根据内容推断=70-89；模糊推断=50-69

### 客户名称/甲方 (customer_name)
- 身份：通常是发包方、甲方、委托方
- 格式特征：公司全称，可能带"有限公司"、"股份公司"等后缀
- 查找关键词：发包人、甲方、委托方、业主、招标人
- 置信度评估：明确标注对应关系=90+；根据上下文推断=70-89

### 签约方/乙方 (signer_party) 【重要：这是合同中乙方的信息】
- **身份识别**：乙方是合同的承包方、受托方、服务提供方
- **查找关键词**：承包人、乙方、设计人、施工方、受托方、服务商、承揽人
- **格式特征**：公司全称，必须完整提取
- **特别注意**：
  - 在合同签署区域，乙方的公司名称通常与法定代表人/授权代表签名并列
  - 乙方的联系信息（地址、电话）通常紧随乙方名称之后
- **常见位置**：
  1. 合同首页的"甲乙双方"条款中
  2. 合同签署页的"乙方（盖章）"处
- 置信度评估：明确标注"乙方/承包人"=90+；根据上下文推断=70-89

### 签约人姓名 (signer_name) 【重要：这是乙方签约人的信息】
- **身份识别**：乙方的法定代表人或授权代表
- **位置**：合同签署页，通常在"乙方（盖章）"附近的签名栏
- **关联信息**：
  - 通常与乙方公司名称在同一区域
  - 可能有"法定代表人"、"授权代表"、"委托代理人"等标注
- **区分**：不要与甲方的签约人混淆，只提取乙方这方的签约人姓名
- 置信度评估：明确标注"法定代表人/授权代表"=90+；签名处姓名=70-89

### 联系电话 (phone) 【重要：这是乙方的联系电话】
- **归属**：乙方的联系电话，非甲方电话
- **位置**：
  1. 合同首页乙方信息区域
  2. 签署页乙方签名栏附近
  3. 合同中的"乙方联系方式"条款
- **格式**：手机号11位，座机带区号
- **区分**：合同中可能同时出现甲乙双方电话，务必提取乙方的电话
- 置信度评估：明确标注"乙方电话/联系电话"=90+；其他位置=60-79

### 合同金额 (amount) 【重要：金额单位为万元】
- **金额单位**：系统使用"万元"作为金额单位，提取后需进行单位转换
- **转换规则**：
  - 合同原文为"元"：金额数字 ÷ 10000 = 万元
  - 合同原文为"万元"：直接提取金额数字
  - 合同原文为"佰万元"：金额数字 × 100 = 万元
  - 合同原文为"千万元"：金额数字 × 1000 = 万元
  - 合同原文为"亿元"：金额数字 × 10000 = 万元
- **查找关键词**：合同金额、总价、价款、合同价款
- **处理细节**：
  - 含税价/不含税价：取合同总金额（通常为含税价）
  - 数字格式：去掉千分位逗号，只保留纯数字
  - 大写金额：如"肆佰陆拾贰万肆仟伍佰陆拾捌元整"，需转换为数字
- **示例转换**：
  - 原文"4624568元" → 输出 462.4568
  - 原文"462.4568万元" → 输出 462.4568
  - 原文"462万元" → 输出 462
  - 原文"肆佰陆拾贰万肆仟伍佰陆拾捌元整" → 输出 462.4568
- 置信度评估：明确标注金额=90+；需计算得出=70-89；估算=50-69

### 签订日期 (signed_date)
- 格式：YYYY-MM-DD
- 查找关键词：签订日期、签署日期、订立日期
- 注意年份：OCR可能误识别，如2029应为2024
- 置信度评估：明确日期=90+；只有年月=70-89；推断=50-69

### 生效日期 (effective_date)
- 格式：YYYY-MM-DD
- 查找关键词：生效日期、开始日期、起止日期
- 如无明确说明，可能与签订日期相同
- 置信度评估：明确标注=90+；推断=60-79；无信息=0

### 截止日期 (expiry_date)
- 格式：YYYY-MM-DD
- 查找关键词：截止日期、终止日期、结束日期、工期
- 注意：工期可能是天数，需计算
- 置信度评估：明确标注=90+；根据工期计算=70-89；推断=50-69

### 款项类型 (payment_type)
- receipt: 本方是收款方（甲方付钱给我方）
- payment: 本方是付款方（我方付钱给对方）
- 判断依据：我方是乙方/承包方通常为receipt

## 特别说明：乙方信息识别
在收款业务场景中，合同中的乙方通常是我方（收款方），因此以下字段都应提取乙方信息：
- signer_party = 乙方公司名称
- signer_name = 乙方签约人姓名
- phone = 乙方联系电话

识别技巧：
1. 首先定位合同中的"甲乙双方"条款，明确谁是甲方、谁是乙方
2. 甲方通常是付款方（发包方），乙方通常是收款方（承包方）
3. 在签署页，甲乙双方分别盖章签字，注意区分

## 置信度评分标准
- 90-100: 明确标注，无需推断
- 70-89: 可根据上下文明确推断
- 50-69: 模糊信息，需要一定推断
- 30-49: 信息不完整，推断依据较弱
- 0-29: 无相关信息或无法确定

## 输出格式
必须返回以下JSON格式，字段无法提取时设为null：
{
    "contract_no": "合同编号或null",
    "contract_name": "合同名称",
    "customer_name": "客户名称/甲方",
    "signer_party": "签约方/乙方",
    "signer_name": "签约人姓名或null",
    "phone": "联系电话或null",
    "amount": 金额数字或null,
    "signed_date": "YYYY-MM-DD或null",
    "effective_date": "YYYY-MM-DD或null",
    "expiry_date": "YYYY-MM-DD或null",
    "payment_type": "receipt或payment",
    "confidence": {
        "contract_no": 0-100,
        "contract_name": 0-100,
        "customer_name": 0-100,
        "signer_party": 0-100,
        "signer_name": 0-100,
        "phone": 0-100,
        "amount": 0-100,
        "signed_date": 0-100,
        "effective_date": 0-100,
        "expiry_date": 0-100,
        "payment_type": 0-100
    }
}

---

合同文本如下：
{$text}

---

只返回JSON，不要有其他任何内容。
EOT;
    }

    /**
     * 调用 Chat API
     */
    public function chat(array $messages): string
    {
        $url = $this->baseUrl . '/v1/chat/completions';

        $data = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $this->temperature,
        ];

        $response = $this->httpPost($url, $data);

        $result = json_decode($response, true);

        if (isset($result['error'])) {
            throw new \Exception('DeepSeek API error: ' . ($result['error']['message'] ?? 'Unknown error'));
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

        throw new \Exception('Failed to parse DeepSeek response as JSON');
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
