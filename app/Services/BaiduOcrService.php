<?php
declare(strict_types=1);

namespace App\Services;

class BaiduOcrService
{
    /** @var string */
    private $ak;
    /** @var string */
    private $sk;
    /** @var string */
    private $accessToken;
    /** @var int */
    private $tokenExpire;

    public function __construct()
    {
        $config = baidu_ocr_config();
        $this->ak = $config['ak'] ?? '';
        $this->sk = $config['sk'] ?? '';
        $this->accessToken = '';
        $this->tokenExpire = 0;
    }

    /**
     * 获取 Access Token
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken && time() < $this->tokenExpire - 300) {
            return $this->accessToken;
        }

        $url = 'https://aip.baidubce.com/oauth/2.0/token';
        $params = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->ak,
            'client_secret' => $this->sk,
        ];

        $response = $this->httpPost($url, $params);
        $data = json_decode($response, true);

        if (isset($data['access_token'])) {
            $this->accessToken = $data['access_token'];
            $this->tokenExpire = time() + ($data['expires_in'] ?? 2592000);
            return $this->accessToken;
        }

        throw new Exception('Failed to get Baidu access token: ' . ($data['error_description'] ?? 'Unknown error'));
    }

    /**
     * 识别图片文件中的文字
     */
    public function recognizeImage(string $imagePath): array
    {
        $token = $this->getAccessToken();
        $url = 'https://aip.baidubce.com/rest/2.0/ocr/v1/general_basic';

        $image = base64_encode(file_get_contents($imagePath));

        $response = $this->httpPost($url . '?access_token=' . $token, [
            'image' => $image,
        ]);

        return $this->parseResponse($response);
    }

    /**
     * 识别 PDF 文件（每页转图片）
     */
    public function recognizePdf(string $pdfPath): array
    {
        // 对于 PDF，先尝试提取文字
        $text = $this->extractPdfText($pdfPath);

        if (!empty($text)) {
            return ['text' => $text, 'is_ocr' => false];
        }

        // 如果没有文字，使用 OCR（需要 PDF 转图片库，这里简化处理）
        throw new Exception('PDF contains no extractable text and OCR not supported for PDF directly');
    }

    /**
     * 从 PDF 提取文字（简化版）
     */
    private function extractPdfText(string $pdfPath): string
    {
        // 尝试使用 pdftotext 命令
        $output = [];
        $returnCode = 0;

        exec('pdftotext "' . $pdfPath . '" - 2>/dev/null', $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        return '';
    }

    /**
     * 解析 API 响应
     */
    private function parseResponse(string $response): array
    {
        $data = json_decode($response, true);

        if (isset($data['error_code'])) {
            throw new Exception('Baidu OCR API error: ' . ($data['error_msg'] ?? 'Unknown error'));
        }

        $text = '';
        if (isset($data['words_result'])) {
            foreach ($data['words_result'] as $item) {
                $text .= ($item['words'] ?? '') . "\n";
            }
        }

        return [
            'text' => trim($text),
            'raw' => $data,
        ];
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : $data);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('HTTP request failed: ' . $error);
        }

        return $response;
    }
}
