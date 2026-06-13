<?php
/**
 * 合同识别 API 端点
 *
 * POST /api/contract-recognize.php
 * Content-Type: multipart/form-data
 *
 * 参数:
 * - file: 合同文档文件（必填，PDF/JPG/PNG/DOCX）
 *
 * 响应:
 * - success: true/false
 * - data: RecognitionResult 数据
 * - error: 错误信息（失败时）
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

// 设置响应头
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// 检查登录状态
$admin = current_admin();
if (!$admin) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'UNAUTHORIZED',
            'message' => '请先登录'
        ]
    ]);
    exit;
}

// 只接受 POST 请求
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'METHOD_NOT_ALLOWED',
            'message' => '只支持 POST 请求'
        ]
    ]);
    exit;
}

// 验证文件上传
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $errorCode = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    $errorMessage = getUploadErrorMessage($errorCode);

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'FILE_UPLOAD_ERROR',
            'message' => $errorMessage
        ]
    ]);
    exit;
}

$file = $_FILES['file'];
$filePath = $file['tmp_name'];
$fileName = $file['name'];
$fileSize = $file['size'];

// 验证文件大小 (最大 50MB)
$maxSize = 50 * 1024 * 1024; // 50MB in bytes
if ($fileSize > $maxSize) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'FILE_TOO_LARGE',
            'message' => '文件大小超过限制（最大 50MB）',
            'details' => [
                'max_size' => '50MB',
                'actual_size' => round($fileSize / 1024 / 1024, 2) . 'MB'
            ]
        ]
    ]);
    exit;
}

// 验证文件格式
$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
$supportedFormats = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx', 'doc'];

if (!in_array($extension, $supportedFormats, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'UNSUPPORTED_FORMAT',
            'message' => '不支持的文件格式: ' . $extension,
            'details' => [
                'supported_formats' => implode(', ', $supportedFormats)
            ]
        ]
    ]);
    exit;
}

// 验证文件内容（MIME 类型检查）
$allowedMimeTypes = [
    'application/pdf',
    'image/jpeg',
    'image/jpg',
    'image/png',
    'image/webp',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/msword'
];

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($filePath);

if (!in_array($mimeType, $allowedMimeTypes, true)) {
    // 允许一些常见的变体 MIME 类型
    $allowedVariants = [
        'application/octet-stream', // 有时 DOCX 会返回这个
        'image/jpg', // 某些系统的 JPG MIME 类型
    ];

    // 对于 docx 和 doc，如果扩展名匹配则允许
    if (in_array($extension, ['docx', 'doc'], true) && in_array($mimeType, $allowedVariants, true)) {
        // 允许通过
    } elseif (!in_array($mimeType, $allowedMimeTypes, true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => 'INVALID_MIME_TYPE',
                'message' => '文件类型不正确',
                'details' => [
                    'detected_type' => $mimeType
                ]
            ]
        ]);
        exit;
    }
}

try {
    // 调用合同识别服务
    $ocrService = new \App\Services\ContractOcrService();
    $result = $ocrService->recognize($filePath);

    if (!$result->success) {
        // 识别失败
        $statusCode = 422;
        $errorCode = 'RECOGNITION_FAILED';

        // 根据错误信息判断具体错误类型
        if (strpos($result->errorMessage ?? '', '置信度') !== false) {
            $errorCode = 'CONFIDENCE_TOO_LOW';
        } elseif (strpos($result->errorMessage ?? '', '文件') !== false) {
            $statusCode = 400;
            $errorCode = 'FILE_ERROR';
        }

        http_response_code($statusCode);
        echo json_encode([
            'success' => false,
            'error' => [
                'code' => $errorCode,
                'message' => $result->errorMessage ?? '识别失败',
                'details' => [
                    'retry_count' => $result->retryCount,
                    'preprocessed' => $result->preprocessed,
                    'final_confidence' => $result->overallConfidence
                ]
            ]
        ]);
        exit;
    }

    // 识别成功
    echo json_encode([
        'success' => true,
        'data' => [
            'full_text' => $result->fullText,
            'fields' => $result->structuredFields,
            'confidence' => [
                'overall' => $result->overallConfidence,
                'fields' => $result->fieldConfidences
            ],
            'retry_count' => $result->retryCount,
            'preprocessed' => $result->preprocessed
        ]
    ]);

} catch (\Exception $e) {
    // 服务异常
    error_log('ContractOcrService error: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'SERVICE_ERROR',
            'message' => '识别服务异常，请稍后重试'
        ]
    ]);
}

/**
 * 获取文件上传错误消息
 *
 * @param int $errorCode PHP 上传错误码
 * @return string 错误消息
 */
function getUploadErrorMessage(int $errorCode): string
{
    $messages = [
        UPLOAD_ERR_INI_SIZE => '文件大小超过服务器限制',
        UPLOAD_ERR_FORM_SIZE => '文件大小超过表单限制',
        UPLOAD_ERR_PARTIAL => '文件只有部分被上传',
        UPLOAD_ERR_NO_FILE => '请选择要上传的文件',
        UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录不存在',
        UPLOAD_ERR_CANT_WRITE => '文件写入失败',
        UPLOAD_ERR_EXTENSION => '文件上传被扩展阻止',
    ];

    return $messages[$errorCode] ?? '未知上传错误';
}
