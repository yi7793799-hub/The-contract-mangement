<?php
// 审核驳回
require_once __DIR__ . '/../../includes/bootstrap.php';

use App\Controllers\ImportController;

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['error'] = '无效的合同ID';
    redirect('/import/review');
}

$controller = new ImportController();
$controller->reject($id);
