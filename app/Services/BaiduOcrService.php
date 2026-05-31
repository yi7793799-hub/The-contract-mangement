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
    /** @var string */
    private $ocrType;

    public function __construct()
    {
        $config = baidu_ocr_config();
        $this->ak = $config['ak'] ?? '';
        $this->sk = $config['sk'] ?? '';
        $this->ocrType = $config['type'] ?? 'accurate'; // accurate 或 general
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

        throw new \Exception('Failed to get Baidu access token: ' . ($data['error_description'] ?? 'Unknown error'));
    }

    /**
     * 识别图片文件中的文字（使用高精度版）
     */
    public function recognizeImage(string $imagePath): array
    {
        $token = $this->getAccessToken();

        // 使用高精度版 OCR
        $url = 'https://aip.baidubce.com/rest/2.0/ocr/v1/accurate_basic';

        $image = base64_encode(file_get_contents($imagePath));

        $response = $this->httpPost($url . '?access_token=' . $token, [
            'image' => $image,
            'detect_direction' => 'true',
            'probability' => 'true',
        ]);

        return $this->parseResponse($response);
    }

    /**
     * 识别表格图片
     */
    public function recognizeTable(string $imagePath): array
    {
        $token = $this->getAccessToken();

        // 表格文字识别
        $url = 'https://aip.baidubce.com/rest/2.0/solution/v1/iocr/recognise/table';

        $image = base64_encode(file_get_contents($imagePath));

        $response = $this->httpPost($url . '?access_token=' . $token, [
            'image' => $image,
        ]);

        return $this->parseTableResponse($response);
    }

    /**
     * 识别印章
     */
    public function recognizeSeal(string $imagePath): array
    {
        $token = $this->getAccessToken();

        // 印章检测识别
        $url = 'https://aip.baidubce.com/rest/2.0/solution/v1/iocr/recognise/seal';

        $image = base64_encode(file_get_contents($imagePath));

        $response = $this->httpPost($url . '?access_token=' . $token, [
            'image' => $image,
        ]);

        return $this->parseSealResponse($response);
    }

    /**
     * 识别 PDF 文件（组合多种识别方式）
     */
    public function recognizePdf(string $pdfPath): array
    {
        // 对于 PDF，先尝试提取文字
        $text = $this->extractPdfText($pdfPath);

        if (!empty($text) && strlen($text) > 100) {
            return ['text' => $text, 'is_ocr' => false];
        }

        // 使用百度 OCR PDF 识别 API（高精度）
        return $this->recognizePdfByOcr($pdfPath);
    }

    /**
     * 使用百度 OCR 识别 PDF（高精度版）
     */
    private function recognizePdfByOcr(string $pdfPath): array
    {
        $token = $this->getAccessToken();

        // 使用高精度版 PDF OCR
        $url = 'https://aip.baidubce.com/rest/2.0/ocr/v1/accurate_pdf';

        $pdfContent = base64_encode(file_get_contents($pdfPath));

        $response = $this->httpPost($url . '?access_token=' . $token, [
            'pdf_file' => $pdfContent,
            'detect_direction' => 'true',
            'probability' => 'true',
        ]);

        return $this->parseResponse($response);
    }

    /**
     * 综合识别（合同专用：文字 + 表格 + 印章）
     */
    public function recognizeContract(string $filePath): array
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        $result = [
            'text' => '',
            'tables' => [],
            'seals' => [],
            'raw' => [],
        ];

        // 1. 基础文字识别
        if ($ext === 'pdf') {
            $ocrResult = $this->recognizePdf($filePath);
            $result['text'] = $ocrResult['text'] ?? '';
            $result['raw']['basic'] = $ocrResult['raw'] ?? [];
        } else {
            $ocrResult = $this->recognizeImage($filePath);
            $result['text'] = $ocrResult['text'] ?? '';
            $result['raw']['basic'] = $ocrResult['raw'] ?? [];

            // 2. 表格识别（图片格式）
            try {
                $tableResult = $this->recognizeTable($filePath);
                $result['tables'] = $tableResult['tables'] ?? [];
                $result['raw']['table'] = $tableResult['raw'] ?? [];
                // 合并表格文本
                if (!empty($tableResult['text'])) {
                    $result['text'] .= "\n" . $tableResult['text'];
                }
            } catch (\Exception $e) {
                // 表格识别失败不影响整体流程
            }

            // 3. 印章识别
            try {
                $sealResult = $this->recognizeSeal($filePath);
                $result['seals'] = $sealResult['seals'] ?? [];
                $result['raw']['seal'] = $sealResult['raw'] ?? [];
            } catch (\Exception $e) {
                // 印章识别失败不影响整体流程
            }
        }

        return $result;
    }

    /**
     * 从 PDF 提取文字
     */
    private function extractPdfText(string $pdfPath): string
    {
        $output = [];
        $returnCode = 0;

        // 尝试 pdftotext
        $pdftotextPath = 'E:\\汇总\\phpstudyV8\\phpstudy_pro\\Extensions\\Apache2.4.39\\bin\\pdftotext.exe';
        if (file_exists($pdftotextPath)) {
            exec($pdftotextPath . ' ' . escapeshellarg($pdfPath) . ' - 2>&1', $output, $returnCode);
            if ($returnCode === 0 && !empty($output)) {
                return implode("\n", $output);
            }
        }

        exec('pdftotext ' . escapeshellarg($pdfPath) . ' - 2>&1', $output, $returnCode);
        if ($returnCode === 0 && !empty($output)) {
            return implode("\n", $output);
        }

        return '';
    }

    /**
     * 解析通用 OCR API 响应
     */
    private function parseResponse(string $response): array
    {
        $data = json_decode($response, true);

        if (isset($data['error_code'])) {
            throw new \Exception('Baidu OCR API error: ' . ($data['error_msg'] ?? 'Unknown error'));
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
     * 解析表格识别响应
     */
    private function parseTableResponse(string $response): array
    {
        $data = json_decode($response, true);

        if (isset($data['error_code'])) {
            throw new \Exception('Baidu Table OCR error: ' . ($data['error_msg'] ?? 'Unknown error'));
        }

        $tables = [];
        $text = '';

        if (isset($data['tables_result'])) {
            foreach ($data['tables_result'] as $table) {
                $tableData = [];
                if (isset($table['body'])) {
                    foreach ($table['body'] as $row) {
                        $rowData = [];
                        foreach ($row['cell'] as $cell) {
                            $rowData[] = $cell['words'] ?? '';
                            $text .= ($cell['words'] ?? '') . "\t";
                        }
                        $tableData[] = $rowData;
                        $text .= "\n";
                    }
                }
                $tables[] = $tableData;
            }
        }

        return [
            'text' => trim($text),
            'tables' => $tables,
            'raw' => $data,
        ];
    }

    /**
     * 解析印章识别响应
     */
    private function parseSealResponse(string $response): array
    {
        $data = json_decode($response, true);

        if (isset($data['error_code'])) {
            throw new \Exception('Baidu Seal OCR error: ' . ($data['error_msg'] ?? 'Unknown error'));
        }

        $seals = [];
        if (isset($data['seals_result'])) {
            foreach ($data['seals_result'] as $seal) {
                $seals[] = [
                    'text' => $seal['words'] ?? '',
                    'location' => $seal['location'] ?? [],
                ];
            }
        }

        return [
            'seals' => $seals,
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