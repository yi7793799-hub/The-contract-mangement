<?php
// tests/Services/ConfidenceCalculatorTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Services\ConfidenceCalculator;

class ConfidenceCalculatorTest extends TestCase
{
    /**
     * @var ConfidenceCalculator
     */
    private $calculator;

    protected function setUp(): void
    {
        $this->calculator = new ConfidenceCalculator();
    }

    public function testCalculateOverallConfidenceWithAllFields(): void
    {
        $fieldConfidences = [
            'contract_no' => 95,
            'amount' => 90,
            'signed_date' => 92,
            'customer_name' => 88,
            'signer_party' => 91,
            'contract_name' => 85,
            'signer_name' => 80,
            'phone' => 75,
            'effective_date' => 70,
            'expiry_date' => 68,
            'payment_type' => 95,
        ];

        $overall = $this->calculator->calculate($fieldConfidences);

        // 加权平均: 关键字段权重更高
        // (95*1.5 + 90*1.5 + 92*1.2 + 88*1.2 + 91*1.2 + 85*1.0 + 80*0.8 + 75*0.8 + 70*0.7 + 68*0.7 + 95*0.5)
        // / (1.5+1.5+1.2+1.2+1.2+1.0+0.8+0.8+0.7+0.7+0.5)
        $this->assertGreaterThan(85, $overall);
        $this->assertLessThan(95, $overall);
    }

    public function testCalculateWithMissingFields(): void
    {
        $fieldConfidences = [
            'contract_no' => 90,
            'amount' => 85,
        ];

        $overall = $this->calculator->calculate($fieldConfidences);

        // 仅计算存在的字段
        $this->assertGreaterThan(80, $overall);
    }

    public function testCalculateWithEmptyArray(): void
    {
        $overall = $this->calculator->calculate([]);

        $this->assertEquals(0.0, $overall);
    }

    public function testConfidenceMeetsTarget(): void
    {
        $fieldConfidences = [
            'contract_no' => 95,
            'amount' => 92,
            'signed_date' => 90,
            'customer_name' => 93,
            'signer_party' => 91,
        ];

        $this->assertTrue($this->calculator->meetsTarget($fieldConfidences, 90.0));
    }

    public function testConfidenceBelowTarget(): void
    {
        $fieldConfidences = [
            'contract_no' => 70,
            'amount' => 65,
            'signed_date' => 60,
        ];

        $this->assertFalse($this->calculator->meetsTarget($fieldConfidences, 90.0));
    }

    public function testCustomWeights(): void
    {
        $customWeights = [
            'contract_no' => 2.0,
            'amount' => 2.0,
        ];

        $calculator = new ConfidenceCalculator($customWeights);
        $fieldConfidences = [
            'contract_no' => 100,
            'amount' => 100,
        ];

        $overall = $calculator->calculate($fieldConfidences);

        $this->assertEquals(100.0, $overall);
    }
}