<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data)) {
    json_response(['ok' => false, 'error' => '无效数据'], 400);
}

if (!csrf_verify($data['csrf'] ?? null)) {
    json_response(['ok' => false, 'error' => '会话已过期，请刷新页面'], 403);
}

$admin = current_admin();
$id = (int) ($admin['id'] ?? 0);
if ($id <= 0) {
    json_response(['ok' => false, 'error' => '未登录'], 401);
}

$displayName = trim((string) ($data['display_name'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$pass = (string) ($data['password'] ?? '');
$pass2 = (string) ($data['password2'] ?? '');

if ($displayName === '') {
    json_response(['ok' => false, 'error' => '请填写显示名称'], 422);
}
if ($pass !== '' && strlen($pass) < 6) {
    json_response(['ok' => false, 'error' => '新密码至少 6 位'], 422);
}
if ($pass !== '' && $pass !== $pass2) {
    json_response(['ok' => false, 'error' => '两次密码不一致'], 422);
}

$pdo = db();
try {
    if ($pass === '') {
        $st = $pdo->prepare('UPDATE admins SET display_name = ?, email = ? WHERE id = ?');
        $st->execute([$displayName, $email === '' ? null : $email, $id]);
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $st = $pdo->prepare('UPDATE admins SET display_name = ?, email = ?, password_hash = ? WHERE id = ?');
        $st->execute([$displayName, $email === '' ? null : $email, $hash, $id]);
    }
    $_SESSION['admin']['display_name'] = $displayName;
    $_SESSION['admin']['email'] = $email;
    json_response([
        'ok' => true,
        'display_name' => $displayName,
        'email' => $email,
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => '保存失败'], 500);
}
