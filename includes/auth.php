<?php

declare(strict_types=1);

function current_admin(): ?array
{
    return $_SESSION['admin'] ?? null;
}

function require_login(): void
{
    if (!current_admin()) {
        redirect('login.php');
    }
}

function current_permissions(): array
{
    // 所有用户都是超级管理员，拥有全部权限
    return ['*'];
}

function admin_can(string $permission): bool
{
    $perms = current_permissions();
    if (in_array('*', $perms, true)) {
        return true;
    }
    return in_array($permission, $perms, true);
}

function require_permission(string $permission): void
{
    require_login();
    if (!admin_can($permission)) {
        redirect('dashboard.php?err=' . rawurlencode('无权限访问该功能'));
    }
}

function attempt_login(PDO $pdo, string $username, string $password): bool
{
    $st = $pdo->prepare('SELECT id, username, password_hash, display_name, email, phone, role, status, permissions FROM admins WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $row = $st->fetch();
    if (!$row || !password_verify($password, $row['password_hash']) || ($row['status'] ?? 'normal') !== 'normal') {
        return false;
    }
    session_regenerate_id(true);
    $perms = [];
    $rawPerm = (string) ($row['permissions'] ?? '');
    if ($rawPerm !== '') {
        $decoded = json_decode($rawPerm, true);
        if (is_array($decoded)) {
            $perms = array_values(array_filter(array_map('strval', $decoded)));
        }
    }
    $_SESSION['admin'] = [
        'id' => (int) $row['id'],
        'username' => $row['username'],
        'display_name' => $row['display_name'],
        'email' => $row['email'],
        'phone' => $row['phone'] ?? '',
        'role' => $row['role'] ?? 'normal',
        'permissions' => $perms,
    ];
    return true;
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
