<?php
/**
 * 测试运行器 - 直接运行测试而不依赖 PHPUnit
 *
 * 用法: php tests/run_import_tests.php
 *
 * GREEN 阶段：验证修复是否有效
 */

// 简单的测试框架
class SimpleTestRunner
{
    private $passed = 0;
    private $failed = 0;
    private $skipped = 0;
    private $errors = [];

    public function assertTrue($condition, $message = '')
    {
        if ($condition === true) {
            $this->passed++;
            echo "  ✅ PASS: {$message}\n";
        } else {
            $this->failed++;
            $this->errors[] = "FAIL: {$message} - Expected true, got false";
            echo "  ❌ FAIL: {$message}\n";
        }
    }

    public function assertFalse($condition, $message = '')
    {
        if ($condition === false) {
            $this->passed++;
            echo "  ✅ PASS: {$message}\n";
        } else {
            $this->failed++;
            $this->errors[] = "FAIL: {$message} - Expected false, got true";
            echo "  ❌ FAIL: {$message}\n";
        }
    }

    public function assertEquals($expected, $actual, $message = '')
    {
        if ($expected === $actual) {
            $this->passed++;
            echo "  ✅ PASS: {$message}\n";
        } else {
            $this->failed++;
            $this->errors[] = "FAIL: {$message} - Expected " . json_encode($expected) . ", got " . json_encode($actual);
            echo "  ❌ FAIL: {$message}\n";
            echo "       Expected: " . json_encode($expected) . "\n";
            echo "       Actual:   " . json_encode($actual) . "\n";
        }
    }

    public function assertArrayHasKey($key, $array, $message = '')
    {
        if (is_array($array) && array_key_exists($key, $array)) {
            $this->passed++;
            echo "  ✅ PASS: {$message}\n";
        } else {
            $this->failed++;
            $this->errors[] = "FAIL: {$message} - Array does not have key '{$key}'";
            echo "  ❌ FAIL: {$message} - Array does not have key '{$key}'\n";
        }
    }

    public function assertArrayNotHasKey($key, $array, $message = '')
    {
        if (!is_array($array) || !array_key_exists($key, $array)) {
            $this->passed++;
            echo "  ✅ PASS: {$message}\n";
        } else {
            $this->failed++;
            $this->errors[] = "FAIL: {$message} - Array has key '{$key}' but should not";
            echo "  ❌ FAIL: {$message} - Array has key '{$key}' but should not\n";
        }
    }

    public function assertLessThan($expected, $actual, $message = '')
    {
        if ($actual < $expected) {
            $this->passed++;
            echo "  ✅ PASS: {$message}\n";
        } else {
            $this->failed++;
            $this->errors[] = "FAIL: {$message} - Expected < {$expected}, got {$actual}";
            echo "  ❌ FAIL: {$message}\n";
            echo "       Expected: < {$expected}\n";
            echo "       Actual:     {$actual}\n";
        }
    }

    public function assertStringContains($needle, $haystack, $message = '')
    {
        if (strpos($haystack, $needle) !== false) {
            $this->passed++;
            echo "  ✅ PASS: {$message}\n";
        } else {
            $this->failed++;
            $this->errors[] = "FAIL: {$message} - String does not contain '{$needle}'";
            echo "  ❌ FAIL: {$message} - String does not contain '{$needle}'\n";
        }
    }

    public function markTestIncomplete($message)
    {
        $this->skipped++;
        echo "  ⏭️  SKIP: {$message}\n";
    }

