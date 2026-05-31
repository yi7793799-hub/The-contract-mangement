<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\ContractImportService;

class ImportController
{
    /** @var ContractImportService */
    private $service;

    public function __construct()
    {
        require_login();
        require_permission('import.view');
        $this->service = new ContractImportService();
    }

    /**
     * 导入上传页
     */
    public function index(): void
    {
        // 检查是否有通知消息
        $notification = $_SESSION['import_notification'] ?? null;
        unset($_SESSION['import_notification']);

        $data = [
            'notification' => $notification,
            'pending_count' => $this->getPendingReviewCount(),
        ];

        $this->view('import/upload', $data);
    }

    /**
     * 处理导入请求
     */
    public function process(): void
    {
        require_permission('import.create');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/import');
        }

        $folderPath = $_POST['folder_path'] ?? '';

        if (empty($folderPath) || !is_dir($folderPath)) {
            $_SESSION['error'] = '请选择有效的文件夹';
            redirect('/import');
        }

        // 后台处理（这里简化处理，实际应该用队列）
        $jobId = $this->service->processFolder($folderPath, $_SESSION['admin_id']);

        $_SESSION['success'] = '导入任务已启动，请在待审核页面查看结果';
        redirect('/import');
    }

    /**
     * 待审核列表页
     */
    public function reviewList(): void
    {
        require_permission('import.review');

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;

        $contracts = $this->getPendingContracts($page, $perPage);
        $total = $this->getPendingContractsCount();
        $totalPages = ceil($total / $perPage);

        $data = [
            'contracts' => $contracts,
            'page' => $page,
            'totalPages' => $totalPages,
            'total' => $total,
        ];

        $this->view('import/review_list', $data);
    }

    /**
     * 审核详情页
     */
    public function reviewDetail(int $id): void
    {
        require_permission('import.review');

        $contract = $this->getContractForReview($id);

        if (!$contract) {
            $_SESSION['error'] = '合同不存在';
            redirect('/import/review');
        }

        // 获取识别字段及其置信度
        $fields = json_decode($contract['import_fields'], true) ?? [];
        $ocrText = $contract['ocr_raw_text'] ?? '';

        // 获取附件
        $files = $this->getContractFiles($id);

        $data = [
            'contract' => $contract,
            'fields' => $fields,
            'ocr_text' => $ocrText,
            'files' => $files,
        ];

        $this->view('import/review_detail', $data);
    }

    /**
     * 审核通过
     */
    public function approve(int $id): void
    {
        require_permission('import.review.edit');

        $stmt = db()->prepare("UPDATE contracts SET status = 'ongoing' WHERE id = ? AND status = 'pending_review'");
        $stmt->execute([$id]);

        $_SESSION['success'] = '合同已审核通过';
        redirect('/import/review');
    }

    /**
     * 审核驳回
     */
    public function reject(int $id): void
    {
        require_permission('import.review.edit');

        // 删除合同及其附件
        $this->deleteContract($id);

        $_SESSION['success'] = '合同已驳回';
        redirect('/import/review');
    }

    /**
     * 批量审核通过
     */
    public function batchApprove(): void
    {
        require_permission('import.review.edit');

        $ids = $_POST['ids'] ?? [];

        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("UPDATE contracts SET status = 'ongoing' WHERE id IN ({$placeholders}) AND status = 'pending_review'");
            $stmt->execute($ids);
        }

        $_SESSION['success'] = '已批量通过 ' . count($ids) . ' 个合同';
        redirect('/import/review');
    }

    /**
     * 批量驳回
     */
    public function batchReject(): void
    {
        require_permission('import.review.edit');

        $ids = $_POST['ids'] ?? [];

        if (!empty($ids)) {
            foreach ($ids as $id) {
                $this->deleteContract((int) $id);
            }
        }

        $_SESSION['success'] = '已批量驳回 ' . count($ids) . ' 个合同';
        redirect('/import/review');
    }

    // ========== 私有方法 ==========

    private function getPendingContracts(int $page, int $perPage): array
    {
        $offset = ($page - 1) * $perPage;

        $stmt = db()->prepare(
            "SELECT c.*, u.display_name as created_by_name,
                    ct.name as type_name
             FROM contracts c
             LEFT JOIN admins u ON c.created_by = u.id
             LEFT JOIN contract_types ct ON c.type_id = ct.id
             WHERE c.status = 'pending_review'
             ORDER BY c.id DESC
             LIMIT ? OFFSET ?"
        );
        $stmt->execute([$perPage, $offset]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getPendingContractsCount(): int
    {
        $stmt = db()->query("SELECT COUNT(*) FROM contracts WHERE status = 'pending_review'");
        return (int) $stmt->fetchColumn();
    }

    private function getPendingReviewCount(): int
    {
        return $this->getPendingContractsCount();
    }

    private function getContractForReview(int $id): ?array
    {
        $stmt = db()->prepare(
            "SELECT c.*, ct.name as type_name
             FROM contracts c
             LEFT JOIN contract_types ct ON c.type_id = ct.id
             WHERE c.id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getContractFiles(int $contractId): array
    {
        $stmt = db()->prepare("SELECT * FROM contract_files WHERE contract_id = ?");
        $stmt->execute([$contractId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function deleteContract(int $id): void
    {
        // 删除附件文件
        $files = $this->getContractFiles($id);
        foreach ($files as $file) {
            $path = $file['file_path'];
            if (file_exists($path)) {
                unlink($path);
            }
        }

        // 删除数据库记录（外键会级联删除附件）
        $stmt = db()->prepare("DELETE FROM contracts WHERE id = ?");
        $stmt->execute([$id]);
    }

    protected function view(string $template, array $data = []): void
    {
        extract($data);

        $viewPath = __DIR__ . '/../Views/' . $template . '.php';
        if (!is_file($viewPath)) {
            throw new Exception('View not found: ' . $viewPath);
        }

        require $viewPath;
    }
}
