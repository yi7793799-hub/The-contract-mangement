<?php
// app/DTO/RecognitionResult.php
declare(strict_types=1);

namespace App\DTO;

/**
 * 合同识别结果
 */
class RecognitionResult
{
    /** @var string */
    public $fullText;

    /** @var array */
    public $structuredFields;

    /** @var float */
    public $overallConfidence;

    /** @var array */
    public $fieldConfidences;

    /** @var int */
    public $retryCount;

    /** @var bool */
    public $success;

    /** @var string|null */
    public $errorMessage;

    /** @var bool */
    public $preprocessed;

    public function __construct(
        string $fullText,
        array $structuredFields,
        float $overallConfidence,
        array $fieldConfidences,
        int $retryCount,
        bool $success,
        ?string $errorMessage,
        bool $preprocessed
    ) {
        $this->fullText = $fullText;
        $this->structuredFields = $structuredFields;
        $this->overallConfidence = $overallConfidence;
        $this->fieldConfidences = $fieldConfidences;
        $this->retryCount = $retryCount;
        $this->success = $success;
        $this->errorMessage = $errorMessage;
        $this->preprocessed = $preprocessed;
    }

    /**
     * 转换为数组（用于API响应）
     */
    public function toArray(): array
    {
        return [
            'full_text' => $this->fullText,
            'fields' => $this->structuredFields,
            'confidence' => [
                'overall' => $this->overallConfidence,
                'fields' => $this->fieldConfidences,
            ],
            'retry_count' => $this->retryCount,
            'preprocessed' => $this->preprocessed,
            'success' => $this->success,
            'error_message' => $this->errorMessage,
        ];
    }
}
