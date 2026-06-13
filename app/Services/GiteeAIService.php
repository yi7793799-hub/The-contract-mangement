<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Gitee AI (模力方舟) API 服务
 * 支持 DeepSeek-OCR-2 等 OCR 模型
 * API 文档: https://ai.gitee.com/docs/openapi/v1
 */
class GiteeAIService
{
    /** @var string */
    private $apiKey;
    /** @var string */
    private $baseUrl = 'https://ai.gitee.com';
    /** @var string */
    private $ocrModel = 'DeepSeek-OCR-2';
    /** @var int */
    private $timeout = 300;

    public function __construct()
    {
        $config = $this->loadConfig();
        $this->apiKey = $config['api_key'] ?? '';
        $this->ocrModel = $config['ocr_model'] ?? 'DeepSeek-OCR-2';
    }

    /**
     * 加载配置
     */
    private function loadConfig(): array
    {
        $configFile = __DIR__ . '/../../config/gitee_ai.php';
        if (file_exists($configFile)) {
            return require $configFile;
        }
        return [];
    }

    /**
     * OCR 识别图片
     *
     * @param string $imagePath 图片路径
     * @param string $prompt OCR提示词，默认使用 DeepSeek-OCR 标准格式
     * @return array ['text' => string, 'error' => string|null]
     */
    public function ocrImage(string $imagePath, string $prompt = '<image>\nFree OCR.'): array
    {
        // 读取图片并转为 base64
        $imageData = file_get_contents($imagePath);
        if ($imageData === false) {
            return ['text' => '', 'error' => '无法读取图片文件'];
        }

        $base64 = base64_encode($imageData);
        $mimeType = $this->getMimeType($imagePath);

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$mimeType};base64,{$base64}",
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                ],
            ],
        ];

        try {
            $response = $this->callApi($messages);
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
     * OCR 识别并结构化输出合同
     *
     * @param string $imagePath 图片路径
     * @return array ['text' => string, 'structured' => string, 'error' => string|null]
     */
    public function ocrAndStructureContract(string $imagePath): array
    {
        // 第一步：纯 OCR 识别
        $ocrResult = $this->ocrImage($imagePath);
        if ($ocrResult['error']) {
            return [
                'text' => '',
                'structured' => '',
                'error' => $ocrResult['error'],
            ];
        }

        // 第二步：结构化输出（如果需要可以调用结构化模型）
        return [
            'text' => $ocrResult['text'],
            'structured' => '', // 可以后续添加结构化处理
            'error' => null,
        ];
    }

    /**
     * 使用结构化提示词识别合同
     *
     * @param string $imagePath 图片路径
     * @return array ['text' => string, 'error' => string|null]
     */
    public function ocrContractStructured(string $imagePath): array
    {
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

        // 读取图片
        $imageData = file_get_contents($imagePath);
        if ($imageData === false) {
            return ['text' => '', 'error' => '无法读取图片文件'];
        }

        $base64 = base64_encode($imageData);
        $mimeType = $this->getMimeType($imagePath);

        $messages = [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$mimeType};base64,{$base64}",
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => '请识别图片中的合同内容并按规范结构化输出',
                    ],
                ],
            ],
        ];

        try {
            $response = $this->callApi($messages);
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
     * 调用 Gitee AI API
     */
    private function callApi(array $messages): string
    {
        if (empty($this->apiKey)) {
            throw new \Exception('Gitee AI API Key 未配置');
        }

        $url = $this->baseUrl . '/v1/chat/completions';

        $data = [
            'messages' => $messages,
            'model' => $this->ocrModel,
            'stream' => false,
            'max_tokens' => 4096,
            'temperature' => 0,
            'top_p' => 1,
            'top_k' => 1,
            'frequency_penalty' => 0,
        ];

        $response = $this->httpPost($url, $data);
        $result = json_decode($response, true);

        if (isset($result['error'])) {
            throw new \Exception('Gitee AI API error: ' . ($result['error']['message'] ?? json_encode($result['error'])));
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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            throw new \Exception('HTTP request failed: ' . $error);
        }

        if ($httpCode !== 200) {
            $result = json_decode($response, true);
            $errorMsg = $result['error']['message'] ?? "HTTP $httpCode";
            throw new \Exception('API error: ' . $errorMsg);
        }

        return $response;
    }

    /**
     * 测试 API 连接
     */
    public function testConnection(): array
    {
        try {
            $url = $this->baseUrl . '/v1/models';

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->apiKey,
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200) {
                $result = json_decode($response, true);
                $models = array_column($result['data'] ?? [], 'id');
                return [
                    'success' => true,
                    'models' => $models,
                ];
            }

            return [
                'success' => false,
                'error' => "HTTP $httpCode",
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
