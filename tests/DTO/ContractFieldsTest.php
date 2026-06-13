<?php
// tests/DTO/ContractFieldsTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\DTO\ContractFields;

class ContractFieldsTest extends TestCase
{
    public function testCreateContractFields(): void
    {
        $fields = new ContractFields(
            'HT-2024-001',
            '设计合同',
            '甲方公司',
            '乙方公司',
            '张三',
            '13800138000',
            100000.00,
            '2024-05-10',
            '2024-05-15',
            '2025-05-14',
            'receipt'
        );

        $this->assertEquals('HT-2024-001', $fields->contractNo);
        $this->assertEquals(100000.00, $fields->amount);
    }

    public function testFromArray(): void
    {
        $data = [
            'contract_no' => 'HT-002',
            'contract_name' => '测试合同',
            'customer_name' => '客户A',
            'signer_party' => '签约方B',
            'amount' => 50000,
            'signed_date' => '2024-01-01',
        ];

        $fields = ContractFields::fromArray($data);

        $this->assertEquals('HT-002', $fields->contractNo);
        $this->assertEquals('测试合同', $fields->contractName);
        $this->assertEquals(50000.0, $fields->amount);
    }

    public function testToArray(): void
    {
        $fields = new ContractFields(
            'HT-003',
            '合同',
            '客户',
            '签约方',
            null,
            null,
            10000.0,
            '2024-06-01',
            null,
            null,
            'receipt'
        );

        $array = $fields->toArray();

        $this->assertEquals('HT-003', $array['contract_no']);
        $this->assertEquals(10000.0, $array['amount']);
    }
}