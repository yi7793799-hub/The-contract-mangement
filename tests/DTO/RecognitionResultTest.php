<?php
// tests/DTO/RecognitionResultTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\DTO\RecognitionResult;

class RecognitionResultTest extends TestCase
{
    public function testCreateRecognitionResult(): void
    {
        $result = new RecognitionResult(
            '合同全文内容',
            ['contract_no' => 'HT-2024-001'],
            92.5,
            ['contract_no' => 95],
            1,
            true,
            null,
            false
        );

        $this->assertEquals('合同全文内容', $result->fullText);
        $this->assertEquals(92.5, $result->overallConfidence);
        $this->assertTrue($result->success);
    }

    public function testToArray(): void
    {
        $result = new RecognitionResult(
            '测试文本',
            ['contract_no' => 'HT-001'],
            85.0,
            ['contract_no' => 90],
            0,
            true,
            null,
            false
        );

        $array = $result->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('full_text', $array);
        $this->assertArrayHasKey('fields', $array);
        $this->assertArrayHasKey('confidence', $array);
    }

    public function testFailedResult(): void
    {
        $result = new RecognitionResult(
            '',
            [],
            45.0,
            [],
            3,
            false,
            '置信度不足',
            true
        );

        $this->assertFalse($result->success);
        $this->assertEquals('置信度不足', $result->errorMessage);
    }
}