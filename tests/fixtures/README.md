# 测试文件目录

此目录用于存放集成测试所需的测试文件。

## 文件命名约定

- `sample_contract.pdf` - 标准合同 PDF 测试文件
- `sample_contract.jpg` - 标准合同 JPEG 测试文件
- `sample_contract.png` - 标准合同 PNG 测试文件
- `sample_contract.docx` - 标准合同 DOCX 测试文件
- `low_quality_contract.jpg` - 低质量合同图像
- `blurry_contract.jpg` - 模糊合同图像（用于测试重试机制）
- `multi_page_contract.pdf` - 多页合同 PDF
- `real_contract.pdf` - 真实合同文件（可选）

## 注意事项

1. 测试文件不应包含真实的敏感信息
2. 如果目录为空，集成测试将自动跳过
3. 测试文件仅用于开发和测试目的

## 运行集成测试

```bash
# 仅运行集成测试
vendor/bin/phpunit --group integration tests/Integration/ContractOcrIntegrationTest.php

# 运行所有测试（排除集成测试）
vendor/bin/phpunit --exclude-group integration tests/

# 运行所有测试（包括集成测试）
vendor/bin/phpunit tests/
```

## 配置要求

运行集成测试前，请确保：

1. 在 `config/siliconflow.php` 中配置有效的 API Key
2. API Key 不应是占位符（不以 `your_` 开头）
3. 网络连接正常，可以访问 SiliconFlow API
