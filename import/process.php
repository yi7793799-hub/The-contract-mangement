<?php
// 处理导入请求
set_time_limit(0);  // 不限制执行时间
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../includes/bootstrap.php';

use App\Controllers\ImportController;

$controller = new ImportController();
$controller->process();
