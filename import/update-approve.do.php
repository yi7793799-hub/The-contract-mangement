<?php
// 更新并审核通过
require_once __DIR__ . '/../../includes/bootstrap.php';

use App\Controllers\ImportController;

$controller = new ImportController();
$controller->updateAndApprove();