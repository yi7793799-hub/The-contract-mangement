# 合同批量导入功能 - 设计文档

| 项目 | 内容 |
|------|------|
| 版本 | V1.0 |
| 日期 | 2026-05-30 |
| 状态 | 已批准 |

---

## 一、功能概述

### 1.1 背景

现有合同管理系统仅支持手动创建合同，对于已有大量纸质/电子版合同的企业，手动录入效率低下。本功能实现批量导入合同文件，自动识别文本内容，减少人工录入工作量。

### 1.2 目标

- 支持批量上传文件夹，识别 Word、PDF、图片等格式
- 通过 OCR 提取文本，DeepSeek 大模型语义校验
- 自动提取关键字段并保存到数据库
- 对低置信度合同进行人工审核
- 保留原始文件作为合同附件

---

## 二、技术方案

### 2.1 技术栈

| 组件 | 技术 | 说明 |
|------|------|------|
| OCR 服务 | 百度智能云 OCR | 通用文字识别 API |
| 大模型 | DeepSeek API | 语义校验、字段提取 |
| 后台处理 | PHP 后台异步 | 不阻塞前端页面 |
| 消息通知 | Session 闪存 | 处理完成后页面通知 |

### 2.2 文件格式支持

| 格式 | 处理方式 |
|------|----------|
| `.docx` | PHPWord 库解析文本 |
| `.doc` | 转换后解析或 OCR |
| `.pdf` | 文字型 PDF 直接提取，扫描版走 OCR |
| `.jpg/.png/.webp` | 百度 OCR 图像识别 |
| 其他 | 返回"不支持格式"错误 |

### 2.3 文本识别流程

```
1. 文件上传到临时目录
2. 根据文件类型选择处理方式：
   - Word (.doc/.docx) → PHPWord 提取正文
   - 文字型 PDF → pdftotext 或 fpdf 提取
   - 扫描型 PDF/图片 → 百度 OCR API
3. 调用 DeepSeek API 提取字段
4. 计算置信度并分级处理
5. 保存到数据库或标记待审核
6. 清理临时文件
```

---

## 三、数据库设计

### 3.1 contracts 表变更

```sql
-- 新增枚举值
ALTER TABLE contracts MODIFY COLUMN status
  ENUM('ongoing','completed','terminated','expiring','pending_review')
  NOT NULL DEFAULT 'ongoing';

-- 新增字段
ALTER TABLE contracts ADD COLUMN import_confidence DECIMAL(5,2) DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN import_fields JSON DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN ocr_raw_text LONGTEXT DEFAULT NULL;
ALTER TABLE contracts ADD COLUMN import_job_id INT UNSIGNED DEFAULT NULL;
```

### 3.2 import_jobs 表（导入任务）

```sql
CREATE TABLE import_jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folder_name VARCHAR(255) NOT NULL,
    status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
    total_files INT UNSIGNED DEFAULT 0,
    success_count INT UNSIGNED DEFAULT 0,
    pending_count INT UNSIGNED DEFAULT 0,
    failed_count INT UNSIGNED DEFAULT 0,
    created_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME DEFAULT NULL,
    INDEX idx_status (status),
    INDEX idx_created_by (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3.3 import_files 表（导入文件明细）

```sql
CREATE TABLE import_files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(50) NOT NULL,
    status ENUM('pending','success','pending_review','failed') DEFAULT 'pending',
    contract_id INT UNSIGNED DEFAULT NULL,
    confidence DECIMAL(5,2) DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    ocr_text LONGTEXT DEFAULT NULL,
    raw_api_response JSON DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_job_id (job_id),
    INDEX idx_status (status),
    FOREIGN KEY (job_id) REFERENCES import_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 四、API 设计

### 4.1 百度 OCR API

```php
// 调用百度通用文字识别
$accessToken = getBaiduAccessToken($ak, $sk);
$response = httpPost('https://aip.baidubce.com/rest/2.0/ocr/v1/general_basic', [
    'access_token' => $accessToken,
    'image' => base64_encode(file_get_contents($imagePath))
]);
```

### 4.2 DeepSeek API

```php
// 调用 DeepSeek 进行字段提取
$prompt = "请从以下合同文本中提取关键信息，返回JSON格式：...";
$response = httpPost('https://api.deepseek.com/v1/chat/completions', [
    'model' => 'deepseek-chat',
    'messages' => [
        ['role' => 'system', 'content' => '你是一个合同信息提取助手'],
        ['role' => 'user', 'content' => $prompt]
    ]
]);
```

