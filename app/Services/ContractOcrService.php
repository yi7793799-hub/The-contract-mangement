<?php
// app/Services/ContractOcrService.php
declare(strict_types=1);

namespace App\Services;

use App\DTO\RecognitionResult;
use App\DTO\ContractFields;
use Exception;

/**
 * 合同 OCR 识别服务
 * 主编排服务，协调文档解析、字段提取、置信度计算和重试逻辑
 */
class ContractOcrService
{
    /** @var array 支持的文件格式 */
    public const SUPPORTED_FORMATS = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx', 'doc'];

    /** @var float 目标志信度阈值 */
    private const DEFAULT_TARGET_CONFIDENCE = 90.0;

    /** @var int 最大重试次数 */
    private const MAX_RETRY_COUNT = 3;

    /** @var DocumentParserService */
    private $parser;

    /** @var SiliconFlowService */
    private $siliconflow;

    /** @var GiteeAIService|null */
    private $giteeAi;

    /** @var ConfidenceCalculator */
    private $confidenceCalculator;

    /** @var float */
    private $targetConfidence;

    /** @var array */
    private $config;

    /** @var string OCR 提供商: 'siliconflow' 或 'gitee' */
    private $ocrProvider = 'gitee';

    /**
     * 构造函数
     * 从 config/siliconflow.php 加载配置
     */
    public function __construct(
        ?DocumentParserService $parser = null,
        ?SiliconFlowService $siliconflow = null,
        ?ConfidenceCalculator $confidenceCalculator = null,
        ?GiteeAIService $giteeAi = null
    ) {
        $this->config = $this->loadConfig();
        $this->parser = $parser ?? new DocumentParserService();
        $this->siliconflow = $siliconflow ?? new SiliconFlowService();
        $this->giteeAi = $giteeAi ?? new GiteeAIService();
        $this->confidenceCalculator = $confidenceCalculator ?? new ConfidenceCalculator();
        $this->targetConfidence = (float) ($this->config['target_confidence'] ?? self::DEFAULT_TARGET_CONFIDENCE);

        // 从配置读取 OCR 提供商
        $this->ocrProvider = $this->config['ocr_provider'] ?? 'gitee';
    }

    /**
     * 加载配置文件
     *
     * @return array
     */
    private function loadConfig(): array
    {
        $configPath = dirname(__DIR__, 2) . '/config/siliconflow.php';
        if (file_exists($configPath)) {
            return require $configPath;
        }
        return [];
    }

    /**
     * 识别合同文件
     *
     * @param string $filePath 文件路径
     * @return RecognitionResult 识别结果
     * @throws Exception 文件验证失败时抛出异常
     */
    public function recognize(string $filePath): RecognitionResult
    {
        $retryCount = 0;
        $preprocessed = false;
        $errorMessage = null;

        try {
            // 1. 验证文件
            $this->validateFile($filePath);

            // 2. 解析文档
            $parseResult = $this->parser->parse($filePath);
            $fullText = $parseResult['text'] ?? '';

            if (empty(trim($fullText))) {
                throw new Exception('文档解析失败：无法提取文本内容');
            }

            // 3. 提取字段
            $fieldsResult = $this->siliconflow->extractContractFields($fullText);
            $structuredFields = $fieldsResult['structured_fields'] ?? $fieldsResult;

            // 4. 计算置信度
            $fieldConfidences = $fieldsResult['confidence'] ?? [];
            $overallConfidence = $this->confidenceCalculator->calculate($fieldConfidences);

            // 5. 如果置信度不足，尝试重试
            if ($overallConfidence < $this->targetConfidence) {
                $retryResult = $this->retry($filePath, $fullText, $fieldConfidences, $overallConfidence);
                if ($retryResult !== null) {
                    $retryCount = $retryResult['retry_count'];
                    $preprocessed = $retryResult['preprocessed'];
                    $structuredFields = $retryResult['fields'];
                    $fieldConfidences = $retryResult['field_confidences'];
                    $overallConfidence = $retryResult['overall_confidence'];
                }
            }

            // 6. 返回结果
            return new RecognitionResult(
                $fullText,
                $structuredFields,
                $overallConfidence,
                $fieldConfidences,
                $retryCount,
                true,
                null,
                $preprocessed
            );
        } catch (Exception $e) {
            $errorMessage = $e->getMessage();
            return new RecognitionResult(
                '',
                [],
                0.0,
                [],
                $retryCount,
                false,
                $errorMessage,
                $preprocessed
            );
        }
    }

