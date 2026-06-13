<?php
/**
 * Gitee AI (模力方舟) API 配置文件
 * 文档: https://ai.gitee.com/docs/openapi/v1
 */
return [
    // API Key - 从 https://ai.gitee.com 获取
    'api_key' => '0AEECK4OGZX9JPMCHISZQOH80FLUCJQA7J074B18',

    // OCR 模型
    // DeepSeek-OCR-2: 最新版本，效果好
    // DeepSeek-OCR: 原版本
    // PaddleOCR-VL-1.5: 百度 PaddleOCR 视觉模型
    // HunyuanOCR: 腾讯混元 OCR
    'ocr_model' => 'DeepSeek-OCR-2',

    // API 地址
    'base_url' => 'https://ai.gitee.com',

    // 请求超时（秒）
    'timeout' => 300,
];
