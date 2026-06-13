<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$lockFile = $root . '/data/installed.lock';
$script = basename($_SERVER['SCRIPT_NAME'] ?? '');

if (!is_file($lockFile) && $script !== 'install.php') {
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (preg_match('#/api$#', $base)) {
        $base = dirname($base);
        $base = $base === '/' ? '' : $base;
    }
    if ($base === '' || $base === '.') {
        $loc = '/install.php';
    } else {
        $loc = $base . '/install.php';
    }
    header('Location: ' . $loc);
    exit;
}

$configPath = $root . '/config/config.php';
if (!is_file($configPath)) {
    header('Content-Type: text/html; charset=utf-8');
    echo '缺少配置文件，请运行 <a href="install.php">install.php</a>。';
    exit;
}

$cfg = require $configPath;
date_default_timezone_set($cfg['app']['timezone'] ?? 'Asia/Shanghai');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once $root . '/config/database.php';
require_once __DIR__ . '/site_branding.php';

// Simple autoloader for app/Controllers, app/Services, and app/DTO
spl_autoload_register(function (string $class) {
    $prefixes = [
        'App\\Controllers\\' => '/app/Controllers/',
        'App\\Services\\' => '/app/Services/',
        'App\\DTO\\' => '/app/DTO/',
    ];
    foreach ($prefixes as $prefix => $path) {
        if (strpos($class, $prefix) === 0) {
            $relative = substr($class, strlen($prefix));
            $file = dirname(__DIR__) . $path . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
    }
});

mf_ensure_contract_schema(db());