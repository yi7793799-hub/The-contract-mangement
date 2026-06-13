<?php
/**
 * SiliconFlow API 配置文件
 * 文档: https://api-docs.siliconflow.cn/docs/api/chat-completions-post
 */
return [
    // API Key
    'api_key' => 'sk-aseznbkvypgobpjyxmwrfuvjgbenrzcwsotdjsivncoxtigy',

    // 模型选择
    // deepseek-ai/DeepSeek-V4-Pro: 最新最强模型
    // deepseek-ai/DeepSeek-V3: 高性价比
    // Qwen/Qwen2.5-72B-Instruct: Qwen最强
    // Qwen/Qwen2.5-7B-Instruct: Qwen轻量版
    'model' => 'deepseek-ai/DeepSeek-V3',

    // OCR 模型 (用于图片识别)
    // Qwen/Qwen3-VL-8B-Instruct: 视觉模型，OCR效果好
    // deepseek-ai/DeepSeek-OCR: OCR专用模型
    'ocr_model' => 'deepseek-ai/DeepSeek-OCR',

    // API 地址
    'base_url' => 'https://api.siliconflow.cn',

    // 请求超时（秒）
    'timeout' => 120,

    // 温度参数（0-1，越低越稳定）
    'temperature' => 0.1,

    // OCR 识别配置
    'ocr' => [
        // 目标置信度
        'target_confidence' => 90,

        // 最大重试次数
        'max_retries' => 3,

        // 重试模型顺序
        'retry_models' => [
            'Qwen/Qwen3-VL-8B-Instruct',
            'deepseek-ai/DeepSeek-V3',
            'Qwen/Qwen2.5-72B-Instruct',
        ],

        // 是否启用图像预处理
        'preprocess' => true,

        // 字段权重
        'field_weights' => [
            'contract_no' => 1.5,
            'amount' => 1.5,
            'signed_date' => 1.2,
            'customer_name' => 1.2,
            'signer_party' => 1.2,
            'contract_name' => 1.0,
            'signer_name' => 0.8,
            'phone' => 0.8,
            'effective_date' => 0.7,
            'expiry_date' => 0.7,
            'payment_type' => 0.5,
        ],
    ],
];