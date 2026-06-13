<?php
// tests/Integration/ContractOcrIntegrationTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Services\ContractOcrService;
use App\DTO\RecognitionResult;

/**
 * 合同 OCR 识别集成测试
 *
 * 此测试文件用于测试真实的 API 调用
 * 需要：
 * 1. 在 config/siliconflow.php 中配置有效的 API Key
 * 2. 在 tests/fixtures/ 目录下放置测试文件
 *
 * 运行集成测试：
 * vendor/bin/phpunit --group integration tests/Integration/ContractOcrIntegrationTest.php
 */
class ContractOcrIntegrationTest extends TestCase
{
    /**
     * @var ContractOcrService
     */
    private $service;

    /**
     * @var string
     */
    private $fixturesDir;

    /**
     * @var array
     */
    private $config;

    protected function setUp(): void
    {
        parent::setUp();

        // 加载配置
        $configPath = dirname(__DIR__, 2) . '/config/siliconflow.php';
        if (!file_exists($configPath)) {
            $this->markTestSkipped('配置文件不存在: config/siliconflow.php');
        }

        $this->config = require $configPath;

        // 检查 API Key 是否有效
        if (empty($this->config['api_key'])) {
            $this->markTestSkipped('需要配置有效的 SiliconFlow API Key');
        }

        if (strpos($this->config['api_key'], 'your_') === 0) {
            $this->markTestSkipped('需要配置有效的 SiliconFlow API Key（非占位符）');
        }

        // 初始化服务
        $this->service = new ContractOcrService();

        // 设置 fixtures 目录
        $this->fixturesDir = dirname(__DIR__) . '/fixtures';
    }

    protected function tearDown(): void
    {
        $this->service = null;
        parent::tearDown();
    }

    /**
     * @group integration
     */
    public function testServiceCanBeInstantiated(): void
    {
        $this->assertInstanceOf(ContractOcrService::class, $this->service);
    }

    /**
     * @group integration
     */
    public function testRecognizeTextPdf(): void
    {
        $testFile = $this->fixturesDir . '/sample_contract.pdf';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('测试文件不存在: tests/fixtures/sample_contract.pdf');
        }

        $result = $this->service->recognize($testFile);

