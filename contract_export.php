<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();
$admin = current_admin() ?? [];
$ownOnly = mf_own_contract_only_enabled($pdo) && (($admin['role'] ?? 'normal') !== 'super');
$currentAdminId = (int) ($admin['id'] ?? 0);
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('orders.php?err=' . rawurlencode('导出参数无效'));
}

$st = $pdo->prepare(
    "SELECT c.*, t.name AS type_name,
            COALESCE((SELECT SUM(tx.amount) FROM contract_transactions tx WHERE tx.contract_id = c.id AND tx.tx_type = 'receipt'), 0) AS receipt_done,
            COALESCE((SELECT SUM(tx.amount) FROM contract_transactions tx WHERE tx.contract_id = c.id AND tx.tx_type = 'payment'), 0) AS payment_done
     FROM contracts c
     LEFT JOIN contract_types t ON t.id = c.type_id
     WHERE c.id = ?"
);
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    redirect('orders.php?err=' . rawurlencode('合同不存在'));
}
if ($ownOnly && (int) ($row['created_by'] ?? 0) !== $currentAdminId) {
    redirect('orders.php?err=' . rawurlencode('仅可操作自己登记的合同'));
}
require_permission(((int) ($row['is_archived'] ?? 0) === 1) ? 'archived.export' : 'contracts.export');

$fileSt = $pdo->prepare('SELECT origin_name, file_path FROM contract_files WHERE contract_id = ? ORDER BY id ASC');
$fileSt->execute([$id]);
$files = $fileSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$txSt = $pdo->prepare(
    "SELECT t.tx_type, t.amount, t.note, t.voucher_path, t.created_at, COALESCE(a.display_name, a.username, '-') AS registrar_name
     FROM contract_transactions t
     LEFT JOIN admins a ON a.id = t.created_by
     WHERE t.contract_id = ?
     ORDER BY t.id ASC"
);
$txSt->execute([$id]);
$txRows = $txSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$invoiceRows = [];
if (db_table_exists($pdo, 'contract_invoices')) {
    $invoiceSt = $pdo->prepare('SELECT invoice_type, amount, note, file_path, created_at FROM contract_invoices WHERE contract_id = ? ORDER BY id ASC');
    $invoiceSt->execute([$id]);
    $invoiceRows = $invoiceSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$normalize = static function (string $name): string {
    $name = trim($name);
    $name = preg_replace('/[\\\\\/:\*\?"<>\|]+/u', '_', $name) ?? '';
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';
    $name = trim($name, " .\t\n\r\0\x0B");
    return $name !== '' ? $name : '合同导出';
};

$folderName = $normalize((string) $row['contract_no'] . '+' . (string) $row['contract_name']);
$zipName = $folderName . '.zip';
$tmpZip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'contract_export_' . $id . '_' . bin2hex(random_bytes(6)) . '.zip';

$zip = new ZipArchive();
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    redirect('orders.php?err=' . rawurlencode('导出失败：无法创建压缩包'));
}

$safe = static function (?string $v): string {
    $v = (string) ($v ?? '');
    return $v !== '' ? $v : '-';
};

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
$zip->addFromString($folderName . '/合同信息.csv', $csv);

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
$zip->addFromString($folderName . '/收付款进度明细.csv', $txCsv);

$zip->addEmptyDir($folderName . '/附件');
foreach ($files as $f) {
    $originName = $normalize((string) $f['origin_name']);
    $relPath = ltrim((string) $f['file_path'], '/');
    $fullPath = __DIR__ . '/' . $relPath;
    if ($originName === '' || !is_file($fullPath)) {
        continue;
    }
    $zip->addFile($fullPath, $folderName . '/附件/' . $originName);
}

$zip->addEmptyDir($folderName . '/收付款凭证');
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
    $zip->addFile($fullPath, $folderName . '/收付款凭证/' . $txType . '_' . str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT) . '_' . $originName);
}

$invoiceCsvRows = [];
$invoiceCsvRows[] = ['合同编号', '合同名称', '发票类型', '开票金额', '备注', '附件文件', '开票时间'];
foreach ($invoiceRows as $inv) {
    $invoiceTypeLabel = ((string) ($inv['invoice_type'] ?? 'receipt')) === 'payment' ? '付款发票' : '收款发票';
    $invoiceFile = basename((string) ($inv['file_path'] ?? ''));
    $invoiceCsvRows[] = [
        $safe($row['contract_no']),
        $safe($row['contract_name']),
        $invoiceTypeLabel,
        number_format((float) ($inv['amount'] ?? 0), 2, '.', ''),
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
$zip->addFromString($folderName . '/发票明细.csv', $invoiceCsv);

$zip->addEmptyDir($folderName . '/发票附件');
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
    $zip->addFile($fullPath, $folderName . '/发票附件/' . $invoiceType . '_' . str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT) . '_' . $originName);
}

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
