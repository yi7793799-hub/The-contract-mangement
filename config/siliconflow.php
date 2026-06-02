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
    // deepseek-ai/DeepSeek-OCR: OCR专用（效果一般）
    'ocr_model' => 'Qwen/Qwen3-VL-8B-Instruct',

    // API 地址
    'base_url' => 'https://api.siliconflow.cn',

    // 请求超时（秒）
    'timeout' => 120,

    // 温度参数（0-1，越低越稳定）
    'temperature' => 0.1,
];