# 合同文档智能识别功能设计文档

## 概述

实现一个电子合同文档识别功能，输入合同文档（PDF/图片/Word），输出结构化全文本识别结果，要求整体置信度达到90%以上。

## 需求规格

| 维度 | 需求 |
|------|------|
| 输入格式 | PDF（扫描版/文字版）、图片（JPG/PNG/WebP）、Word（DOCX/DOC） |
| 输出内容 | 全文文本 + 合同专用字段（编号、金额、日期、甲乙方等）+ 各字段置信度 |
| 置信度要求 | 整体平均置信度 ≥ 90% |
| 重试策略 | 参数调整 → 模型切换 → 图像预处理（组合策略） |

## 架构设计

### 整体架构

```
┌─────────────────────────────────────────────────────────────┐
│                    ContractOcrService                        │
│  (新增：合同文档识别服务，门面层)                              │
├─────────────────────────────────────────────────────────────┤
│  - recognize(File) → RecognitionResult                       │
│  - retryWithAdjustedParams()                                 │
│  - retryWithBetterModel()                                    │
│  - retryWithImagePreprocessing()                             │
│  - calculateOverallConfidence()                              │
└─────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌──────────────────────┐    ┌──────────────────────┐
│ DocumentParserService │    │   SiliconFlowService  │
│   (现有：文档解析)     │    │   (现有：AI服务)       │
│  - parse(File)        │    │  - ocrImage()         │
│  - parsePdf()         │    │  - extractFields()    │
│  - parseImage()       │    │  - chat()             │
└──────────────────────┘    └──────────────────────┘
```

### 模块职责

| 模块 | 职责 |
|------|------|
| ContractOcrService | 门面服务，协调文档解析、字段提取、重试逻辑、置信度计算 |
| DocumentParserService | 文档解析，提取纯文本（现有） |
| SiliconFlowService | AI服务调用，OCR识别和字段提取（现有） |

## 数据结构

### RecognitionResult

```php
class RecognitionResult
{
    public string $fullText;           // 全文文本
    public array $structuredFields;     // 结构化字段
    public float $overallConfidence;    // 整体置信度 (0-100)
    public array $fieldConfidences;     // 各字段置信度
    public int $retryCount;             // 重试次数
    public bool $success;               // 是否达标
    public ?string $errorMessage;       // 错误信息
    public bool $preprocessed;          // 是否经过图像预处理
}
```

### ContractFields

```php
class ContractFields
{
    public ?string $contractNo;         // 合同编号
    public ?string $contractName;       // 合同名称
    public ?string $customerName;       // 甲方名称
    public ?string $signerParty;        // 乙方名称
    public ?string $signerName;         // 签约人
    public ?string $phone;              // 联系电话
    public ?float $amount;              // 合同金额
    public ?string $signedDate;         // 签订日期 (YYYY-MM-DD)
    public ?string $effectiveDate;      // 生效日期 (YYYY-MM-DD)
    public ?string $expiryDate;         // 截止日期 (YYYY-MM-DD)
    public string $paymentType;         // 款项类型 (receipt/payment)
}
```

## 核心流程

### 主流程

```
输入文件
    │
    ▼
┌─────────────────────┐
│ 1. 文档解析          │
│ DocumentParserService│
│ 提取全文文本          │
└─────────────────────┘
    │
    ▼
┌─────────────────────┐
│ 2. 字段提取          │
│ SiliconFlowService   │
│ 提取结构化字段        │
└─────────────────────┘
    │
    ▼
┌─────────────────────┐
│ 3. 置信度计算        │
│ 加权平均计算         │
└─────────────────────┘
    │
    ▼
置信度 ≥ 90%? ──Yes──► 返回成功结果
    │
   No
    │
    ▼
┌─────────────────────┐
│ 4. 重试策略          │
│ (见重试流程)         │
└─────────────────────┘
```

### 重试流程

