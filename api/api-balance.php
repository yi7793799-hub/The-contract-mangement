<?php
/**
 * API 余额查询接口
 */
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if (!current_admin()) {
        echo json_encode(['success' => false, 'error' => '请先登录']);
        exit;
    }

    $service = new \App\Services\ApiBalanceService();
    $balances = $service->getAllBalances();

    echo json_encode([
        'success' => true,
        'data' => $balances,
    ]);
} catch (\Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
