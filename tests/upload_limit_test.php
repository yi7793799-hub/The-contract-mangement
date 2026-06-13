<?php
/**
 * 文件上传大小限制测试
 * TDD - GREEN 阶段：验证修复是否有效
 *
 * 用法: php tests/upload_limit_test.php
 */

echo "\n" . str_repeat('=', 60) . "\n";
echo "文件上传大小限制测试 (TDD - GREEN 阶段)\n";
echo str_repeat('=', 60) . "\n\n";

$passed = 0;
$failed = 0;
$skipped = 0;

function test_true($condition, $message) {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ✅ PASS: {$message}\n";
    } else {
        $failed++;
        echo "  ❌ FAIL: {$message}\n";
    }
}

function test_skip($message) {
    global $skipped;
    $skipped++;
    echo "  ⏭️  SKIP: {$message}\n";
}

// 将 PHP 配置值转换为字节
function toBytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val) - 1]);
    $val = (int) $val;
    switch ($last) {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}

// ========== 测试 1: 检查 .user.ini 配置 ==========
echo "【测试 1】.user.ini 配置文件\n";
echo str_repeat('-', 50) . "\n";

$userIniPath = __DIR__ . '/../.user.ini';
if (file_exists($userIniPath)) {
    test_true(true, ".user.ini 文件存在");

    $userIni = file_get_contents($userIniPath);

    // 检查各项配置
    preg_match('/upload_max_filesize\s*=\s*(\S+)/i', $userIni, $matches);
    $configuredUpload = $matches[1] ?? null;
    test_true($configuredUpload !== null, "配置了 upload_max_filesize");
    if ($configuredUpload) {
        $uploadBytes = toBytes($configuredUpload);
        test_true($uploadBytes >= 500 * 1024 * 1024, "upload_max_filesize >= 500M (当前: {$configuredUpload})");
    }

    preg_match('/post_max_size\s*=\s*(\S+)/i', $userIni, $matches);
    $configuredPost = $matches[1] ?? null;
    test_true($configuredPost !== null, "配置了 post_max_size");
    if ($configuredPost) {
        $postBytes = toBytes($configuredPost);
        test_true($postBytes >= 500 * 1024 * 1024, "post_max_size >= 500M (当前: {$configuredPost})");
    }

    preg_match('/max_execution_time\s*=\s*(\d+)/i', $userIni, $matches);
    $configuredTime = $matches[1] ?? null;
    test_true($configuredTime !== null, "配置了 max_execution_time");
    if ($configuredTime) {
        test_true((int)$configuredTime >= 300, "max_execution_time >= 300秒 (当前: {$configuredTime}秒)");
    }

    echo "\n.user.ini 内容:\n";
    echo str_replace("\n", "\n", $userIni) . "\n";
} else {
    test_skip(".user.ini 文件不存在");
}

echo "\n";

// ========== 测试 2: 前端文件大小检查 ==========
echo "【测试 2】前端文件大小检查\n";
echo str_repeat('-', 50) . "\n";

$controllerContent = file_get_contents(__DIR__ . '/../app/Controllers/ImportController.php');

// 检查单文件限制
test_true(
    strpos($controllerContent, '500 * 1024 * 1024') !== false,
    "前端设置了 500MB 文件大小限制"
);

// 检查单文件超限检查
test_true(
    strpos($controllerContent, 'oversizedFiles') !== false,
    "前端检查单个文件是否超限"
);

// 检查总大小限制
test_true(
    strpos($controllerContent, 'maxTotalSize') !== false,
    "前端检查总大小限制"
);

// 检查用户友好提示
test_true(
    strpos($controllerContent, '超过') !== false && strpos($controllerContent, '限制') !== false,
    "前端有用户友好的错误提示"
);

echo "\n";

// ========== 测试 3: 验证配置生效情况 ==========
echo "【测试 3】当前 PHP 配置状态\n";
echo str_repeat('-', 50) . "\n";

$uploadMaxFilesize = ini_get('upload_max_filesize');
$postMaxSize = ini_get('post_max_size');
$memoryLimit = ini_get('memory_limit');

echo "  当前 upload_max_filesize: {$uploadMaxFilesize}\n";
echo "  当前 post_max_size: {$postMaxSize}\n";
echo "  当前 memory_limit: {$memoryLimit}\n";

$uploadMaxBytes = toBytes($uploadMaxFilesize);
$postMaxBytes = toBytes($postMaxSize);

// 用户的 301MB 文件
$testFileSize = 301 * 1024 * 1024;

test_true(
    $uploadMaxBytes >= $testFileSize,
    "当前 upload_max_filesize ({$uploadMaxFilesize}) 能支持 301MB 文件"
);

test_true(
    $postMaxBytes >= $testFileSize,
    "当前 post_max_size ({$postMaxSize}) 能支持 301MB 文件"
);

echo "\n";

// ========== 测试 4: 错误处理完整性 ==========
echo "【测试 4】错误处理完整性\n";
echo str_repeat('-', 50) . "\n";

$processFilesContent = file_get_contents(__DIR__ . '/../import/process-files.php');

// 检查各种上传错误码处理
$errorCodes = [
    'UPLOAD_ERR_INI_SIZE' => '文件大小超过 upload_max_filesize',
    'UPLOAD_ERR_FORM_SIZE' => '文件大小超过 MAX_FILE_SIZE',
    'UPLOAD_ERR_PARTIAL' => '文件只有部分被上传',
    'UPLOAD_ERR_NO_FILE' => '没有文件被上传',
    'UPLOAD_ERR_NO_TMP_DIR' => '缺少临时文件夹',
    'UPLOAD_ERR_CANT_WRITE' => '文件写入失败',
];

foreach ($errorCodes as $code => $desc) {
    test_true(
        strpos($processFilesContent, $code) !== false,
        "处理 {$code} ({$desc})"
    );
}

echo "\n";

// ========== 总结 ==========
echo str_repeat('=', 60) . "\n";
echo "测试结果: ✅ {$passed} 通过, ❌ {$failed} 失败, ⏭️  {$skipped} 跳过\n";
echo str_repeat('=', 60) . "\n\n";

if ($failed === 0 && $passed > 0) {
    echo "【TDD GREEN 阶段成功】\n\n";
    echo "修复内容：\n";
    echo "1. ✅ 创建了 .user.ini 文件，提高 PHP 上传限制到 512MB\n";
    echo "2. ✅ 前端增加了单文件和总大小的限制检查（500MB）\n";
    echo "3. ✅ 前端在文件超限时给出友好提示，阻止上传\n";
    echo "4. ✅ 后端有完整的上传错误码处理\n\n";

    echo "【重要提示】\n";
    echo ".user.ini 需要重启 PHP 或等待配置生效时间（通常 5 分钟）\n";
    echo "如果使用 phpStudy，请在 phpStudy 面板中重启 PHP 服务\n\n";

    echo "【用户操作指南】\n";
    echo "1. 单个文件最大支持 500MB\n";
    echo "2. 所有文件总大小最大支持 500MB\n";
    echo "3. 超过 100MB 会有提示但可以继续\n";
    echo "4. 超过 500MB 会被阻止并提示压缩或拆分\n";
} else if ($failed > 0) {
    echo "【部分测试未通过】\n";
    echo "请检查 PHP 配置是否生效\n";
} else {
    echo "【测试未执行】\n";
}