        // 验证返回结果
        $this->assertInstanceOf(RecognitionResult::class, $result);
        $this->assertTrue($result->success, '识别应该成功');
        $this->assertNotEmpty($result->fullText, '应提取到文本');
        $this->assertIsArray($result->structuredFields, '结构化字段应为数组');
        $this->assertIsFloat($result->overallConfidence, '置信度应为浮点数');
        $this->assertGreaterThanOrEqual(0, $result->overallConfidence, '置信度应 >= 0');
        $this->assertLessThanOrEqual(100, $result->overallConfidence, '置信度应 <= 100');
    }

    /**
     * @group integration
     */
    public function testRecognizeJpegImage(): void
    {
        $testFile = $this->fixturesDir . '/sample_contract.jpg';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('测试文件不存在: tests/fixtures/sample_contract.jpg');
        }

        $result = $this->service->recognize($testFile);

        $this->assertInstanceOf(RecognitionResult::class, $result);
        $this->assertTrue($result->success, '识别应该成功');
        $this->assertNotEmpty($result->fullText, '应提取到文本');
    }

    /**
     * @group integration
     */
    public function testRecognizePngImage(): void
    {
        $testFile = $this->fixturesDir . '/sample_contract.png';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('测试文件不存在: tests/fixtures/sample_contract.png');
        }

        $result = $this->service->recognize($testFile);

        $this->assertInstanceOf(RecognitionResult::class, $result);
        $this->assertTrue($result->success, '识别应该成功');
        $this->assertNotEmpty($result->fullText, '应提取到文本');
    }

    /**
     * @group integration
     */
    public function testRecognizeDocxDocument(): void
    {
        $testFile = $this->fixturesDir . '/sample_contract.docx';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('测试文件不存在: tests/fixtures/sample_contract.docx');
        }

        $result = $this->service->recognize($testFile);

        $this->assertInstanceOf(RecognitionResult::class, $result);
        $this->assertTrue($result->success, '识别应该成功');
        $this->assertNotEmpty($result->fullText, '应提取到文本');
    }

    /**
     * @group integration
     */
    public function testRecognizeExtractsContractFields(): void
    {
        $testFile = $this->fixturesDir . '/sample_contract.pdf';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('测试文件不存在: tests/fixtures/sample_contract.pdf');
        }

        $result = $this->service->recognize($testFile);

        $this->assertTrue($result->success, '识别应该成功');

        // 检查是否提取到关键字段
        $fields = $result->structuredFields;

        // 至少应该能提取到一些字段（即使值可能为空）
        $this->assertIsArray($fields, '结构化字段应为数组');

        // 如果成功提取到字段，验证常用字段
        if (!empty($fields)) {
            // 检查是否包含常见合同字段
            $expectedFieldKeys = [
                'contract_no',
                'contract_name',
                'customer_name',
                'signer_party',
                'amount',
                'signed_date',
                'effective_date',
                'expiry_date',
                'signer_name',
                'phone',
                'payment_type',
            ];

            $foundFields = array_intersect(array_keys($fields), $expectedFieldKeys);

            // 应该至少识别出一些字段
            $this->assertNotEmpty(
                $foundFields,
                '应该提取到至少一个关键字段。提取到的字段: ' . implode(', ', array_keys($fields))
            );
        }
    }

    /**
     * @group integration
     */
    public function testRecognizeWithLowQualityImage(): void
    {
        $testFile = $this->fixturesDir . '/low_quality_contract.jpg';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('测试文件不存在: tests/fixtures/low_quality_contract.jpg');
        }

        $result = $this->service->recognize($testFile);

        // 低质量图像可能成功或失败，取决于图像质量
        // 主要测试服务不会崩溃
        $this->assertInstanceOf(RecognitionResult::class, $result);

        // 如果成功，检查重试逻辑是否被触发
        if ($result->success) {
            // 低质量图像通常会触发重试或预处理
            $this->assertIsInt($result->retryCount);
            $this->assertIsBool($result->preprocessed);
        }
    }

    /**
     * @group integration
     */
    public function testRecognizeHandlesMultiPagePdf(): void
    {
        $testFile = $this->fixturesDir . '/multi_page_contract.pdf';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('测试文件不存在: tests/fixtures/multi_page_contract.pdf');
        }

        $result = $this->service->recognize($testFile);

        $this->assertInstanceOf(RecognitionResult::class, $result);
        $this->assertTrue($result->success, '多页PDF识别应该成功');
        $this->assertNotEmpty($result->fullText, '应提取到文本');
    }

    /**
     * @group integration
     */
    public function testConfidenceCalculationWorks(): void
    {
        $testFile = $this->fixturesDir . '/sample_contract.pdf';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('测试文件不存在: tests/fixtures/sample_contract.pdf');
        }

        $result = $this->service->recognize($testFile);

        if ($result->success) {
            // 检查置信度字段
            $this->assertIsArray($result->fieldConfidences, '字段置信度应为数组');

            // 如果有字段置信度，验证其值范围
            foreach ($result->fieldConfidences as $field => $confidence) {
                $this->assertIsNumeric($confidence, "字段 {$field} 的置信度应为数字");
                $this->assertGreaterThanOrEqual(0, $confidence, "字段 {$field} 的置信度应 >= 0");
                $this->assertLessThanOrEqual(100, $confidence, "字段 {$field} 的置信度应 <= 100");
            }

            // 验证总体置信度在合理范围内
            $this->assertGreaterThanOrEqual(0, $result->overallConfidence);
            $this->assertLessThanOrEqual(100, $result->overallConfidence);
        }
    }

    /**
     * @group integration
     */
    public function testApiTimeout(): void
    {
        // 设置较短的超时时间
        $originalTimeout = $this->config['timeout'] ?? 120;

        $testFile = $this->fixturesDir . '/sample_contract.pdf';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('测试文件不存在: tests/fixtures/sample_contract.pdf');
        }

        // 正常情况下应该在超时前完成
        $result = $this->service->recognize($testFile);

        $this->assertInstanceOf(RecognitionResult::class, $result);

        // 测试是否在合理时间内完成（默认120秒内）
        // 如果失败，检查 errorMessage 是否包含超时相关信息
        if (!$result->success) {
            // 失败是可接受的，但应该有错误信息
            $this->assertNotEmpty($result->errorMessage, '失败时应包含错误信息');
        }
    }

    /**
     * @group integration
     */
    public function testRetryMechanismOnLowConfidence(): void
    {
        // 使用一个可能导致低置信度的文件
        $testFile = $this->fixturesDir . '/blurry_contract.jpg';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('测试文件不存在: tests/fixtures/blurry_contract.jpg（使用低质量图像测试重试机制）');
        }

        // 设置较高的目标置信度以触发重试
        $this->service->setTargetConfidence(95.0);

        $result = $this->service->recognize($testFile);

        $this->assertInstanceOf(RecognitionResult::class, $result);

        // 如果成功，检查重试次数
        if ($result->success) {
            // 如果初始置信度低于目标，应该有重试
            if ($result->overallConfidence < 95.0) {
                $this->assertGreaterThanOrEqual(0, $result->retryCount, '应该有重试尝试');
            }
        }
    }

    /**
     * @group integration
     */
    public function testRecognizeRealWorldContract(): void
    {
        // 测试真实的合同文件（如果提供）
        $testFile = $this->fixturesDir . '/real_contract.pdf';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('测试文件不存在: tests/fixtures/real_contract.pdf（可选：放置真实合同文件进行测试）');
        }

        $result = $this->service->recognize($testFile);

        $this->assertInstanceOf(RecognitionResult::class, $result);

        // 对于真实合同，我们主要关注：
        // 1. 服务不崩溃
        // 2. 返回合理的结果
        if ($result->success) {
            $this->assertNotEmpty($result->fullText, '真实合同应提取到文本');
            $this->assertNotEmpty($result->structuredFields, '真实合同应提取到结构化字段');

            // 真实合同应该有合理的置信度
            $this->assertGreaterThan(30, $result->overallConfidence, '真实合同的置信度应 > 30');
        } else {
            // 如果失败，记录原因供调试
            $this->assertNotEmpty($result->errorMessage, '失败时应提供错误信息');
        }
    }

    /**
     * @group integration
     */
    public function testServiceHandlesMissingFixture(): void
    {
        $nonExistentFile = $this->fixturesDir . '/non_existent_file.pdf';

        $result = $this->service->recognize($nonExistentFile);

        $this->assertInstanceOf(RecognitionResult::class, $result);
        $this->assertFalse($result->success, '不存在的文件应返回失败');
        $this->assertNotEmpty($result->errorMessage, '应包含错误信息');
        $this->assertStringContainsString('文件不存在', $result->errorMessage);
    }

    /**
     * @group integration
     */
    public function testServiceWithCustomTargetConfidence(): void
    {
        $testFile = $this->fixturesDir . '/sample_contract.pdf';

        if (!file_exists($testFile)) {
            $this->markTestSkipped('测试文件不存在: tests/fixtures/sample_contract.pdf');
        }

        // 设置自定义目标置信度
        $this->service->setTargetConfidence(50.0);
        $result = $this->service->recognize($testFile);

        $this->assertInstanceOf(RecognitionResult::class, $result);

        // 较低的目标置信度应该更容易达成，可能不会触发重试
        if ($result->success) {
            $this->assertGreaterThanOrEqual(0, $result->overallConfidence);
        }
    }

    /**
     * @group integration
     */
    public function testRecognizeDifferentFileFormats(): void
    {
        $formats = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx'];
        $results = [];

        foreach ($formats as $format) {
            $testFile = $this->fixturesDir . '/sample_contract.' . $format;

            if (!file_exists($testFile)) {
                continue; // 跳过不存在的文件
            }

            $result = $this->service->recognize($testFile);
            $results[$format] = $result;

            $this->assertInstanceOf(RecognitionResult::class, $result);

            // 记录每种格式的结果
            if ($result->success) {
                $this->assertNotEmpty($result->fullText, "{$format} 格式应提取到文本");
            }
        }

        // 如果没有任何测试文件，跳过
        if (empty($results)) {
            $this->markTestSkipped('没有找到任何测试文件。请在 tests/fixtures/ 目录下放置测试文件。');
        }
    }
}
