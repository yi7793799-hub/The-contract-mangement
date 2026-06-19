<?php
// 更新并审核通过
require_once dirname(__DIR__) . '/includes/bootstrap.php';

use App\Controllers\ImportController;

$controller = new ImportController();
$controller->updateAndApprove();