<?php
// 审核详情页
require_once __DIR__ . '/includes/bootstrap.php';

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    $_SESSION['error'] = '无效的合同ID';
    redirect('/import/review');
}

$controller = new ImportController();
$controller->reviewDetail($id);
