<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 增强版文档解析服务
 * - 非扫描版PDF: 使用 PyMuPDF 提取文本和结构（快速、免费）
 * - 扫描版PDF/图片: 使用 SiliconFlow OCR（Qwen VL模型，高精度）
 */
class DocumentParserService
{
    /** @var string Python解释器路径 */
    private $pythonPath;

    /** @var string 脚本目录 */
    private $scriptDir;

    /** @var SiliconFlowService SiliconFlow OCR服务 */
    private $siliconflow;

    public function __construct()
    {
        $this->pythonPath = $this->findPython();
        $this->scriptDir = dirname(__DIR__, 2) . '/scripts';
        $this->siliconflow = new SiliconFlowService();
    }

    /**
     * 查找Python解释器
     */
    private function findPython(): string
    {
        $candidates = [
            'D:/Edge download/Python/Install/python.exe',
            'D:/Software/anaconda/python.exe',
            'C:/Users/A/AppData/Local/Microsoft/WindowsApps/python.exe',
            'C:/Python312/python.exe',
            'C:/Python311/python.exe',
            'C:/Python310/python.exe',
            'C:/Users/A/AppData/Local/Programs/Python/Python312/python.exe',
        ];

        foreach ($candidates as $path) {
            $output = [];
            $returnCode = 0;
            // 使用引号包裹路径，确保带空格的路径能正确执行
            $quotedPath = '"' . $path . '"';
            exec($quotedPath . ' --version 2>&1', $output, $returnCode);
            if ($returnCode === 0) {
                return $path; // 返回未加引号的路径，后续使用时再加引号
            }
        }

        return 'python';
    }

