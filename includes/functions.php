<?php

declare(strict_types=1);

function e(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_config(): array
{
    static $c = null;
    if ($c === null) {
        $c = require dirname(__DIR__) . '/config/config.php';
    }
    return $c;
}

function base_path(): string
{
    return rtrim((string) (app_config()['app']['base_path'] ?? '/mf'), '/');
}

function url(string $path): string
{
    $p = ltrim($path, '/');
    $b = base_path();
    if ($b === '') {
        return '/' . $p;
    }
    return $b . '/' . $p;
}

function asset_url(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path): void
{
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        header('Location: ' . $path);
        exit;
    }
    header('Location: ' . url($path));
    exit;
}

function json_response(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_verify(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function generate_order_no(): string
{
    return 'SP' . date('ymdHis') . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

/** 会员钱包类单据号：CZ 充值、KK 扣款、ZZ 转账、TX 提现 */
function generate_wallet_order_no(string $prefix): string
{
    $p = preg_replace('/[^A-Za-z0-9]/', '', $prefix) ?: 'WX';
    return strtoupper(substr($p, 0, 2)) . date('ymdHis') . str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function db_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $st = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$table]);
    $exists = (int) $st->fetchColumn() > 0;
    if ($exists) {
        $cache[$table] = true;
    }
    return $exists;
}

function db_column_exists(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (!array_key_exists($key, $cache)) {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $st->execute([$table, $column]);
        $cache[$key] = (int) $st->fetchColumn() > 0;
    }
    return $cache[$key];
}

/**
 * 钱包「充值次数」流水金额合计（次数价值），用于营业额统计。
 *
 * @param string $andWhereSql 不含 WHERE 的 AND 片段，如 DATE(created_at) = ?
 */
function mf_sum_recharge_times_value(PDO $pdo, string $andWhereSql, array $params): float
{
    if (!db_table_exists($pdo, 'member_wallet_records')) {
        return 0.0;
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0) FROM member_wallet_records WHERE kind = ? AND (' . $andWhereSql . ')'
    );
    $st->execute(array_merge(['recharge_times'], $params));

    return (float) $st->fetchColumn();
}

/** 按日汇总充次次数价值（与 dashboard 趋势图日期对齐用） */
function mf_recharge_times_value_by_day(PDO $pdo, string $dayFrom, string $dayTo): array
{
    if (!db_table_exists($pdo, 'member_wallet_records')) {
        return [];
    }
    $st = $pdo->prepare(
        "SELECT DATE(created_at) AS d, COALESCE(SUM(amount),0) AS s
         FROM member_wallet_records
         WHERE kind = 'recharge_times' AND DATE(created_at) >= ? AND DATE(created_at) <= ?
         GROUP BY DATE(created_at)"
    );
    $st->execute([$dayFrom, $dayTo]);
    $out = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $out[(string) $r['d']] = (float) $r['s'];
    }

    return $out;
}

/**
 * 永久删除会员：先删其消费订单，再清除他人钱包流水中的对方会员引用，最后删会员（钱包 CASCADE）。
 *
 * @return bool 会员存在且已删除
 */
function mf_purge_member_and_orders(PDO $pdo, int $memberId): bool
{
    $st = $pdo->prepare('SELECT id FROM members WHERE id = ? LIMIT 1');
    $st->execute([$memberId]);
    if (!$st->fetch()) {
        return false;
    }
    $st = $pdo->prepare('DELETE FROM orders WHERE member_id = ?');
    $st->execute([$memberId]);
    if (db_table_exists($pdo, 'member_wallet_records')
        && db_column_exists($pdo, 'member_wallet_records', 'counterparty_member_id')) {
        $st = $pdo->prepare('UPDATE member_wallet_records SET counterparty_member_id = NULL WHERE counterparty_member_id = ?');
        $st->execute([$memberId]);
    }
    $st = $pdo->prepare('DELETE FROM members WHERE id = ?');
    $st->execute([$memberId]);

    return $st->rowCount() === 1;
}

