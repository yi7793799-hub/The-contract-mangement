<?php
// app/Services/ConfidenceCalculator.php
declare(strict_types=1);

namespace App\Services;

/**
 * 置信度计算器
 * 使用加权平均计算整体置信度
 */
class ConfidenceCalculator
{
    /**
     * 默认字段权重
     * 关键字段权重更高
     */
    private const DEFAULT_WEIGHTS = [
        'contract_no' => 1.5,    // 合同编号，唯一标识
        'amount' => 1.5,         // 合同金额，核心财务数据
        'signed_date' => 1.2,    // 签订日期，重要时间节点
        'customer_name' => 1.2,  // 甲方名称，核心主体
        'signer_party' => 1.2,   // 乙方名称，核心主体
        'contract_name' => 1.0,  // 合同名称
        'signer_name' => 0.8,    // 签约人姓名
        'phone' => 0.8,          // 联系电话
        'effective_date' => 0.7, // 生效日期
        'expiry_date' => 0.7,    // 截止日期
        'payment_type' => 0.5,   // 款项类型
    ];

    /**
     * @var array
     */
    private $weights;

    /**
     * @param array $customWeights
     */
    public function __construct(array $customWeights = [])
    {
        $this->weights = array_merge(self::DEFAULT_WEIGHTS, $customWeights);
    }

    /**
     * 计算整体置信度
     *
     * @param array $fieldConfidences 字段置信度 ['field_name' => 0-100]
     * @return float 整体置信度 (0-100)
     */
    public function calculate(array $fieldConfidences): float
    {
        if (empty($fieldConfidences)) {
            return 0.0;
        }

        $weightedSum = 0.0;
        $weightTotal = 0.0;

        foreach ($fieldConfidences as $field => $confidence) {
            $weight = $this->weights[$field] ?? 1.0;
            $weightedSum += $confidence * $weight;
            $weightTotal += $weight;
        }

        if ($weightTotal === 0.0) {
            return 0.0;
        }

        return round($weightedSum / $weightTotal, 2);
    }

    /**
     * 检查置信度是否达到目标
     *
     * @param array $fieldConfidences
     * @param float $target
     * @return bool
     */
    public function meetsTarget(array $fieldConfidences, float $target = 90.0): bool
    {
        return $this->calculate($fieldConfidences) >= $target;
    }

    /**
     * 获取当前权重配置
     *
     * @return array
     */
    public function getWeights(): array
    {
        return $this->weights;
    }
}