    /**
     * 识别合同文档并保存全文到文件
     *
     * @param string $filePath 源文件路径
     * @param string|null $outputPath 输出文件路径（默认保存到 uploads/ocr_text/ 目录）
     * @return array ['result' => RecognitionResult, 'output_file' => string]
     * @throws Exception
     */
    public function recognizeAndSaveText(string $filePath, ?string $outputPath = null): array
    {
        // 执行识别
        $result = $this->recognize($filePath);

        // 如果识别失败，直接返回
        if (!$result->success || empty($result->fullText)) {
            return [
                'result' => $result,
                'output_file' => null,
            ];
        }

        // 生成输出路径
        if ($outputPath === null) {
            $baseName = pathinfo($filePath, PATHINFO_FILENAME);
            $outputDir = dirname(__DIR__, 2) . '/uploads/ocr_text';

            // 确保目录存在
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }

            $outputPath = $outputDir . '/' . $baseName . '_全文.txt';
        }

        // 确保输出目录存在
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // 保存全文
        $written = file_put_contents($outputPath, $result->fullText);

        if ($written === false) {
            throw new Exception('无法保存全文到文件: ' . $outputPath);
        }

        return [
            'result' => $result,
            'output_file' => $outputPath,
        ];
    }

    /**
     * 使用 Gitee AI DeepSeek-OCR-2 识别图片
     * 适用于扫描版合同图片
     *
     * @param string $imagePath 图片路径
     * @param bool $structured 是否返回结构化格式
     * @return array ['text' => string, 'error' => string|null]
     */
    public function ocrImageWithGitee(string $imagePath, bool $structured = false): array
    {
        if ($this->giteeAi === null) {
            return ['text' => '', 'error' => 'Gitee AI 服务未初始化'];
        }

        if ($structured) {
            return $this->giteeAi->ocrContractStructured($imagePath);
        }

        return $this->giteeAi->ocrImage($imagePath);
    }

    /**
     * 设置 OCR 提供商
     *
     * @param string $provider 'siliconflow' 或 'gitee'
     */
    public function setOcrProvider(string $provider): void
    {
        $this->ocrProvider = $provider;
    }

    /**
     * 获取当前 OCR 提供商
     *
     * @return string
     */
    public function getOcrProvider(): string
    {
        return $this->ocrProvider;
    }

    /**
     * 验证文件
     *
     * @param string $filePath 文件路径
     * @throws Exception 验证失败时抛出异常
     */
    public function validateFile(string $filePath): void
    {
        // 检查文件是否存在
        if (!file_exists($filePath)) {
            throw new Exception('文件不存在: ' . $filePath);
        }

        // 检查文件是否为空
        if (filesize($filePath) === 0) {
            throw new Exception('文件为空: ' . $filePath);
        }

        // 检查文件格式是否支持
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($extension, self::SUPPORTED_FORMATS, true)) {
            throw new Exception(
                '不支持的文件格式: ' . $extension . '。支持的格式: ' . implode(', ', self::SUPPORTED_FORMATS)
            );
        }
    }

    /**
     * 重试逻辑
     * 三阶段重试策略：
     * 1. 调整提取参数
     * 2. 切换模型
     * 3. 图像预处理后重试
     *
     * @param string $filePath 文件路径
     * @param string $fullText 已提取的文本
     * @param array $fieldConfidences 字段置信度
     * @param float $currentConfidence 当前置信度
     * @return array|null 重试结果，如果所有重试都失败则返回 null
     */
    private function retry(
        string $filePath,
        string $fullText,
        array $fieldConfidences,
        float $currentConfidence
    ): ?array {
        $retryCount = 0;
        $preprocessed = false;
        $bestResult = null;
        $bestConfidence = $currentConfidence;

        // 阶段 1: 参数调整重试
        $stage1Result = $this->retryWithParameterAdjustment($fullText);
        if ($stage1Result !== null) {
            $retryCount++;
            $newConfidence = $this->confidenceCalculator->calculate($stage1Result['confidence'] ?? []);
            if ($newConfidence >= $this->targetConfidence) {
                return [
                    'retry_count' => $retryCount,
                    'preprocessed' => false,
                    'fields' => $stage1Result,
                    'field_confidences' => $stage1Result['confidence'] ?? [],
                    'overall_confidence' => $newConfidence,
                ];
            }
            if ($newConfidence > $bestConfidence) {
                $bestConfidence = $newConfidence;
                $bestResult = $stage1Result;
            }
        }

        // 阶段 2: 模型切换重试
        $stage2Result = $this->retryWithModelSwitch($fullText);
        if ($stage2Result !== null) {
            $retryCount++;
            $newConfidence = $this->confidenceCalculator->calculate($stage2Result['confidence'] ?? []);
            if ($newConfidence >= $this->targetConfidence) {
                return [
                    'retry_count' => $retryCount,
                    'preprocessed' => false,
                    'fields' => $stage2Result,
                    'field_confidences' => $stage2Result['confidence'] ?? [],
                    'overall_confidence' => $newConfidence,
                ];
            }
            if ($newConfidence > $bestConfidence) {
                $bestConfidence = $newConfidence;
                $bestResult = $stage2Result;
            }
        }

        // 阶段 3: 图像预处理重试
        $stage3Result = $this->retryWithPreprocessing($filePath);
        if ($stage3Result !== null) {
            $retryCount++;
            $preprocessed = true;
            $newConfidence = $this->confidenceCalculator->calculate($stage3Result['confidence'] ?? []);
            if ($newConfidence >= $this->targetConfidence) {
                return [
                    'retry_count' => $retryCount,
                    'preprocessed' => true,
                    'fields' => $stage3Result,
                    'field_confidences' => $stage3Result['confidence'] ?? [],
                    'overall_confidence' => $newConfidence,
                ];
            }
            if ($newConfidence > $bestConfidence) {
                $bestConfidence = $newConfidence;
                $bestResult = $stage3Result;
            }
        }

        // 返回最佳结果
        if ($bestResult !== null) {
            return [
                'retry_count' => $retryCount,
                'preprocessed' => $preprocessed,
                'fields' => $bestResult,
                'field_confidences' => $bestResult['confidence'] ?? [],
                'overall_confidence' => $bestConfidence,
            ];
        }

        return null;
    }

    /**
     * 阶段 1: 参数调整重试
     * 使用更严格的提示词重新提取
     *
     * @param string $fullText 文本内容
     * @return array|null 提取结果
     */
    private function retryWithParameterAdjustment(string $fullText): ?array
    {
        try {
            // 使用更详细的提示词
            $enhancedPrompt = $this->buildEnhancedExtractionPrompt($fullText);
            $result = $this->siliconflow->extractContractFields($enhancedPrompt);
            return $result;
        } catch (Exception $e) {
            error_log('ContractOcrService Stage 1 retry failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 阶段 2: 模型切换重试
     * 使用不同的模型重新提取
     *
     * @param string $fullText 文本内容
     * @return array|null 提取结果
     */
    private function retryWithModelSwitch(string $fullText): ?array
    {
        try {
            // 使用备用模型配置（如果配置中有 retry_models）
            $retryModels = $this->config['retry_models'] ?? [];
            if (!empty($retryModels)) {
                // 尝试使用第一个备用模型
                $backupModel = $retryModels[0] ?? null;
                if ($backupModel) {
                    error_log('ContractOcrService Stage 2: switching to model ' . $backupModel);
                }
            }
            // 当前实现仍使用 SiliconFlowService，后续可扩展模型切换逻辑
            $result = $this->siliconflow->extractContractFields($fullText);
            return $result;
        } catch (Exception $e) {
            error_log('ContractOcrService Stage 2 retry failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 阶段 3: 图像预处理重试
     * 对图像进行预处理后重新识别
     *
     * @param string $filePath 文件路径
     * @return array|null 提取结果
     */
    private function retryWithPreprocessing(string $filePath): ?array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        // 只对图像文件进行预处理
        if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'])) {
            return null;
        }

        try {
            // 调用预处理脚本
            $preprocessedPath = $this->preprocessImage($filePath);
            if ($preprocessedPath === null) {
                return null;
            }

            // 使用预处理后的图像重新解析
            $parseResult = $this->parser->parse($preprocessedPath);
            $fullText = $parseResult['text'] ?? '';

            // 清理临时文件
            if (file_exists($preprocessedPath)) {
                @unlink($preprocessedPath);
            }

            if (empty(trim($fullText))) {
                return null;
            }

            // 重新提取字段
            $result = $this->siliconflow->extractContractFields($fullText);
            return $result;
        } catch (Exception $e) {
            error_log('ContractOcrService Stage 3 retry failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 预处理图像
     * 调用 Python 脚本进行图像增强
     *
     * @param string $filePath 原始图像路径
     * @return string|null 预处理后的图像路径，失败返回 null
     */
    private function preprocessImage(string $filePath): ?string
    {
        $scriptPath = dirname(__DIR__, 2) . '/scripts/preprocess_image.py';

        if (!file_exists($scriptPath)) {
            return null;
        }

        $tempDir = sys_get_temp_dir();
        $outputPath = $tempDir . '/preprocessed_' . basename($filePath);

        // 查找 Python 解释器
        $pythonPath = $this->findPython();

        $cmd = sprintf(
            '%s %s %s %s 2>&1',
            escapeshellarg($pythonPath),
            escapeshellarg($scriptPath),
            escapeshellarg($filePath),
            escapeshellarg($outputPath)
        );

        $output = [];
        $returnCode = 0;
        exec($cmd, $output, $returnCode);

        if ($returnCode === 0 && file_exists($outputPath)) {
            return $outputPath;
        }

        return null;
    }

    /**
     * 查找 Python 解释器
     *
     * @return string
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
            if (file_exists($path)) {
                $output = [];
                $returnCode = 0;
                exec('"' . $path . '" --version 2>&1', $output, $returnCode);
                if ($returnCode === 0) {
                    return $path;
                }
            }
        }

        return 'python';
    }

    /**
     * 构建增强的提取提示词
     *
     * @param string $text 文本内容
     * @return string 增强的提示词
     */
    private function buildEnhancedExtractionPrompt(string $text): string
    {
        return "请仔细分析以下合同文本，准确提取所有关键信息。特别注意：\n" .
            "1. 合同编号通常在合同首页顶部或标题附近\n" .
            "2. 金额可能以大写或小写形式出现，请提取数字金额\n" .
            "3. 日期格式可能多样化，请转换为 YYYY-MM-DD 格式\n" .
            "4. 甲乙双方名称要完整提取，不要遗漏\n\n" .
            "合同文本：\n" . $text;
    }

    /**
     * 获取目标置信度
     *
     * @return float
     */
    public function getTargetConfidence(): float
    {
        return $this->targetConfidence;
    }

    /**
     * 设置目标置信度
     *
     * @param float $target
     */
    public function setTargetConfidence(float $target): void
    {
        $this->targetConfidence = $target;
    }

    /**
     * 获取支持的文件格式
     *
     * @return array
     */
    public static function getSupportedFormats(): array
    {
        return self::SUPPORTED_FORMATS;
    }
}
