<?php
// tests/Controllers/ImportControllerTest.php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 批量导入前端测试
 *
 * 测试目标：
 * 1. 验证长时间处理时前端应该显示进度而非卡死
 * 2. 验证导入失败时应该有明确的错误提示
 * 3. 验证前端超时应该优雅处理而非直接报错
 */
class ImportControllerTest extends TestCase
{
    /**
     * @var string
     */
    private $testFilesDir;

    /**
     * @var string 测试用的临时上传目录
     */
    private $uploadDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testFilesDir = sys_get_temp_dir() . '/import_test_' . uniqid();
        $this->uploadDir = sys_get_temp_dir() . '/upload_test_' . uniqid();

        if (!is_dir($this->testFilesDir)) {
            mkdir($this->testFilesDir, 0755, true);
        }
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // 清理测试文件
        if (is_dir($this->testFilesDir)) {
            $files = glob($this->testFilesDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->testFilesDir);
        }
        if (is_dir($this->uploadDir)) {
            $files = glob($this->uploadDir . '/*');
            foreach ($files as $file) {
                @unlink($file);
            }
            @rmdir($this->uploadDir);
        }
        parent::tearDown();
    }

    /**
     * 【失败测试 1】测试导入处理 API 应返回进度信息而非阻塞等待完成
     *
     * 当前问题：前端发送请求后一直等待直到处理完成或超时
     * 期望行为：API 应立即返回任务ID，前端通过轮询获取进度
     */
    public function testProcessFilesApiShouldReturnImmediatelyWithJobId(): void
    {
        // 创建多个测试 PDF 文件模拟批量导入
        for ($i = 1; $i <= 3; $i++) {
            $testFile = $this->testFilesDir . '/contract_' . $i . '.pdf';
            file_put_contents($testFile, '%PDF-1.4 mock content ' . $i);
        }

        // 模拟上传文件
        $files = glob($this->testFilesDir . '/*.pdf');
        $this->assertGreaterThanOrEqual(3, count($files), 'Should have test files');

        // 当前实现是同步处理，这会导致前端等待
        // 我们期望的行为是：API 立即返回任务ID，让前端轮询进度

        // 这个测试目前会失败，因为 process-files.php 是同步处理的
        // 期望: 返回 ['job_id' => X, 'status' => 'processing', 'poll_url' => '...']
        // 实际: 返回 ['success' => true, 'redirect' => '...'] 需要等待完成

        // 模拟 API 调用
        $startTime = microtime(true);

        // 模拟调用 process-files.php（这里使用 Service 来测试逻辑）
        // 由于无法直接测试 HTTP API，我们测试 Service 的行为

        // 期望：返回任务 ID 后立即结束，而非等待处理完成
        // 当前失败原因：ContractImportService::processFiles 是同步处理的

        $this->markTestIncomplete('此测试需要修改 API 为异步模式才能通过');

        // 期望的行为：
        // $response = $this->callApi('POST', '/import/process-files.php', ['files' => $files]);
        // $this->assertArrayHasKey('job_id', $response);
        // $this->assertArrayHasKey('poll_url', $response);
        // $this->assertArrayNotHasKey('redirect', $response); // 不应该立即跳转
        // $elapsed = microtime(true) - $startTime;
        // $this->assertLessThan(2.0, $elapsed, 'API should return in under 2 seconds');
    }

    /**
     * 【失败测试 2】测试导入失败时应返回明确的错误信息
     *
     * 当前问题：失败时错误信息不清晰，用户不知道具体原因
     * 期望行为：返回结构化的错误信息，包含文件名和具体错误原因
     */
    public function testImportFailureShouldReturnDetailedError(): void
    {
        // 创建一个空文件（会导致处理失败）
        $emptyFile = $this->testFilesDir . '/empty_contract.pdf';
        file_put_contents($emptyFile, ''); // 空文件

        // 创建一个不支持格式的文件
        $unsupportedFile = $this->testFilesDir . '/unsupported.xyz';
        file_put_contents($unsupportedFile, 'test content');

        // 期望的错误响应格式：
        // [
        //     'error' => true,
        //     'message' => '导入处理失败',
        //     'details' => [
        //         ['file' => 'empty_contract.pdf', 'error' => '文件为空'],
        //         ['file' => 'unsupported.xyz', 'error' => '不支持的文件格式']
        //     ]
        // ]

        // 当前实际行为可能只是简单返回 "导入出错"

        $this->markTestIncomplete('此测试需要改进错误处理机制才能通过');
    }

    /**
     * 【失败测试 3】测试前端超时应该有明确的提示而非直接报错
     *
     * 当前问题：XHR 超时设置10分钟，超时后只显示"请求超时"无后续处理
     * 期望行为：超时后告知用户后台仍在处理，提供查看进度的方式
     */
    public function testFrontendTimeoutShouldProvideFollowUpAction(): void
    {
        // 测试前端 JavaScript 超时处理逻辑

        // 当前前端代码：
        // xhr.ontimeout = function() {
        //     progressArea.innerHTML = '<div class="mf-alert mf-alert--warning">请求超时，但后台可能仍在处理中。请稍后刷新查看结果。</div>';
        // };

        // 问题：只提示用户刷新，没有提供任务追踪方式

        // 期望改进：
        // 1. 如果是异步模式，返回任务ID，超时后显示"查看进度"按钮
        // 2. 提供"取消任务"选项

        $this->markTestIncomplete('此测试需要实现异步任务追踪机制');
    }

    /**
     * 【失败测试 4】测试大文件导入时的进度反馈
     *
     * 当前问题：处理大文件时没有进度反馈，用户不知道处理到哪个阶段
     * 期望行为：提供 OCR 进度、字段提取进度等阶段性反馈
     */
    public function testLargeFileImportShouldProvideProgressFeedback(): void
    {
        // 创建较大的测试文件（模拟多页 PDF）
        $largeFile = $this->testFilesDir . '/large_contract.pdf';
        file_put_contents($largeFile, '%PDF-1.4 ' . str_repeat('mock content page ', 100));

        $fileSize = filesize($largeFile);
        $this->assertGreaterThan(1000, $fileSize, 'Should create a file larger than 1KB');

        // 期望的进度反馈：
        // {
        //     'job_id': X,
        //     'progress': {
        //         'current_file': 'large_contract.pdf',
        //         'stage': 'ocr', // 或 'extract', 'save'
        //         'percent': 50,
        //         'message': '正在识别第5页...'
        //     }
        // }

        $this->markTestIncomplete('此测试需要实现进度反馈机制');
    }

    /**
     * 【失败测试 5】测试导入状态 API 应正确返回进度
     *
     * 验证 api/import-status.php 的返回格式是否正确
     */
    public function testImportStatusApiShouldReturnCorrectFormat(): void
    {
        // 验证 import-status.php 返回的数据结构

        // 期望返回：
        // {
        //     'job': { id, folder_name, status, total_files, success_count, pending_count, failed_count },
        //     'progress': 50.5, // 百分比
        //     'processed': 5,
        //     'files': [{ id, file_name, status, confidence, error_message }]
        // }

        // 测试边界情况：无任务、任务不存在

        $this->markTestIncomplete('需要验证 import-status.php 的实际返回');
    }

    /**
     * 【集成测试】测试完整的批量导入流程（异步模式）
     *
     * 验证从上传到完成的完整流程：
     * 1. 上传文件 -> 返回任务ID
     * 2. 轮询进度 -> 显示进度条
     * 3. 完成后 -> 自动跳转到审核页面
     */
    public function testFullBatchImportFlowWithAsyncMode(): void
    {
        $this->markTestSkipped('需要先实现异步模式才能运行此集成测试');

        // 完整流程测试：
        // Step 1: POST /import/process-files.php -> 返回 job_id
        // Step 2: GET /api/import-status.php?job_id=X -> 返回进度
        // Step 3: 当 status === 'completed' -> 跳转审核页面
    }
}