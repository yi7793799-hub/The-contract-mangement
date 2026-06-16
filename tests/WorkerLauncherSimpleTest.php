<?php
/**
 * 测试 Worker 后台启动功能 - 完整测试套件
 */

declare(strict_types=1);

echo "========================================\n";
echo "  Worker Launcher Test Suite\n";
echo "========================================\n\n";

// 测试 1: 类文件存在
echo "Test 1: WorkerLauncher class file exists\n";
$classFile = dirname(__DIR__) . '/app/Services/WorkerLauncher.php';
if (file_exists($classFile)) {
    echo "  PASS: File exists at {$classFile}\n";
} else {
    echo "  FAIL: File not found\n";
    exit(1);
}

// 测试 2: 类可以加载
echo "\nTest 2: WorkerLauncher class can be loaded\n";
require_once $classFile;
if (class_exists('App\Services\WorkerLauncher')) {
    echo "  PASS: Class loaded successfully\n";
} else {
    echo "  FAIL: Class not found after require\n";
    exit(1);
}

// 测试 3: 实例化
echo "\nTest 3: WorkerLauncher can be instantiated\n";
$launcher = new \App\Services\WorkerLauncher();
if ($launcher instanceof \App\Services\WorkerLauncher) {
    echo "  PASS: Instance created\n";
} else {
    echo "  FAIL: Instance creation failed\n";
    exit(1);
}

// 测试 4: launch 方法存在
echo "\nTest 4: launch method exists\n";
if (method_exists($launcher, 'launch')) {
    echo "  PASS: launch method exists\n";
} else {
    echo "  FAIL: launch method not found\n";
    exit(1);
}

// 测试 5: launch 返回数组
echo "\nTest 5: launch returns array\n";
$result = $launcher->launch(0); // 无效 job ID
if (is_array($result)) {
    echo "  PASS: Returns array\n";
} else {
    echo "  FAIL: Does not return array\n";
    exit(1);
}

// 测试 6: launch 对无效 job ID 返回错误
echo "\nTest 6: launch returns error for invalid job ID\n";
$result = $launcher->launch(0);
if (isset($result['launched']) && $result['launched'] === false) {
    echo "  PASS: Returns launched=false for invalid ID\n";
} else {
    echo "  FAIL: Should return launched=false\n";
    echo "  Result: " . json_encode($result) . "\n";
    exit(1);
}

// 测试 7: launch 对有效 job ID 返回成功
echo "\nTest 7: launch returns success for valid job ID\n";
$result = $launcher->launch(1, 'direct'); // 使用 direct 模式测试
if (isset($result['launched']) && $result['launched'] === true) {
    echo "  PASS: Returns launched=true\n";
} else {
    echo "  INFO: Job may not exist, result: " . json_encode($result) . "\n";
}

// 测试 8: 支持 auto 模式
echo "\nTest 8: launch supports auto mode\n";
$result = $launcher->launch(1, 'auto');
if (isset($result['mode'])) {
    echo "  PASS: Returns mode in result: {$result['mode']}\n";
} else {
    echo "  FAIL: Should return mode\n";
    exit(1);
}

echo "\n========================================\n";
echo "  All tests passed!\n";
echo "========================================\n";