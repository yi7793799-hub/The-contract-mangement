<?php
// app/DTO/ContractFields.php
declare(strict_types=1);

namespace App\DTO;

/**
 * 合同字段数据
 */
class ContractFields
{
    /** @var string|null */
    public $contractNo;

    /** @var string|null */
    public $contractName;

    /** @var string|null */
    public $customerName;

    /** @var string|null */
    public $signerParty;

    /** @var string|null */
    public $signerName;

    /** @var string|null */
    public $phone;

    /** @var float|null */
    public $amount;

    /** @var string|null */
    public $signedDate;

    /** @var string|null */
    public $effectiveDate;

    /** @var string|null */
    public $expiryDate;

    /** @var string */
    public $paymentType;

    public function __construct(
        ?string $contractNo,
        ?string $contractName,
        ?string $customerName,
        ?string $signerParty,
        ?string $signerName,
        ?string $phone,
        ?float $amount,
        ?string $signedDate,
        ?string $effectiveDate,
        ?string $expiryDate,
        string $paymentType = 'receipt'
    ) {
        $this->contractNo = $contractNo;
        $this->contractName = $contractName;
        $this->customerName = $customerName;
        $this->signerParty = $signerParty;
        $this->signerName = $signerName;
        $this->phone = $phone;
        $this->amount = $amount;
        $this->signedDate = $signedDate;
        $this->effectiveDate = $effectiveDate;
        $this->expiryDate = $expiryDate;
        $this->paymentType = $paymentType;
    }

    /**
     * 从API响应数组创建
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['contract_no'] ?? null,
            $data['contract_name'] ?? null,
            $data['customer_name'] ?? null,
            $data['signer_party'] ?? null,
            $data['signer_name'] ?? null,
            $data['phone'] ?? null,
            isset($data['amount']) ? (float) $data['amount'] : null,
            $data['signed_date'] ?? null,
            $data['effective_date'] ?? null,
            $data['expiry_date'] ?? null,
            $data['payment_type'] ?? 'receipt'
        );
    }

    /**
     * 转换为数组
     */
    public function toArray(): array
    {
        return [
            'contract_no' => $this->contractNo,
            'contract_name' => $this->contractName,
            'customer_name' => $this->customerName,
            'signer_party' => $this->signerParty,
            'signer_name' => $this->signerName,
            'phone' => $this->phone,
            'amount' => $this->amount,
            'signed_date' => $this->signedDate,
            'effective_date' => $this->effectiveDate,
            'expiry_date' => $this->expiryDate,
            'payment_type' => $this->paymentType,
        ];
    }
}
