<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();
$admin = current_admin() ?? [];
$ownOnly = mf_own_contract_only_enabled($pdo) && (($admin['role'] ?? 'normal') !== 'super');
$currentAdminId = (int) ($admin['id'] ?? 0);

$kw = trim((string) ($_GET['kw'] ?? ''));
$statusFilter = (string) ($_GET['status'] ?? '');
$typeFilter = (int) ($_GET['type_id'] ?? 0);
$paymentFilter = (string) ($_GET['payment_type'] ?? '');
$archived = (string) ($_GET['archived'] ?? '0') === '1';
require_permission($archived ? 'archived.export' : 'contracts.export');

$where = ['c.is_archived = ?'];
$params = [$archived ? 1 : 0];
if ($kw !== '') {
    $where[] = '(c.contract_no LIKE ? OR c.contract_name LIKE ? OR c.signer_party LIKE ? OR c.customer_name LIKE ?)';
    $like = '%' . $kw . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if (in_array($statusFilter, ['ongoing', 'completed', 'terminated', 'expiring'], true)) {
    $where[] = 'c.status = ?';
    $params[] = $statusFilter;
}
if ($typeFilter > 0) {
    $where[] = 'c.type_id = ?';
    $params[] = $typeFilter;
}
if (in_array($paymentFilter, ['receipt', 'payment'], true)) {
    $where[] = 'c.payment_type = ?';
    $params[] = $paymentFilter;
}
if ($ownOnly) {
    $where[] = 'c.created_by = ?';
    $params[] = $currentAdminId;
}

$st = $pdo->prepare(
    "SELECT c.*, t.name AS type_name,
            COALESCE((SELECT SUM(tx.amount) FROM contract_transactions tx WHERE tx.contract_id = c.id AND tx.tx_type = 'receipt'), 0) AS receipt_done,
            COALESCE((SELECT SUM(tx.amount) FROM contract_transactions tx WHERE tx.contract_id = c.id AND tx.tx_type = 'payment'), 0) AS payment_done
     FROM contracts c
     LEFT JOIN contract_types t ON t.id = c.type_id
     WHERE " . implode(' AND ', $where) . '
     ORDER BY c.id DESC'
);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (!$rows) {
    redirect('orders.php?err=' . rawurlencode('没有可导出的合同'));
}

$normalize = static function (string $name): string {
    $name = trim($name);
    $name = preg_replace('/[\\\\\/:\*\?"<>\|]+/u', '_', $name) ?? '';
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';
    $name = trim($name, " .\t\n\r\0\x0B");
    return $name !== '' ? $name : '合同导出';
};
$safe = static function (?string $v): string {
    $v = (string) ($v ?? '');
    return $v !== '' ? $v : '-';
};

$bundleName = '合同批量导出_' . date('Ymd_His');
$zipName = $bundleName . '.zip';
$tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'contracts_export_' . bin2hex(random_bytes(6)) . '.zip';

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    redirect('orders.php?err=' . rawurlencode('导出失败：无法创建压缩包'));
}

$fileSt = $pdo->prepare('SELECT origin_name, file_path FROM contract_files WHERE contract_id = ? ORDER BY id ASC');
$txSt = $pdo->prepare(
    "SELECT t.tx_type, t.amount, t.note, t.voucher_path, t.created_at, COALESCE(a.display_name, a.username, '-') AS registrar_name
     FROM contract_transactions t
     LEFT JOIN admins a ON a.id = t.created_by
     WHERE t.contract_id = ?
     ORDER BY t.id ASC"
);
$invoiceSt = null;
if (db_table_exists($pdo, 'contract_invoices')) {
    $invoiceSt = $pdo->prepare('SELECT invoice_type, amount, note, file_path, created_at FROM contract_invoices WHERE contract_id = ? ORDER BY id ASC');
}

$summary = [];
$summary[] = ['合同编号', '合同名称', '客户名称', '款项类型', '合同状态', '合同类型', '合同金额', '已收款金额', '已付款金额', '已开票金额', '签订日期', '生效日期', '截止日期'];

