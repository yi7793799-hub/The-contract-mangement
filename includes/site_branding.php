<?php

declare(strict_types=1);

/**
 * 确保 app_settings 表存在（首次访问自动创建，与 upgrade.php 中语句一致）
 */
function mf_ensure_app_settings_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS app_settings (
          id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
          site_name VARCHAR(200) NOT NULL DEFAULT \'\',
          logo_path VARCHAR(255) NULL,
          own_contract_only TINYINT(1) NOT NULL DEFAULT 0,
          updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $hasOwnContractOnly = db_column_exists($pdo, 'app_settings', 'own_contract_only');
    if (!$hasOwnContractOnly) {
        try {
            $pdo->exec('ALTER TABLE app_settings ADD COLUMN own_contract_only TINYINT(1) NOT NULL DEFAULT 0');
        } catch (Throwable $e) {
        }
        $hasOwnContractOnly = db_column_exists($pdo, 'app_settings', 'own_contract_only');
    }
    if ($hasOwnContractOnly) {
        $pdo->exec("INSERT IGNORE INTO app_settings (id, site_name, logo_path, own_contract_only) VALUES (1, '', NULL, 0)");
    } else {
        $pdo->exec("INSERT IGNORE INTO app_settings (id, site_name, logo_path) VALUES (1, '', NULL)");
    }
}

/**
 * @return array{name: string, logo_url: ?string, logo_path: ?string}
 */
function mf_site_branding(?PDO $pdo = null): array
{
    $cfg = app_config();
    $defaultName = (string) ($cfg['app']['name'] ?? '云云合同管理系统');
    try {
        if ($pdo === null) {
            $pdo = db();
        }
        mf_ensure_app_settings_table($pdo);
        if (!db_table_exists($pdo, 'app_settings')) {
            return ['name' => $defaultName, 'logo_url' => null, 'logo_path' => null];
        }
        $st = $pdo->query('SELECT site_name, logo_path FROM app_settings WHERE id = 1 LIMIT 1');
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if (!$row) {
            return ['name' => $defaultName, 'logo_url' => null, 'logo_path' => null];
        }
        $name = trim((string) ($row['site_name'] ?? ''));
        if ($name === '') {
            $name = $defaultName;
        }
        $logoPath = $row['logo_path'] ?? null;
        $logoPath = is_string($logoPath) && $logoPath !== '' ? $logoPath : null;
        $logoUrl = $logoPath !== null ? url($logoPath) : null;

        return ['name' => $name, 'logo_url' => $logoUrl, 'logo_path' => $logoPath];
    } catch (Throwable $e) {
        return ['name' => $defaultName, 'logo_url' => null, 'logo_path' => null];
    }
}
