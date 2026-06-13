<?php
// tests/Services/ContractOcrServiceTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Services\ContractOcrService;
use App\Services\DocumentParserService;
use App\Services\SiliconFlowService;
use App\Services\ConfidenceCalculator;
use App\DTO\RecognitionResult;

class ContractOcrServiceTest extends TestCase
{
    /**
     * @var ContractOcrService
     */
    private $service;

    /**
     * @var string
     */
    private $testFilesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContractOcrService();
        $this->testFilesDir = sys_get_temp_dir() . '/contract_ocr_test_' . uniqid();
        if (!is_dir($this->testFilesDir)) {
            mkdir($this->testFilesDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // 清理测试文件
        if (is_dir($this->testFilesDir)) {
            $files = glob($this->testFilesDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->testFilesDir);
        }
        parent::tearDown();
    }

    /**
     * 测试服务可以被实例化
     */
    public function testServiceCanBeInstantiated(): void
    {
        $this->assertInstanceOf(ContractOcrService::class, $this->service);
    }

    /**
     * 测试不支持的文件格式抛出异常
     */
    public function testUnsupportedFormatThrowsException(): void
    {
        // 创建一个不支持格式的测试文件
        $testFile = $this->testFilesDir . '/test.unsupported';
        file_put_contents($testFile, 'test content');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('不支持的文件格式');

        $this->service->validateFile($testFile);
    }

    /**
     * 测试不存在的文件抛出异常
     */
    public function testNonExistentFileThrowsException(): void
    {
        $nonExistentFile = $this->testFilesDir . '/non_existent_file.pdf';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('文件不存在');

        $this->service->validateFile($nonExistentFile);
    }

    /**
     * 测试空文件抛出异常
     */
    public function testEmptyFileThrowsException(): void
    {
        // 创建一个空文件
        $emptyFile = $this->testFilesDir . '/empty.pdf';
        file_put_contents($emptyFile, '');

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('文件为空');

        $this->service->validateFile($emptyFile);
    }

    /**
     * 测试支持的格式列表
     */
    public function testSupportedFormatsAreCorrect(): void
    {
        $expectedFormats = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx', 'doc'];
        $actualFormats = ContractOcrService::getSupportedFormats();

        $this->assertEquals($expectedFormats, $actualFormats);
    }

    /**
     * 测试支持的文件格式验证通过
     */
    public function testSupportedFormatPassesValidation(): void
    {
        // 创建支持的格式文件
        $supportedFormats = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx', 'doc'];

        foreach ($supportedFormats as $format) {
            $testFile = $this->testFilesDir . '/test.' . $format;
            file_put_contents($testFile, 'test content');

            // 不应该抛出异常
            try {
                $this->service->validateFile($testFile);
                $this->assertTrue(true, "Format {$format} passed validation");
            } catch (Exception $e) {
                $this->fail("Format {$format} should be supported but threw: " . $e->getMessage());
            }
        }
    }

    /**
     * 测试目标置信度默认值
     */
    public function testDefaultTargetConfidence(): void
    {
        $targetConfidence = $this->service->getTargetConfidence();
        $this->assertGreaterThanOrEqual(0, $targetConfidence);
        $this->assertLessThanOrEqual(100, $targetConfidence);
    }

    /**
     * 测试设置目标置信度
     */
    public function testSetTargetConfidence(): void
    {
        $newTarget = 95.5;
        $this->service->setTargetConfidence($newTarget);

        $this->assertEquals($newTarget, $this->service->getTargetConfidence());
    }

    /**
     * 测试大写扩展名格式也被支持
     */
    public function testUppercaseExtensionIsSupported(): void
    {
        // 创建大写扩展名的文件
        $testFile = $this->testFilesDir . '/test_upper.PDF';
        file_put_contents($testFile, 'test content');

        try {
            $this->service->validateFile($testFile);
            $this->assertTrue(true, 'Uppercase extension passed validation');
        } catch (Exception $e) {
            $this->fail('Uppercase extension should be supported: ' . $e->getMessage());
        }
    }

    /**
     * 测试 recognize 方法返回正确的结果类型
     */
    public function testRecognizeReturnsRecognitionResult(): void
    {
        // 创建一个简单的测试 PDF 文件
        $testFile = $this->testFilesDir . '/test.pdf';
        file_put_contents($testFile, '%PDF-1.4 test content');

        $result = $this->service->recognize($testFile);

        // 结果应该包含必要的字段
        $this->assertNotNull($result);
        $this->assertIsString($result->fullText);
        $this->assertIsArray($result->structuredFields);
        $this->assertIsFloat($result->overallConfidence);
        $this->assertIsArray($result->fieldConfidences);
        $this->assertIsInt($result->retryCount);
        $this->assertIsBool($result->success);
        $this->assertIsBool($result->preprocessed);
    }

    /**
     * 测试 recognize 方法处理不存在的文件
     */
    public function testRecognizeHandlesNonExistentFile(): void
    {
        $result = $this->service->recognize('/path/to/non/existent/file.pdf');

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errorMessage);
        $this->assertStringContainsString('文件不存在', $result->errorMessage);
    }