```
第1次识别失败
    │
    ▼
重试1: 调整参数
  - temperature: 0.1 → 0.05
  - max_tokens: 增加50%
    │
    ▼
置信度 ≥ 90%? ──Yes──► 返回成功结果
    │
   No
    │
    ▼
重试2: 切换模型
  - Qwen3-VL-8B → DeepSeek-V3 或 Qwen2.5-72B
    │
    ▼
置信度 ≥ 90%? ──Yes──► 返回成功结果
    │
   No
    │
    ▼
重试3: 图像预处理
  - 灰度化
  - 增强对比度 (1.5x)
  - 降噪 (MedianFilter)
  - 锐化
    │
    ▼
置信度 ≥ 90%? ──Yes──► 返回成功结果
    │
   No
    │
    ▼
返回结果，标记 success=false
提示人工复核
```

## 置信度计算

### 字段权重

关键字段权重更高，确保核心信息准确：

| 字段 | 权重 | 说明 |
|------|------|------|
| contract_no | 1.5 | 合同编号，唯一标识 |
| amount | 1.5 | 合同金额，核心财务数据 |
| signed_date | 1.2 | 签订日期，重要时间节点 |
| customer_name | 1.2 | 甲方名称，核心主体 |
| signer_party | 1.2 | 乙方名称，核心主体 |
| contract_name | 1.0 | 合同名称 |
| signer_name | 0.8 | 签约人姓名 |
| phone | 0.8 | 联系电话 |
| effective_date | 0.7 | 生效日期 |
| expiry_date | 0.7 | 截止日期 |
| payment_type | 0.5 | 款项类型 |

### 计算公式

```
整体置信度 = Σ(field_confidence × weight) / Σ(weights)
```

示例：
```
字段置信度: {contract_no: 95, amount: 88, signed_date: 92, ...}
整体置信度 = (95×1.5 + 88×1.5 + 92×1.2 + ...) / (1.5+1.5+1.2+...)
```

## 图像预处理

使用 Python + Pillow 实现图像增强：

```python
# scripts/preprocess_image.py
from PIL import Image, ImageEnhance, ImageFilter
import sys

def preprocess(input_path, output_path):
    img = Image.open(input_path)

    # 1. 灰度化（如果是彩色图像）
    if img.mode != 'L':
        img = img.convert('L')

    # 2. 增强对比度
    enhancer = ImageEnhance.Contrast(img)
    img = enhancer.enhance(1.5)

    # 3. 降噪
    img = img.filter(ImageFilter.MedianFilter(3))

    # 4. 锐化
    img = img.filter(ImageFilter.SHARPEN)

    img.save(output_path)
    print(output_path)

if __name__ == '__main__':
    preprocess(sys.argv[1], sys.argv[2])
```

## API 接口

### 请求

```
POST /api/contract-recognize.php
Content-Type: multipart/form-data

参数:
- file: 合同文档文件（必填）
- options[strict]: bool - 是否严格模式（默认true，强制90%置信度）
```

### 响应

```json
{
    "success": true,
    "data": {
        "full_text": "合同全文内容...",
        "fields": {
            "contract_no": "HT-2024-001",
            "contract_name": "工程设计合同",
            "customer_name": "某某建设有限公司",
            "signer_party": "某某设计有限公司",
            "signer_name": "张三",
            "phone": "13800138000",
            "amount": 1234567.89,
            "signed_date": "2024-05-10",
            "effective_date": "2024-05-15",
            "expiry_date": "2025-05-14",
            "payment_type": "receipt"
        },
        "confidence": {
            "overall": 92.5,
            "fields": {
                "contract_no": 95,
                "contract_name": 90,
                "customer_name": 92,
                "signer_party": 91,
                "signer_name": 88,
                "phone": 85,
                "amount": 94,
                "signed_date": 93,
                "effective_date": 85,
                "expiry_date": 82,
                "payment_type": 95
            }
        },
        "retry_count": 1,
        "preprocessed": false,
        "model_used": "Qwen/Qwen3-VL-8B-Instruct"
    }
}
```

### 错误响应

```json
{
    "success": false,
    "error": {
        "code": "CONFIDENCE_TOO_LOW",
        "message": "置信度不足90%，已重试3次仍无法达标",
        "details": {
            "final_confidence": 78.5,
            "retry_count": 3,
            "preprocessed": true
        }
    }
}
```