foreach ($rows as $row) {
    $folderName = $normalize((string) $row['contract_no'] . '+' . (string) $row['contract_name']);
    $base = $bundleName . '/' . $folderName;
    $zip->addEmptyDir($base);

    $csvRows = [];
    $csvRows[] = ['合同编号', '合同名称', '客户名称', '签约方', '签约人', '联系电话', '款项类型', '合同状态', '合同类型', '合同金额', '已收款金额', '已付款金额', '签订日期', '生效日期', '截止日期', '导出时间'];
    $csvRows[] = [
        $safe($row['contract_no']),
        $safe($row['contract_name']),
        $safe($row['customer_name']),
        $safe($row['signer_party']),
        $safe($row['signer_name']),
        $safe($row['phone']),
        ((string) ($row['payment_type'] ?? 'receipt')) === 'payment' ? '付款' : '收款',
        mf_contract_status_label((string) ($row['status'] ?? '')),
        $safe($row['type_name'] ?? ''),
        number_format((float) ($row['amount'] ?? 0), 2, '.', ''),
        number_format((float) ($row['receipt_done'] ?? 0), 2, '.', ''),
        number_format((float) ($row['payment_done'] ?? 0), 2, '.', ''),
        $safe($row['signed_date'] ?? ''),
        $safe($row['effective_date'] ?? ''),
        $safe($row['expiry_date'] ?? ''),
        date('Y-m-d H:i:s'),
    ];
    $csv = "\xEF\xBB\xBF";
    foreach ($csvRows as $line) {
        $escaped = array_map(static function ($v): string {
            $s = (string) $v;
            $s = str_replace('"', '""', $s);
            return '"' . $s . '"';
        }, $line);
        $csv .= implode(',', $escaped) . "\r\n";
    }
    $zip->addFromString($base . '/合同信息.csv', $csv);

    $txSt->execute([(int) $row['id']]);
    $txRows = $txSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $txCsvRows = [];
    $txCsvRows[] = ['合同编号', '合同名称', '款项类型', '登记金额', '登记人', '备注', '凭证文件', '登记时间'];
    foreach ($txRows as $tx) {
        $txTypeLabel = ((string) ($tx['tx_type'] ?? 'receipt')) === 'payment' ? '付款' : '收款';
        $voucherFile = basename((string) ($tx['voucher_path'] ?? ''));
        $txCsvRows[] = [
            $safe($row['contract_no']),
            $safe($row['contract_name']),
            $txTypeLabel,
            number_format((float) ($tx['amount'] ?? 0), 2, '.', ''),
            $safe($tx['registrar_name'] ?? ''),
            $safe($tx['note'] ?? ''),
            $voucherFile !== '' ? $voucherFile : '-',
            $safe($tx['created_at'] ?? ''),
        ];
    }
    $txCsv = "\xEF\xBB\xBF";
    foreach ($txCsvRows as $line) {
        $escaped = array_map(static function ($v): string {
            $s = (string) $v;
            $s = str_replace('"', '""', $s);
            return '"' . $s . '"';
        }, $line);
        $txCsv .= implode(',', $escaped) . "\r\n";
    }
    $zip->addFromString($base . '/收付款进度明细.csv', $txCsv);

    $zip->addEmptyDir($base . '/附件');

    $fileSt->execute([(int) $row['id']]);
    $files = $fileSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($files as $f) {
        $originName = $normalize((string) $f['origin_name']);
        $relPath = ltrim((string) $f['file_path'], '/');
        $fullPath = __DIR__ . '/' . $relPath;
        if ($originName === '' || !is_file($fullPath)) {
            continue;
        }
        $zip->addFile($fullPath, $base . '/附件/' . $originName);
    }

    $zip->addEmptyDir($base . '/收付款凭证');
    foreach ($txRows as $idx => $tx) {
        $relPath = ltrim((string) ($tx['voucher_path'] ?? ''), '/');
        if ($relPath === '') {
            continue;
        }
        $fullPath = __DIR__ . '/' . $relPath;
        if (!is_file($fullPath)) {
            continue;
        }
        $txType = ((string) ($tx['tx_type'] ?? 'receipt')) === 'payment' ? '付款' : '收款';
        $originName = $normalize(basename($relPath));
        $zip->addFile($fullPath, $base . '/收付款凭证/' . $txType . '_' . str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT) . '_' . $originName);
    }

    $invoiceRows = [];
    if ($invoiceSt instanceof PDOStatement) {
        $invoiceSt->execute([(int) $row['id']]);
        $invoiceRows = $invoiceSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $invoiceCsvRows = [];
    $invoiceCsvRows[] = ['合同编号', '合同名称', '发票类型', '开票金额', '备注', '附件文件', '开票时间'];
    $invoicedAmount = 0.0;
    foreach ($invoiceRows as $inv) {
        $invoiceTypeLabel = ((string) ($inv['invoice_type'] ?? 'receipt')) === 'payment' ? '付款发票' : '收款发票';
        $invoiceFile = basename((string) ($inv['file_path'] ?? ''));
        $amount = (float) ($inv['amount'] ?? 0);
        $invoicedAmount += $amount;
        $invoiceCsvRows[] = [
            $safe($row['contract_no']),
            $safe($row['contract_name']),
            $invoiceTypeLabel,
            number_format($amount, 2, '.', ''),
            $safe($inv['note'] ?? ''),
            $invoiceFile !== '' ? $invoiceFile : '-',
            $safe($inv['created_at'] ?? ''),
        ];
    }
    $invoiceCsv = "\xEF\xBB\xBF";
    foreach ($invoiceCsvRows as $line) {
        $escaped = array_map(static function ($v): string {
            $s = (string) $v;
            $s = str_replace('"', '""', $s);
            return '"' . $s . '"';
        }, $line);
        $invoiceCsv .= implode(',', $escaped) . "\r\n";
    }
    $zip->addFromString($base . '/发票明细.csv', $invoiceCsv);

    $zip->addEmptyDir($base . '/发票附件');
    foreach ($invoiceRows as $idx => $inv) {
        $relPath = ltrim((string) ($inv['file_path'] ?? ''), '/');
        if ($relPath === '') {
            continue;
        }
        $fullPath = __DIR__ . '/' . $relPath;
        if (!is_file($fullPath)) {
            continue;
        }
        $invoiceType = ((string) ($inv['invoice_type'] ?? 'receipt')) === 'payment' ? '付款' : '收款';
        $originName = $normalize(basename($relPath));
        $zip->addFile($fullPath, $base . '/发票附件/' . $invoiceType . '_' . str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT) . '_' . $originName);
    }

    $summary[] = [
        $safe($row['contract_no']),
        $safe($row['contract_name']),
        $safe($row['customer_name']),
        ((string) ($row['payment_type'] ?? 'receipt')) === 'payment' ? '付款' : '收款',
        mf_contract_status_label((string) ($row['status'] ?? '')),
        $safe($row['type_name'] ?? ''),
        number_format((float) ($row['amount'] ?? 0), 2, '.', ''),
        number_format((float) ($row['receipt_done'] ?? 0), 2, '.', ''),
        number_format((float) ($row['payment_done'] ?? 0), 2, '.', ''),
        number_format($invoicedAmount, 2, '.', ''),
        $safe($row['signed_date'] ?? ''),
        $safe($row['effective_date'] ?? ''),
        $safe($row['expiry_date'] ?? ''),
    ];
}

$summaryCsv = "\xEF\xBB\xBF";
foreach ($summary as $line) {
    $escaped = array_map(static function ($v): string {
        $s = (string) $v;
        $s = str_replace('"', '""', $s);
        return '"' . $s . '"';
    }, $line);
    $summaryCsv .= implode(',', $escaped) . "\r\n";
}
$zip->addFromString($bundleName . '/合同汇总.csv', $summaryCsv);
$zip->close();

if (!is_file($tmpZip)) {
    redirect('orders.php?err=' . rawurlencode('导出失败：压缩包不存在'));
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . rawurlencode($zipName) . '"; filename*=UTF-8\'\'' . rawurlencode($zipName));
header('Content-Length: ' . (string) filesize($tmpZip));
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
readfile($tmpZip);
@unlink($tmpZip);
exit;