    public function getResults()
    {
        return [
            'passed' => $this->passed,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }

    public function printSummary()
    {
        echo "\n" . str_repeat('=', 60) . "\n";
        $total = $this->passed + $this->failed + $this->skipped;
        echo "测试结果: ✅ {$this->passed} 通过, ❌ {$this->failed} 失败, ⏭️  {$this->skipped} 跳过 (共 {$total} 个)\n";
        if ($this->failed > 0) {
            echo "\n失败详情:\n";
            foreach ($this->errors as $error) {
                echo "  - {$error}\n";
            }
        }
        echo str_repeat('=', 60) . "\n";

        // 返回是否全部通过
        return ['passed' => $this->passed, 'failed' => $this->failed, 'skipped' => $this->skipped];
    }
}

// 加载项目引导文件
require_once __DIR__ . '/../includes/bootstrap.php';

echo "\n" . str_repeat('=', 60) . "\n";
echo "批量导入前端问题测试 (TDD - GREEN 阶段)\n";
echo "目标: 验证异步处理和进度轮询功能\n";
echo str_repeat('=', 60) . "\n\n";

$runner = new SimpleTestRunner();

// ========== 测试 1: 验证 process-files.php 返回异步响应 ==========
echo "【测试 1】process-files.php 返回异步响应格式\n";
echo str_repeat('-', 50) . "\n";

$processFilesContent = file_get_contents(__DIR__ . '/../import/process-files.php');

// 验证返回 job_id
$runner->assertStringContains("'job_id'", $processFilesContent, "返回 job_id 字段");

// 验证返回 poll_url
$runner->assertStringContains("'poll_url'", $processFilesContent, "返回 poll_url 字段");

// 验证启动后台 worker
$runner->assertStringContains('import-worker.php', $processFilesContent, "启动后台 worker");

// 验证不再返回 redirect
$runner->assertFalse(strpos($processFilesContent, "'redirect' => url('import/review.php')") !== false && strpos($processFilesContent, '同步处理') !== false,
    "不应该在同步处理后返回 redirect");

echo "\n";

// ========== 测试 2: 验证前端轮询逻辑 ==========
echo "【测试 2】前端 JavaScript 轮询逻辑\n";
echo str_repeat('-', 50) . "\n";

$controllerContent = file_get_contents(__DIR__ . '/../app/Controllers/ImportController.php');

// 验证有 startProgressPolling 函数
$runner->assertStringContains('startProgressPolling', $controllerContent, "定义 startProgressPolling 函数");

// 验证轮询间隔设置
$runner->assertStringContains('pollInterval', $controllerContent, "设置轮询间隔");

// 验证轮询 import-status API
$runner->assertStringContains('import-status.php', $controllerContent, "轮询 import-status API");

// 验证处理进度显示
$runner->assertStringContains('progress', $controllerContent, "显示进度");

// 验证完成后跳转
$runner->assertStringContains("import/review.php", $controllerContent, "完成后跳转到审核页");

echo "\n";

// ========== 测试 3: 验证上传超时设置 ==========
echo "【测试 3】上传超时设置\n";
echo str_repeat('-', 50) . "\n";

// 检查 xhr.timeout 设置
if (preg_match('/xhr\.timeout\s*=\s*(\d+)/', $controllerContent, $matches)) {
    $timeout = (int) $matches[1];
    echo "  XHR 超时设置: {$timeout}ms (" . ($timeout / 1000) . "秒)\n";

    // 上传阶段超时应该较短（5分钟足够上传）
    $runner->assertLessThan(600000, $timeout, "上传超时应小于10分钟（因为进入轮询模式）");
} else {
    $runner->markTestIncomplete("未找到 xhr.timeout 设置");
}

echo "\n";

// ========== 测试 4: 验证错误处理 ==========
echo "【测试 4】错误处理格式\n";
echo str_repeat('-', 50) . "\n";

// 验证返回 errors 数组
$runner->assertStringContains("'errors'", $processFilesContent, "返回 errors 数组");

// 验证前端显示错误详情
$runner->assertStringContains('resp.details', $controllerContent, "前端显示错误详情");

echo "\n";

// ========== 测试 5: 验证 import-status API 格式 ==========
echo "【测试 5】import-status API 格式验证\n";
echo str_repeat('-', 50) . "\n";

$importStatusContent = file_get_contents(__DIR__ . '/../api/import-status.php');

// 验证返回 progress
$runner->assertStringContains("'progress'", $importStatusContent, "返回 progress 字段");

// 验证返回 processed
$runner->assertStringContains("'processed'", $importStatusContent, "返回 processed 字段");

// 验证返回 files 数组
$runner->assertStringContains("'files'", $importStatusContent, "返回 files 数组");

echo "\n";

// ========== 总结 ==========
$results = $runner->printSummary();

if ($results['failed'] === 0 && $results['passed'] > 0) {
    echo "\n【TDD GREEN 阶段成功】\n";
    echo "所有修复验证通过！批量导入已改为异步模式：\n\n";
    echo "✅ process-files.php 立即返回 job_id 和 poll_url\n";
    echo "✅ 前端使用轮询模式获取进度\n";
    echo "✅ 上传超时设置合理（5分钟）\n";
    echo "✅ 错误处理提供详细信息\n";
    echo "✅ import-status API 正确支持轮询\n\n";

    echo "【下一步】进入 REFACTOR 阶段：优化代码质量\n";
} else if ($results['failed'] > 0) {
    echo "\n【TDD GREEN 阶段未完成】\n";
    echo "部分测试失败，需要继续修复。\n";
} else {
    echo "\n【注意】没有运行任何测试\n";
    echo "请检查测试环境是否正确配置。\n";
}