## 配置扩展

```php
// config/siliconflow.php 新增配置

return [
    // ... 现有配置 ...

    // OCR 识别配置
    'ocr' => [
        'target_confidence' => 90,      // 目标置信度
        'max_retries' => 3,             // 最大重试次数
        'retry_models' => [             // 重试模型顺序
            'Qwen/Qwen3-VL-8B-Instruct',
            'deepseek-ai/DeepSeek-V3',
            'Qwen/Qwen2.5-72B-Instruct',
        ],
        'preprocess' => true,           // 是否启用图像预处理
        'field_weights' => [            // 字段权重（可选覆盖）
            'contract_no' => 1.5,
            'amount' => 1.5,
            // ...
        ],
    ],
];
```

## 测试策略（TDD）

### 单元测试

```php
// tests/Services/ContractOcrServiceTest.php

class ContractOcrServiceTest extends TestCase
{
    // 基础功能测试
    public function testRecognizePdfDocument(): void;
    public function testRecognizeImageDocument(): void;
    public function testRecognizeWordDocument(): void;

    // 置信度测试
    public function testCalculateOverallConfidence(): void;
    public function testConfidenceMeets90Percent(): void;
    public function testConfidenceBelow90PercentTriggersRetry(): void;

    // 重试策略测试
    public function testRetryWithAdjustedParams(): void;
    public function testRetryWithBetterModel(): void;
    public function testRetryWithImagePreprocessing(): void;
    public function testMaxRetryLimit(): void;

    // 边界测试
    public function testEmptyFileThrowsException(): void;
    public function testUnsupportedFormatThrowsException(): void;
    public function testCorruptedFileHandling(): void;

    // Mock测试（不调用真实API）
    public function testRecognizeWithMockedApi(): void;
}
```

### 测试数据

准备以下测试文件：
- `tests/fixtures/sample_contract.pdf` - 文字版PDF
- `tests/fixtures/sample_contract_scanned.pdf` - 扫描版PDF
- `tests/fixtures/sample_contract.jpg` - 图片格式
- `tests/fixtures/sample_contract.docx` - Word文档
- `tests/fixtures/sample_contract_corrupted.pdf` - 损坏文件（边界测试）

### 集成测试

```php
// tests/Integration/ContractOcrIntegrationTest.php

class ContractOcrIntegrationTest extends TestCase
{
    // 真实API调用测试（需要API Key）
    public function testRealApiPdfRecognition(): void;
    public function testRealApiImageRecognition(): void;
}
```

## 实现计划

### Phase 1: 基础框架（TDD）
1. 编写测试用例骨架
2. 实现 ContractOcrService 基础结构
3. 实现置信度计算逻辑
4. 验证基础识别流程

### Phase 2: 重试机制
1. 实现参数调整重试
2. 实现模型切换重试
3. 实现图像预处理重试
4. 验证重试流程

### Phase 3: API集成
1. 实现 API 端点
2. 添加错误处理
3. 集成测试

### Phase 4: 优化完善
1. 性能优化
2. 日志记录
3. 文档完善

## 文件清单

| 文件 | 说明 |
|------|------|
| `app/Services/ContractOcrService.php` | 新增：合同识别服务 |
| `scripts/preprocess_image.py` | 新增：图像预处理脚本 |
| `api/contract-recognize.php` | 新增：API端点 |
| `tests/Services/ContractOcrServiceTest.php` | 新增：单元测试 |
| `tests/Integration/ContractOcrIntegrationTest.php` | 新增：集成测试 |
| `config/siliconflow.php` | 修改：新增OCR配置 |

## 风险与缓解

| 风险 | 缓解措施 |
|------|----------|
| API调用超时 | 设置合理超时时间，添加重试机制 |
| 图像预处理失败 | 捕获异常，跳过预处理继续识别 |
| 模型切换后置信度更低 | 记录每次结果，返回最高置信度的结果 |
| 测试数据准备困难 | 使用脱敏的真实合同作为测试数据 |