### 4.3 字段提取 Prompt

```
从以下合同文本中提取关键信息，以JSON格式返回：
{
    "contract_no": "合同编号",
    "contract_name": "合同名称",
    "customer_name": "客户名称",
    "signer_party": "签约方",
    "signer_name": "签约人",
    "phone": "联系电话",
    "amount": "金额数字",
    "signed_date": "签订日期 YYYY-MM-DD",
    "effective_date": "生效日期 YYYY-MM-DD",
    "expiry_date": "截止日期 YYYY-MM-DD",
    "payment_type": "receipt/payment",
    "type_id": "合同类型ID"
}

如果某字段无法提取，设为null。

合同文本如下：
{ocr_text}
```

---

## 五、置信度分级

### 5.1 分级标准

| 等级 | 置信度 | 处理方式 | 合同状态 |
|------|--------|----------|----------|
| 高 | ≥ 85% | 直接创建 | `ongoing` |
| 中 | 60-85% | 创建 + 标记 | `ongoing`（有低置信度字段标记） |
| 低 | < 60% | 创建草稿 | `pending_review` |
| 失败 | 无法识别 | 记录错误 | 不创建合同 |

### 5.2 置信度计算

```php
// 各字段置信度由 DeepSeek 返回
$fields = ['contract_no', 'contract_name', 'customer_name', 'amount', ...];
$confidence = array_sum($fieldConfidences) / count($fieldConfidences);
```

### 5.3 置信度可视化

在审核页面，每个字段显示颜色标记：
- 绿色：置信度 ≥ 85%
- 黄色：置信度 60-85%
- 红色：置信度 < 60%

---

## 六、页面设计

### 6.1 导入上传页 `/import`

```
┌─────────────────────────────────────────────────────────────┐
│ 合同批量导入                                                 │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │                                                     │   │
│  │         📁 点击选择文件夹或将文件夹拖拽到此处        │   │
│  │                                                     │   │
│  │         支持格式: .doc .docx .pdf .jpg .png         │   │
│  │                                                     │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  已选择文件夹: /path/to/contracts_folder                    │
│  文件数量: 25 个                                            │
│                                                             │
│  [开始导入]                                                 │
│                                                             │
│  ─────────────────────────────────────────────────────────  │
│                                                             │
│  导入进度:                                                  │
│  ████████████████████░░░░░░░░  60%                         │
│                                                             │
│  处理中: 15/25                                              │
│  成功: 12 | 待审核: 2 | 失败: 1                            │
│                                                             │
│  [查看待审核合同 (2)]                                       │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 6.2 待审核列表页 `/import/review`

```
┌─────────────────────────────────────────────────────────────┐
│ 待审核合同                                                   │
├─────────────────────────────────────────────────────────────┤
│ [筛选: 全部 ▼]  [搜索: 关键词...]        [导出失败记录]     │
├─────────────────────────────────────────────────────────────┤
│ ☐ │ 文件名          │ 导入时间      │ 置信度 │ 操作       │
├───┼────────────────┼──────────────┼────────┼────────────┤
│ ☐ │ 采购合同A.pdf  │ 2026-05-30  │  52%   │ [审核]     │
│ ☐ │ 服务合同B.docx  │ 2026-05-30  │  48%   │ [审核]     │
│ ☐ │ 租赁合同C.pdf  │ 2026-05-29  │  35%   │ [审核]     │
└───┴────────────────┴──────────────┴────────┴────────────┘
│                                                             │
│ [批量通过]  [批量驳回]                                      │
└─────────────────────────────────────────────────────────────┘
```

### 6.3 审核详情页 `/import/review/{id}`

```
┌─────────────────────────────────────────────────────────────┐
│ 审核合同: 采购合同A.pdf                          [返回列表] │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  原始文件: [预览] 采购合同A.pdf                              │
│                                                             │
│  ─────────────────────────────────────────────────────────  │
│                                                             │
│  识别结果:                                                  │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 字段               │ 识别值              │ 置信度    │   │
│  ├────────────────────┼────────────────────┼───────────┤   │
│  │ 合同编号           │ HT-2026-001        │ 95%  ✅   │   │
│  │ 合同名称           │ 办公用品采购合同    │ 92%  ✅   │   │
│  │ 客户名称           │ 某某科技有限公司    │ 88%  ✅   │   │
│  │ 金额               │ [  150,000.00  ]   │ 52%  ⚠️   │   │
│  │ 签订日期           │ [2026-01-15    ]   │ 78%  ⚠️   │   │
│  │ ...                │                    │           │   │
│  └─────────────────────────────────────────────────────┘   │
│                                                             │
│  ⚠️ 有 2 个字段置信度较低，请核实后保存                       │
│                                                             │
│  [驳回]                                      [审核通过]     │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 七、功能清单