    /**
     * 测试 recognize 方法处理空文件
     */
    public function testRecognizeHandlesEmptyFile(): void
    {
        $emptyFile = $this->testFilesDir . '/empty.pdf';
        file_put_contents($emptyFile, '');

        $result = $this->service->recognize($emptyFile);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errorMessage);
        $this->assertStringContainsString('文件为空', $result->errorMessage);
    }

    /**
     * 测试 recognize 方法处理不支持的格式
     */
    public function testRecognizeHandlesUnsupportedFormat(): void
    {
        $unsupportedFile = $this->testFilesDir . '/test.xyz';
        file_put_contents($unsupportedFile, 'test content');

        $result = $this->service->recognize($unsupportedFile);

        $this->assertFalse($result->success);
        $this->assertNotEmpty($result->errorMessage);
        $this->assertStringContainsString('不支持的文件格式', $result->errorMessage);
    }

    /**
     * 测试使用 Mock 服务进行识别（高置信度结果）
     */
    public function testRecognizeWithMockedServices(): void
    {
        // 创建 Mock DocumentParserService
        $mockParser = $this->createMock(DocumentParserService::class);
        $mockParser->method('parse')
            ->willReturn([
                'text' => '合同编号：HT-2024-001\n甲方：测试公司A\n乙方：测试公司B\n金额：100000元\n签订日期：2024-01-15',
                'is_scanned' => false,
                'pages' => 1,
            ]);

        // 创建 Mock SiliconFlowService
        $mockSiliconFlow = $this->createMock(SiliconFlowService::class);
        $mockSiliconFlow->method('extractContractFields')
            ->willReturn([
                'contract_no' => 'HT-2024-001',
                'customer_name' => '测试公司A',
                'signer_party' => '测试公司B',
                'amount' => '100000',
                'signed_date' => '2024-01-15',
                'confidence' => [
                    'contract_no' => 95,
                    'customer_name' => 92,
                    'signer_party' => 90,
                    'amount' => 98,
                    'signed_date' => 94,
                ],
            ]);

        // 创建 Mock ConfidenceCalculator
        $mockConfidenceCalculator = $this->createMock(ConfidenceCalculator::class);
        $mockConfidenceCalculator->method('calculate')
            ->willReturn(93.8);

        // 创建测试文件
        $testFile = $this->testFilesDir . '/test_mock.pdf';
        file_put_contents($testFile, '%PDF-1.4 mock content');

        // 使用 Mock 服务创建 ContractOcrService
        $service = new ContractOcrService(
            $mockParser,
            $mockSiliconFlow,
            $mockConfidenceCalculator
        );

        // 执行识别
        $result = $service->recognize($testFile);

        // 验证结果
        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->fullText);
        $this->assertEquals('HT-2024-001', $result->structuredFields['contract_no']);
        $this->assertEquals('测试公司A', $result->structuredFields['customer_name']);
        $this->assertEquals('测试公司B', $result->structuredFields['signer_party']);
        $this->assertEquals('100000', $result->structuredFields['amount']);
        $this->assertEquals('2024-01-15', $result->structuredFields['signed_date']);
        $this->assertGreaterThanOrEqual(90.0, $result->overallConfidence);
        $this->assertEquals(0, $result->retryCount);
        $this->assertFalse($result->preprocessed);
    }

    /**
     * 测试低置信度触发重试逻辑
     */
    public function testRecognizeWithLowConfidenceTriggersRetry(): void
    {
        // 创建测试文件
        $testFile = $this->testFilesDir . '/test_retry.pdf';
        file_put_contents($testFile, '%PDF-1.4 retry test content');

        // 创建 Mock DocumentParserService
        $mockParser = $this->createMock(DocumentParserService::class);
        $mockParser->method('parse')
            ->willReturn([
                'text' => '模糊的合同文本，部分信息不清晰',
                'is_scanned' => false,
                'pages' => 1,
            ]);

        // 创建 Mock SiliconFlowService
        // 第一次返回低置信度结果，重试后返回高置信度结果
        $mockSiliconFlow = $this->createMock(SiliconFlowService::class);
        $callCount = 0;
        $mockSiliconFlow->method('extractContractFields')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    // 第一次调用返回低置信度
                    return [
                        'contract_no' => 'HT-???',
                        'customer_name' => '?公司',
                        'amount' => '?',
                        'confidence' => [
                            'contract_no' => 40,
                            'customer_name' => 35,
                            'amount' => 30,
                        ],
                    ];
                }
                // 重试后返回高置信度
                return [
                    'contract_no' => 'HT-2024-002',
                    'customer_name' => '清晰公司名称',
                    'amount' => '50000',
                    'confidence' => [
                        'contract_no' => 95,
                        'customer_name' => 92,
                        'amount' => 88,
                    ],
                ];
            });

        // 创建 Mock ConfidenceCalculator
        // 第一次计算返回低值，后续计算返回高值
        $mockConfidenceCalculator = $this->createMock(ConfidenceCalculator::class);
        $calculateCount = 0;
        $mockConfidenceCalculator->method('calculate')
            ->willReturnCallback(function ($fieldConfidences) use (&$calculateCount) {
                $calculateCount++;
                if ($calculateCount === 1) {
                    // 第一次计算返回低置信度（触发重试）
                    return 35.0;
                }
                // 重试后返回高置信度
                return 91.67;
            });

        // 使用 Mock 服务创建 ContractOcrService
        // 设置较高的目标置信度以触发重试
        $service = new ContractOcrService(
            $mockParser,
            $mockSiliconFlow,
            $mockConfidenceCalculator
        );
        $service->setTargetConfidence(90.0);

        // 执行识别
        $result = $service->recognize($testFile);

        // 验证重试被触发
        $this->assertTrue($result->success);
        $this->assertGreaterThan(0, $result->retryCount, 'Retry count should be greater than 0 when confidence is low');
        $this->assertGreaterThanOrEqual(90.0, $result->overallConfidence);
        $this->assertEquals('HT-2024-002', $result->structuredFields['contract_no']);
        $this->assertEquals('清晰公司名称', $result->structuredFields['customer_name']);
    }
}
