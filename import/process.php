<?php
// 处理导入请求
require_once __DIR__ . '/includes/bootstrap.php';

use App\Controllers\ImportController;

$controller = new ImportController();
$controller->process();
