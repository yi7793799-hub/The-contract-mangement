<?php
// 导入上传页入口
require_once __DIR__ . '/includes/bootstrap.php';

use App\Controllers\ImportController;

$controller = new ImportController();
$controller->index();
