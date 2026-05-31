<?php
// 待审核列表页
require_once __DIR__ . '/includes/bootstrap.php';

use App\Controllers\ImportController;

$controller = new ImportController();
$controller->reviewList();