function generate_card_number(PDO $pdo): string
{
    for ($i = 0; $i < 50; $i++) {
        $n = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $st = $pdo->prepare('SELECT COUNT(*) FROM members WHERE card_number = ?');
        $st->execute([$n]);
        if ((int) $st->fetchColumn() === 0) {
            return $n;
        }
    }
    throw new RuntimeException('无法生成唯一卡号');
}

function mf_ensure_contract_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $safeExec = static function (string $sql) use ($pdo): void {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate column') !== false
                || stripos($msg, 'Duplicate key') !== false
                || stripos($msg, 'already exists') !== false
                || stripos($msg, 'check that column/key exists') !== false) {
                return;
            }
            throw $e;
        }
    };

    $safeExec("ALTER TABLE admins ADD COLUMN display_name VARCHAR(50) NULL");
    $safeExec("ALTER TABLE admins ADD COLUMN email VARCHAR(120) NULL");
    $safeExec("ALTER TABLE admins ADD COLUMN phone VARCHAR(30) NULL");
    $safeExec("ALTER TABLE admins ADD COLUMN role ENUM('super','normal') NOT NULL DEFAULT 'normal'");
    $safeExec("ALTER TABLE admins ADD COLUMN status ENUM('normal','disabled') NOT NULL DEFAULT 'normal'");
    $safeExec("ALTER TABLE admins ADD COLUMN permissions TEXT NULL");
    $safeExec("ALTER TABLE admins MODIFY COLUMN role ENUM('super','normal','sales') NOT NULL DEFAULT 'normal'");

    $safeExec(
        "CREATE TABLE IF NOT EXISTS contract_types (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            remark VARCHAR(255) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL,
            UNIQUE KEY uk_name (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $safeExec(
        "CREATE TABLE IF NOT EXISTS contracts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            contract_no VARCHAR(64) NOT NULL,
            contract_name VARCHAR(180) NOT NULL,
            customer_name VARCHAR(180) NOT NULL DEFAULT '',
            payment_type ENUM('receipt','payment') NOT NULL DEFAULT 'receipt',
            signer_party VARCHAR(180) NOT NULL DEFAULT '',
            signer_name VARCHAR(80) NOT NULL DEFAULT '',
            phone VARCHAR(40) NOT NULL DEFAULT '',
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            signed_date DATE NULL,
            effective_date DATE NULL,
            expiry_date DATE NULL,
            status ENUM('ongoing','completed','terminated','expiring','pending_review') NOT NULL DEFAULT 'ongoing',
            type_id INT UNSIGNED NULL,
            created_by INT UNSIGNED NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL,
            UNIQUE KEY uk_contract_no (contract_no),
            KEY idx_contract_name (contract_name),
            KEY idx_customer_name (customer_name),
            KEY idx_status (status),
            KEY idx_type (type_id),
            KEY idx_expiry_date (expiry_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $safeExec("ALTER TABLE contracts ADD COLUMN payment_type ENUM('receipt','payment') NOT NULL DEFAULT 'receipt'");
    $safeExec("ALTER TABLE contracts ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0");
    $safeExec("ALTER TABLE contracts ADD COLUMN archived_at DATETIME NULL DEFAULT NULL");
    $safeExec("ALTER TABLE contracts ADD COLUMN import_confidence DECIMAL(5,2) DEFAULT NULL");
    $safeExec("ALTER TABLE contracts ADD COLUMN import_fields TEXT DEFAULT NULL");
    $safeExec("ALTER TABLE contracts ADD COLUMN ocr_raw_text LONGTEXT DEFAULT NULL");
    $safeExec("ALTER TABLE contracts ADD COLUMN import_job_id INT UNSIGNED DEFAULT NULL");
    $safeExec("ALTER TABLE contracts MODIFY COLUMN status ENUM('ongoing','completed','terminated','expiring','pending_review') NOT NULL DEFAULT 'ongoing'");

    $safeExec(
        "CREATE TABLE IF NOT EXISTS contract_files (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            contract_id INT UNSIGNED NOT NULL,
            origin_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            mime_type VARCHAR(120) NOT NULL DEFAULT '',
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_contract (contract_id),
            CONSTRAINT fk_contract_files_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $safeExec(
        "CREATE TABLE IF NOT EXISTS contract_transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            contract_id INT UNSIGNED NOT NULL,
            tx_type ENUM('receipt','payment') NOT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            note VARCHAR(255) NOT NULL DEFAULT '',
            voucher_path VARCHAR(255) DEFAULT NULL,
            created_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_contract_tx (contract_id, tx_type, created_at),
            CONSTRAINT fk_contract_tx_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $safeExec(
        "CREATE TABLE IF NOT EXISTS contract_invoices (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            contract_id INT UNSIGNED NOT NULL,
            invoice_type ENUM('receipt','payment') NOT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            note VARCHAR(255) NOT NULL DEFAULT '',
            file_path VARCHAR(255) DEFAULT NULL,
            created_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_contract_invoice (contract_id, invoice_type, created_at),
            CONSTRAINT fk_contract_invoice_contract FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $safeExec(
        "CREATE TABLE IF NOT EXISTS contract_settings (
            id TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
            remind_days INT UNSIGNED NOT NULL DEFAULT 15,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $safeExec("INSERT IGNORE INTO contract_settings (id, remind_days) VALUES (1, 15)");

    $safeExec(
        "CREATE TABLE IF NOT EXISTS import_jobs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            folder_name VARCHAR(255) NOT NULL,
            status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
            total_files INT UNSIGNED DEFAULT 0,
            success_count INT UNSIGNED DEFAULT 0,
            pending_count INT UNSIGNED DEFAULT 0,
            failed_count INT UNSIGNED DEFAULT 0,
            created_by INT UNSIGNED DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at DATETIME DEFAULT NULL,
            INDEX idx_status (status),
            INDEX idx_created_by (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $safeExec(
        "CREATE TABLE IF NOT EXISTS import_files (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            job_id INT UNSIGNED NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_type VARCHAR(50) NOT NULL,
            status ENUM('pending','success','pending_review','failed') DEFAULT 'pending',
            contract_id INT UNSIGNED DEFAULT NULL,
            confidence DECIMAL(5,2) DEFAULT NULL,
            error_message TEXT DEFAULT NULL,
            ocr_text LONGTEXT DEFAULT NULL,
            raw_api_response TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_job_id (job_id),
            INDEX idx_status (status),
            FOREIGN KEY (job_id) REFERENCES import_jobs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    if (db_column_exists($pdo, 'admins', 'name')) {
        $safeExec("UPDATE admins SET display_name = name WHERE (display_name IS NULL OR display_name = '')");
    } else {
        $safeExec("UPDATE admins SET display_name = username WHERE (display_name IS NULL OR display_name = '')");
    }
    $safeExec("UPDATE admins SET status = 'normal' WHERE status IS NULL");
    $safeExec("UPDATE admins SET role = 'super' WHERE id = 1");
}

function mf_contract_status_label(string $status): string
{
    $map = [
        'ongoing' => '进行中',
        'completed' => '已完成',
        'terminated' => '已终止',
        'expiring' => '即将到期',
        'expired' => '已过期',
        'pending_review' => '待审核',
    ];
    return $map[$status] ?? $status;
}

function mf_contract_status_badge(string $status): string
{
    $label = mf_contract_status_label($status);
    $bg = '#f4f4f5';
    $color = '#606266';
    if ($status === 'ongoing') {
        $bg = '#ecf5ff';
        $color = '#409eff';
    } elseif ($status === 'completed') {
        $bg = '#f0f9eb';
        $color = '#67c23a';
    } elseif ($status === 'terminated') {
        $bg = '#fef0f0';
        $color = '#f56c6c';
    } elseif ($status === 'expiring') {
        $bg = '#fdf6ec';
        $color = '#e6a23c';
    } elseif ($status === 'expired') {
        $bg = '#fef0f0';
        $color = '#f56c6c';
    } elseif ($status === 'pending_review') {
        $bg = '#fdf6ec';
        $color = '#e6a23c';
    }
    return '<span style="display:inline-block;padding:2px 8px;background:' . $bg . ';color:' . $color . ';border:1px solid ' . $bg . ';">' . e($label) . '</span>';
}

function mf_payment_type_badge(string $paymentType): string
{
    $isPayment = $paymentType === 'payment';
    $label = $isPayment ? '付款' : '收款';
    $bg = $isPayment ? '#fef0f0' : '#f0f9eb';
    $color = $isPayment ? '#f56c6c' : '#67c23a';
    return '<span style="display:inline-block;padding:2px 8px;background:' . $bg . ';color:' . $color . ';border:1px solid ' . $bg . ';">' . $label . '</span>';
}

function mf_contract_status_by_expiry(?string $expiryDate, int $remindDays): string
{
    if ($expiryDate === null || $expiryDate === '') {
        return 'ongoing';
    }
    $today = strtotime(date('Y-m-d'));
    $expiry = strtotime($expiryDate);
    if ($expiry === false) {
        return 'ongoing';
    }
    $diff = (int) floor(($expiry - $today) / 86400);
    if ($diff <= $remindDays) {
        return 'expiring';
    }
    return 'ongoing';
}

function mf_contract_done_amount(PDO $pdo, int $contractId, string $paymentType): float
{
    if ($contractId <= 0 || !in_array($paymentType, ['receipt', 'payment'], true)) {
        return 0.0;
    }
    $st = $pdo->prepare(
        'SELECT COALESCE(SUM(amount),0) FROM contract_transactions WHERE contract_id = ? AND tx_type = ?'
    );
    $st->execute([$contractId, $paymentType]);
    return (float) $st->fetchColumn();
}

function mf_permission_catalog(): array
{
    return [
        'dashboard.view' => '首页-查看',
        'search.view' => '快速搜索-查看',
        'contracts.view' => '合同列表-查看',
        'contracts.create' => '合同列表-新增',
        'contracts.edit' => '合同列表-编辑',
        'contracts.delete' => '合同列表-删除',
        'contracts.update_status' => '合同列表-更新状态',
        'contracts.archive' => '合同列表-归档',
        'contracts.export' => '合同列表-导出',
        'receipt.progress.view' => '收款业务-查看',
        'receipt.progress.entry' => '收款业务-登记收款',
        'receipt.progress.undo' => '收款业务-撤销上一次',
        'receipt.progress.export' => '收款业务-导出',
        'receipt.records.view' => '收款记录-查看',
        'receipt.records.export' => '收款记录-导出',
        'receipt.invoices.view' => '收款开票-查看',
        'receipt.invoices.entry' => '收款开票-上传发票',
        'payment.progress.view' => '付款业务-查看',
        'payment.progress.entry' => '付款业务-登记付款',
        'payment.progress.undo' => '付款业务-撤销上一次',
        'payment.progress.export' => '付款业务-导出',
        'payment.records.view' => '付款记录-查看',
        'payment.records.export' => '付款记录-导出',
        'payment.invoices.view' => '付款开票-查看',
        'payment.invoices.entry' => '付款开票-上传发票',
        'types.view' => '类型管理-查看',
        'types.edit' => '类型管理-增删改',
        'remind.view' => '到期提醒-查看',
        'remind.edit' => '到期提醒-修改提醒天数',
        'archived.view' => '归档合同-查看',
        'archived.export' => '归档合同-导出',
        'app_settings.view' => '系统设置-查看',
        'app_settings.edit' => '系统设置-编辑',
        'admins.view' => '管理员设置-查看（仅超级管理员）',
        'admins.edit' => '管理员设置-增删改（仅超级管理员）',
        'users.view' => '业务员管理-查看',
        'users.edit' => '业务员管理-增删改',
        'import.view' => '批量导入-查看',
        'import.create' => '批量导入-执行导入',
        'import.review' => '批量导入-审核',
        'import.review.edit' => '批量导入-审核编辑',
    ];
}

function mf_permission_templates(): array
{
    return [
        'viewer' => [
            'label' => '只读查看',
            'permissions' => [
                'dashboard.view','search.view','contracts.view','receipt.progress.view','receipt.records.view',
                'receipt.invoices.view','payment.progress.view','payment.records.view','payment.invoices.view',
                'types.view','remind.view','archived.view'
            ],
        ],
        'contract_manager' => [
            'label' => '合同专员',
            'permissions' => [
                'dashboard.view','search.view','contracts.view','contracts.create','contracts.edit','contracts.update_status','contracts.archive','contracts.export',
                'types.view','types.edit','archived.view','archived.export'
            ],
        ],
        'finance_manager' => [
            'label' => '财务专员',
            'permissions' => [
                'dashboard.view','search.view','contracts.view','contracts.export',
                'receipt.progress.view','receipt.progress.entry','receipt.progress.undo','receipt.progress.export',
                'receipt.records.view','receipt.records.export',
                'receipt.invoices.view','receipt.invoices.entry',
                'payment.progress.view','payment.progress.entry','payment.progress.undo','payment.progress.export',
                'payment.records.view','payment.records.export',
                'payment.invoices.view','payment.invoices.entry',
                'archived.view','archived.export'
            ],
        ],
    ];
}

function mf_permission_groups(): array
{
    return [
        'dashboard' => ['label' => '首页', 'permissions' => ['dashboard.view']],
        'search' => ['label' => '快速搜索', 'permissions' => ['search.view']],
        'contracts' => ['label' => '合同业务（含收付款合同列表/编辑/归档/导出）', 'permissions' => [
            'contracts.view', 'contracts.create', 'contracts.edit', 'contracts.delete',
            'contracts.update_status', 'contracts.archive', 'contracts.export'
        ]],
        'receipt' => ['label' => '收款业务（进度/登记/撤销/记录/导出）', 'permissions' => [
            'receipt.progress.view', 'receipt.progress.entry', 'receipt.progress.undo',
            'receipt.progress.export', 'receipt.records.view', 'receipt.records.export',
            'receipt.invoices.view', 'receipt.invoices.entry'
        ]],
        'payment' => ['label' => '付款业务（进度/登记/撤销/记录/导出）', 'permissions' => [
            'payment.progress.view', 'payment.progress.entry', 'payment.progress.undo',
            'payment.progress.export', 'payment.records.view', 'payment.records.export',
            'payment.invoices.view', 'payment.invoices.entry'
        ]],
        'types' => ['label' => '类型管理', 'permissions' => ['types.view', 'types.edit']],
        'remind' => ['label' => '到期提醒', 'permissions' => ['remind.view', 'remind.edit']],
        'archived' => ['label' => '归档合同', 'permissions' => ['archived.view', 'archived.export']],
        'app_settings' => ['label' => '系统设置', 'permissions' => ['app_settings.view', 'app_settings.edit']],
        'salesmen' => ['label' => '业务员管理', 'permissions' => ['users.view', 'users.edit']],
        'import' => ['label' => '批量导入', 'permissions' => ['import.view', 'import.create', 'import.review', 'import.review.edit']],
    ];
}

function mf_permissions_from_groups(array $groups): array
{
    $map = mf_permission_groups();
    $out = [];
    foreach ($groups as $g) {
        $key = (string) $g;
        if (!isset($map[$key]) || !is_array($map[$key]['permissions'] ?? null)) {
            continue;
        }
        foreach ($map[$key]['permissions'] as $p) {
            $p = (string) $p;
            if ($p !== '') {
                $out[$p] = true;
            }
        }
    }
    $perms = array_keys($out);
    sort($perms);
    return $perms;
}

function mf_own_contract_only_enabled(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        if (!db_table_exists($pdo, 'app_settings') || !db_column_exists($pdo, 'app_settings', 'own_contract_only')) {
            return $cache = false;
        }
        $v = (int) ($pdo->query('SELECT own_contract_only FROM app_settings WHERE id = 1 LIMIT 1')->fetchColumn() ?: 0);
        return $cache = ($v === 1);
    } catch (Throwable $e) {
        return $cache = false;
    }
}

function baidu_ocr_config(): array
{
    static $c = null;
    if ($c === null) {
        $configFile = dirname(__DIR__) . '/config/baidu_ocr.php';
        if (file_exists($configFile)) {
            $c = require $configFile;
        } else {
            $c = app_config()['baidu_ocr'] ?? [];
        }
    }
    return $c;
}

function deepseek_config(): array
{
    return app_config()['deepseek'] ?? [];
}

function import_config(): array
{
    return app_config()['import'] ?? ['high_confidence' => 85, 'low_confidence' => 60];
}
