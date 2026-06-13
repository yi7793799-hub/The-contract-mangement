# 合同文档识别 API

## 接口说明

识别电子合同文档，提取结构化字段并计算置信度。

## 请求

```
POST /api/contract-recognize.php
Content-Type: multipart/form-data
```

### 参数

| 参数 | 类型 | 必填 | 说明 |
|------|------|------|------|
| file | file | 是 | 合同文档文件（PDF/JPG/PNG/DOCX） |
| options[strict] | bool | 否 | 是否严格模式，默认true |

## 响应

### 成功响应

```json
{
    "success": true,
    "data": {
        "full_text": "合同全文...",
        "fields": {
            "contract_number": "HT-2024-001",
            "contract_name": "采购合同",
            "party_a": "甲方公司名称",
            "party_b": "乙方公司名称",
            "amount": "100000.00",
            "sign_date": "2024-01-15",
            "start_date": "2024-01-20",
            "end_date": "2024-12-31"
        },
        "confidence": {
            "overall": 92.5,
            "fields": {
                "contract_number": 95.0,
                "contract_name": 90.0,
                "party_a": 88.5,
                "party_b": 91.0,
                "amount": 96.0,
                "sign_date": 93.5,
                "start_date": 89.0,
                "end_date": 90.5
            }
        }
    }
}
```

### 错误响应

```json
{
    "success": false,
    "error": {
        "code": "CONFIDENCE_TOO_LOW",
        "message": "置信度不足90%"
    }
}
```

### 错误码说明

| 错误码 | 说明 |
|--------|------|
| INVALID_FILE_TYPE | 不支持的文件类型 |
| FILE_TOO_LARGE | 文件超过大小限制（10MB） |
| OCR_FAILED | OCR识别失败 |
| EXTRACTION_FAILED | 字段提取失败 |
| CONFIDENCE_TOO_LOW | 整体置信度低于阈值 |

## 支持的文件格式

| 格式 | 扩展名 | 说明 |
|------|--------|------|
| PDF | .pdf | 支持文字版和扫描版 |
| 图片 | .jpg, .jpeg, .png, .webp | 支持常见图片格式 |
| Word | .docx, .doc | 支持 Word 文档 |

**文件大小限制：** 最大 10MB

## 置信度说明

### 计算方法

置信度基于以下因素计算：
1. **OCR质量** - 文字识别的准确度
2. **字段匹配** - 正则匹配的可靠性
3. **字段完整性** - 必要字段是否存在

### 字段权重

关键字段权重更高，对整体置信度影响更大：

| 字段 | 权重 | 说明 |
|------|------|------|
| contract_number | 1.5 | 合同编号 |
| amount | 1.5 | 合同金额 |
| sign_date | 1.2 | 签订日期 |
| contract_name | 1.0 | 合同名称 |
| party_a | 1.0 | 甲方名称 |
| party_b | 1.0 | 乙方名称 |
| start_date | 0.8 | 开始日期 |
| end_date | 0.8 | 结束日期 |

### 置信度阈值

- **严格模式（默认）：** 整体置信度 >= 90%
- **宽松模式：** 整体置信度 >= 70%

## 重试策略

系统会自动重试以提高置信度：

### 重试层级

1. **参数调整**
   - 调整温度参数（temperature）
   - 尝试不同的提取提示词

2. **模型切换**
   - 主模型失败时切换备用模型
   - 支持的模型：DeepSeek、SiliconFlow

3. **图像预处理**
   - 增强对比度
   - 降噪处理
   - 倾斜校正

### 重试限制

- 最大重试次数：3次
- 重试间隔：递增（1s, 2s, 3s）
- 总超时时间：60秒

## 使用示例

### cURL

```bash
curl -X POST \
  -F "file=@contract.pdf" \
  -F "options[strict]=true" \
  http://localhost/api/contract-recognize.php
```

### JavaScript

```javascript
const formData = new FormData();
formData.append('file', fileInput.files[0]);
formData.append('options[strict]', 'true');

const response = await fetch('/api/contract-recognize.php', {
    method: 'POST',
    body: formData
});

const result = await response.json();
console.log(result.data.fields);
```

### PHP

```php
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'http://localhost/api/contract-recognize.php');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'file' => new CURLFile('/path/to/contract.pdf'),
    'options[strict]' => 'true'
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
```

## 注意事项

1. **文件预处理** - 建议上传前压缩大文件
2. **超时处理** - 客户端应设置至少60秒超时
3. **错误重试** - 网络错误应由客户端重试
4. **结果缓存** - 相同文件不会缓存结果，每次都会重新识别