    /**
     * 解析文档（自动识别类型）
     */
    public function parse(string $filePath): array
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        switch ($ext) {
            case 'pdf':
                return $this->parsePdf($filePath);
            case 'jpg':
            case 'jpeg':
            case 'png':
            case 'webp':
                return $this->parseImage($filePath);
            case 'docx':
                return $this->parseDocx($filePath);
            case 'doc':
                return $this->parseDoc($filePath);
            default:
                throw new \Exception('不支持的文件格式: ' . $ext);
        }
    }

    /**
     * 解析PDF文档
     * 智能判断：文字型PDF用PyMuPDF，扫描版用SiliconFlow OCR
     */
    public function parsePdf(string $filePath): array
    {
        $result = [
            'text' => '',
            'is_scanned' => false,
            'pages' => 0,
            'has_tables' => false,
            'structure' => [],
        ];

        // 首先尝试用PyMuPDF提取文本
        $pyResult = $this->extractPdfWithPyMuPdf($filePath);

        // 计算平均每页文本量，判断是否为扫描版
        $avgTextPerPage = 0;
        if ($pyResult['pages'] > 0) {
            $avgTextPerPage = strlen($pyResult['text'] ?? '') / $pyResult['pages'];
        }

        // 如果每页平均超过200字符，认为是文字型PDF
        if ($avgTextPerPage > 200) {
            $result['text'] = $pyResult['text'];
            $result['pages'] = $pyResult['pages'];
            $result['structure'] = $pyResult['structure'] ?? [];
            $result['has_tables'] = $pyResult['has_tables'] ?? false;
        } else {
            // 扫描版PDF，使用 SiliconFlow OCR (Qwen VL)
            $result['is_scanned'] = true;
            try {
                $ocrResult = $this->ocrPdfWithSiliconFlow($filePath);
                $result['text'] = $ocrResult['text'] ?? '';
                $result['pages'] = $ocrResult['pages'] ?? $pyResult['pages'] ?? 1;
            } catch (\Exception $e) {
                // OCR失败，使用PyMuPDF提取的少量文本
                $result['text'] = $pyResult['text'] ?? '';
                $result['pages'] = $pyResult['pages'] ?? 1;
                $result['ocr_error'] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * 使用 SiliconFlow OCR 识别扫描版 PDF
     * 渲染PDF为图片，然后逐页识别
     */
    private function ocrPdfWithSiliconFlow(string $filePath, int $maxPages = 20): array
    {
        // 渲染PDF为图片
        $images = $this->renderPdfToImages($filePath, $maxPages);

        if (empty($images)) {
            return [
                'text' => '',
                'pages' => 0,
                'error' => '无法将PDF转换为图片'
            ];
        }

        $allText = [];
        foreach ($images as $index => $imagePath) {
            $result = $this->siliconflow->ocrImage($imagePath);
            $allText[] = "--- 第" . ($index + 1) . "页 ---\n" . ($result['text'] ?? '');
            @unlink($imagePath);
        }

        return [
            'text' => implode("\n\n", $allText),
            'pages' => count($images),
        ];
    }

    /**
     * 使用 PyMuPDF 渲染 PDF 为图片
     */
    private function renderPdfToImages(string $filePath, int $maxPages = 20): array
    {
        $tempDir = sys_get_temp_dir() . '/pdf_images_' . uniqid();
        if (!mkdir($tempDir, 0755, true)) {
            return [];
        }

        // 复制文件到临时目录（避免中文路径问题）
        $tempPdfPath = $tempDir . '/source.pdf';
        if (!copy($filePath, $tempPdfPath)) {
            @rmdir($tempDir);
            return [];
        }

        // 使用 Python 脚本渲染
        $scriptPath = $this->scriptDir . '/render_pdf_full.py';
        $cmd = sprintf(
            '%s %s %s %s %d 2>&1',
            escapeshellarg($this->pythonPath),
            escapeshellarg($scriptPath),
            escapeshellarg($tempPdfPath),
            escapeshellarg($tempDir),
            $maxPages
        );

        exec($cmd, $output, $returnCode);

        // 删除临时PDF
        @unlink($tempPdfPath);

        // 获取生成的图片
        $images = glob($tempDir . '/page_*.png');
        sort($images);

        // 如果没有生成图片，清理并返回空
        if (empty($images)) {
            @rmdir($tempDir);
            return [];
        }

        return $images;
    }

    /**
     * 使用PyMuPDF提取PDF文本
     */
    private function extractPdfWithPyMuPdf(string $filePath): array
    {
        if (!is_dir($this->scriptDir)) {
            mkdir($this->scriptDir, 0755, true);
        }

        // 复制文件到临时目录（避免中文路径问题）
        $tempDir = sys_get_temp_dir();
        $tempPdfPath = $tempDir . '/pdf_temp_' . md5($filePath) . '.pdf';

        if (!copy($filePath, $tempPdfPath)) {
            return ['text' => '', 'pages' => 0, 'structure' => []];
        }

        $outputFile = $this->scriptDir . '/pdf_output_' . md5($filePath) . '.json';

        $scriptPathReal = $this->scriptDir . '/pdf_extractor.py';
        $cmd = $this->pythonPath . ' ' . escapeshellarg($scriptPathReal) . ' ' . escapeshellarg($tempPdfPath) . ' ' . escapeshellarg($outputFile) . ' 2>&1';
        shell_exec($cmd);

        @unlink($tempPdfPath);

        if (!file_exists($outputFile)) {
            return ['text' => '', 'pages' => 0, 'structure' => []];
        }

        $output = file_get_contents($outputFile);
        @unlink($outputFile);

        if ($output === false || empty($output)) {
            return ['text' => '', 'pages' => 0, 'structure' => []];
        }

        $result = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('PDF JSON parse error: ' . json_last_error_msg());
            return ['text' => '', 'pages' => 0, 'structure' => []];
        }

        if (isset($result['error'])) {
            error_log('PDF extraction error: ' . $result['error']);
            return ['text' => '', 'pages' => 0, 'structure' => []];
        }

        return $result ?: ['text' => '', 'pages' => 0, 'structure' => []];
    }

    /**
     * 解析图片（使用 SiliconFlow OCR）
     */
    public function parseImage(string $filePath): array
    {
        $ocrResult = $this->siliconflow->ocrImage($filePath);

        return [
            'text' => $ocrResult['text'] ?? '',
            'is_scanned' => true,
            'pages' => 1,
            'has_tables' => false,
            'structure' => [],
        ];
    }

    /**
     * 解析DOCX文档
     */
    public function parseDocx(string $filePath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \Exception('无法打开DOCX文件');
        }

        $content = $zip->getFromName('word/document.xml');
        $zip->close();

        $xml = simplexml_load_string($content);
        $namespaces = $xml->getNamespaces(true);
        $xml->registerXPathNamespace('w', $namespaces['w']);

        $paragraphs = $xml->xpath('//w:p');
        $textParts = [];

        foreach ($paragraphs as $p) {
            $runs = $p->xpath('.//w:t');
            $paraText = '';
            foreach ($runs as $run) {
                $paraText .= (string)$run;
            }
            if (trim($paraText)) {
                $textParts[] = trim($paraText);
            }
        }

        $fullText = implode("\n", $textParts);

        return [
            'text' => $fullText,
            'is_scanned' => false,
            'pages' => 1,
            'has_tables' => $this->detectTables($fullText),
            'structure' => [],
        ];
    }

    /**
     * 解析DOC文档（旧版Word）
     */
    public function parseDoc(string $filePath): array
    {
        $output = [];
        $returnCode = 0;
        exec('antiword ' . escapeshellarg($filePath) . ' 2>/dev/null', $output, $returnCode);

        if ($returnCode === 0 && !empty($output)) {
            return [
                'text' => implode("\n", $output),
                'is_scanned' => false,
                'pages' => 1,
                'has_tables' => false,
                'structure' => [],
            ];
        }

        throw new \Exception('无法处理.doc文件，请先转换为.docx格式');
    }

    /**
     * 检测文本中是否包含表格
     */
    private function detectTables(string $text): bool
    {
        if (preg_match('/\t{2,}/m', $text)) {
            return true;
        }
        if (preg_match('/(\d+[,.]?\d*\s+){3,}/m', $text)) {
            return true;
        }
        return false;
    }
}