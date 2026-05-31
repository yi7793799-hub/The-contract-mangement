<?php
// 批量审核驳回
require_once __DIR__ . '/../includes/bootstrap.php';

use App\Controllers\ImportController;

$controller = new ImportController();
$controller->batchReject();
