<?php
/**
 * 文件下载接口
 * 用于安全地访问uploads目录下的文件
 */
require_once __DIR__ . '/../includes/bootstrap.php';

// 获取文件ID
$fileId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($fileId <= 0) {
    http_response_code(400);
    die('无效的文件ID');
}

// 从数据库获取文件信息
$stmt = db()->prepare("SELECT * FROM contract_files WHERE id = ?");
$stmt->execute([$fileId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$file) {
    http_response_code(404);
    die('文件不存在');
}

// 获取文件路径
$filePath = $file['file_path'];

// 提取相对路径并转换为绝对路径
if (preg_match('#uploads/(attachments/.+)$#', $filePath, $matches)) {
    $relativePath = 'uploads/' . $matches[1];
    // 使用数据库中的绝对路径
    $absolutePath = $filePath;
} else {
    $absolutePath = $filePath;
}

// 检查文件是否存在
if (!file_exists($absolutePath)) {
    http_response_code(404);
    die('文件不存在: ' . basename($absolutePath));
}

// 获取文件信息
$fileSize = filesize($absolutePath);
$fileName = $file['origin_name'] ?? basename($absolutePath);
$mimeType = $file['mime_type'] ?? 'application/octet-stream';

// 设置下载头
header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . basename($fileName) . '"');
header('Content-Transfer-Encoding: binary');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . $fileSize);

// 输出文件内容
readfile($absolutePath);
exit;