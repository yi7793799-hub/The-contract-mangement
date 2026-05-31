<?php
// 更新合同信息
require_once __DIR__ . '/../../includes/bootstrap.php';

use App\Controllers\ImportController;

$controller = new ImportController();
$controller->update();