<?php
return array (
  'db' =>
  array (
    'host' => '127.0.0.1',
    'port' => 3306,
    'name' => 'htgl',
    'user' => 'root',
    'pass' => '123456aa',
    'charset' => 'utf8mb4',
  ),
  'app' =>
  array (
    'name' => '合同管理系统',
    'timezone' => 'Asia/Shanghai',
    'base_path' => '/',
  ),
  // 百度 OCR 配置已移至 config/baidu_ocr.php
  'deepseek' => [
    'api_key' => 'your_key',
    'model' => 'deepseek-chat',
  ],
  'import' => [
    'high_confidence' => 85,
    'low_confidence' => 60,
    'allowed_paths' => [
        'C:\\Users',
        'D:\\',
        'E:\\',
    ],
  ],
);