### 7.1 导入上传页

| 功能 | 说明 |
|------|------|
| 文件夹选择 | 支持点击选择或拖拽 |
| 格式校验 | 仅允许指定格式，拒绝其他格式 |
| 进度展示 | 实时显示处理进度和结果统计 |
| 消息通知 | 处理完成后页面顶部显示通知 |
| 快捷入口 | 查看待审核合同链接 |

### 7.2 待审核列表页

| 功能 | 说明 |
|------|------|
| 列表展示 | 显示所有待审核合同 |
| 筛选 | 按状态、日期范围筛选 |
| 搜索 | 关键词搜索文件名 |
| 批量操作 | 批量通过/驳回 |
| 分页 | 每页 20 条 |

### 7.3 审核详情页

| 功能 | 说明 |
|------|------|
| 原始文件预览 | 新窗口打开原始文件 |
| 字段展示 | 显示所有识别字段及置信度 |
| 字段修正 | 可编辑修正低置信度字段 |
| 审核操作 | 通过（改状态为 ongoing）或驳回（删除合同） |
| 处理记录 | 记录审核人、审核时间 |

---

## 八、权限设计

### 8.1 权限点

| 权限 | 说明 |
|------|------|
| `import.view` | 查看导入页面 |
| `import.create` | 执行导入操作 |
| `import.review` | 审核待处理合同 |
| `import.review.edit` | 修改识别字段 |

### 8.2 角色权限

| 角色 | import.view | import.create | import.review | import.review.edit |
|------|:------------:|:--------------:|:--------------:|:------------------:|
| 超级管理员 | ✓ | ✓ | ✓ | ✓ |
| 普通管理员 | ✓ | ✓ | ✓ | ✓ |
| 业务员 | ✓ | ✓ | ✗ | ✗ |

---

## 九、错误处理

### 9.1 错误类型

| 错误类型 | 处理方式 |
|----------|----------|
| 百度 OCR 失败 | 记录错误，标记文件状态为 failed |
| DeepSeek API 失败 | 重试 3 次，仍失败则标记为 failed |
| 文件格式不支持 | 直接标记失败，提示"不支持的文件格式" |
| 合同编号重复 | 标记失败，提示"合同编号已存在" |
| 数据库写入失败 | 标记失败，记录错误日志 |

### 9.2 失败记录

失败的文件记录到 `import_files` 表，`error_message` 字段存储错误原因。用户可在待审核列表页面查看失败记录并重新处理。

---

## 十、配置项

### 10.1 系统配置 (config/config.php)

```php
return [
    // 百度 OCR 配置
    'baidu_ocr' => [
        'ak' => 'your_ak',      // API Key
        'sk' => 'your_sk',      // Secret Key
    ],

    // DeepSeek 配置
    'deepseek' => [
        'api_key' => 'your_key',
        'model' => 'deepseek-chat',
    ],

    // 置信度阈值
    'import' => [
        'high_confidence' => 85,   // 高置信度阈值
        'low_confidence' => 60,    // 低置信度阈值
    ],
];
```

---

## 十一、待解决问题

| 问题 | 状态 |
|------|------|
| 百度 OCR Access Token 获取方式 | 待确认 |
| DeepSeek API 费用账户 | 待确认 |
| .doc 旧版 Word 格式处理方案 | 待确认 |

---

## 十二、后续优化方向

| 功能 | 说明 |
|------|------|
| 合同模板管理 | 用户自定义字段映射规则 |
| 导入历史 | 记录每次导入的任务和统计 |
| 数据统计 | 导入成功率、常见错误分析 |
| 自动分类 | 根据内容自动推荐合同类型 |

---

*文档结束*
