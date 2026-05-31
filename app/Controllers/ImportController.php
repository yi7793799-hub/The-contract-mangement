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
        global $pageTitle, $activeNav;
        $pageTitle = '批量导入';
        $activeNav = 'import';

        // 检查是否有通知消息
        $notification = $_SESSION['import_notification'] ?? null;
        unset($_SESSION['import_notification']);
        $pendingCount = $this->getPendingReviewCount();

        ob_start();
        ?>
        <div class="mf-panel">
            <div class="mf-panel__header">
                <h3>合同批量导入</h3>
            </div>
            <div class="mf-panel__body">
                <?php if ($notification): ?>
                <div class="mf-alert mf-alert--success mf-mb-3">
                    导入完成！成功: <?= e($notification['success']) ?> | 待审核: <?= e($notification['pending']) ?> | 失败: <?= e($notification['failed']) ?>
                </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success'])): ?>
                <div class="mf-alert mf-alert--success mf-mb-3"><?= e($_SESSION['success']) ?></div>
                <?php unset($_SESSION['success']); endif; ?>

                <?php if (isset($_SESSION['error'])): ?>
                <div class="mf-alert mf-alert--danger mf-mb-3"><?= e($_SESSION['error']) ?></div>
                <?php unset($_SESSION['error']); endif; ?>

                <form method="post" action="<?= url('import/process.php') ?>">
                    <div class="mf-form-item">
                        <label class="mf-label">文件夹路径 <span class="mf-text-danger">*</span></label>
                        <input type="text" name="folder_path" class="mf-input"
                               placeholder="请输入包含合同文件的文件夹路径，如 D:\contracts"
                               required style="width:100%;">
                        <div class="mf-form-help">支持 .doc, .docx, .pdf, .jpg, .png, .webp 格式</div>
                    </div>

                    <div class="mf-form-item">
                        <button type="submit" class="mf-btn mf-btn--primary">
                            <i class="bi bi-upload"></i> 开始导入
                        </button>

                        <?php if ($pendingCount > 0): ?>
                        <a href="<?= url('import/review.php') ?>" class="mf-btn mf-btn--warning">
                            查看待审核合同 (<?= $pendingCount ?>)
                        </a>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="mf-panel mf-mt-3" style="background:#f8f9fa;">
                    <div class="mf-panel__header">导入说明</div>
                    <div class="mf-panel__body">
                        <ul style="margin:0;padding-left:1.5em;line-height:2;">
                            <li>请确保文件夹内的文件格式为支持的格式</li>
                            <li>系统将自动识别合同文本并提取关键字段</li>
                            <li>使用百度 OCR 识别图片和扫描版 PDF</li>
                            <li>使用 DeepSeek 大模型进行语义校验</li>
                            <li>低置信度合同将标记为待审核状态</li>
                            <li>原始文件将保存为合同附件</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $content = ob_get_clean();
        require dirname(__DIR__, 2) . '/includes/layout.php';
    }

    /**
     * 处理导入请求
     */
    public function process(): void
    {
        require_permission('import.create');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/import.php');
        }

        $folderPath = $_POST['folder_path'] ?? '';

        if (empty($folderPath) || !is_dir($folderPath)) {
            $_SESSION['error'] = '请选择有效的文件夹';
            redirect('/import.php');
        }

        // 路径白名单验证
        $realPath = realpath($folderPath);
        $config = import_config();
        $allowedPaths = $config['allowed_paths'] ?? [];
        $allowed = false;
        foreach ($allowedPaths as $allowedPath) {
            if (strpos($realPath, realpath($allowedPath)) === 0) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            $_SESSION['error'] = '该路径不在允许导入的目录范围内';
            redirect('/import.php');
        }

        $admin = current_admin();
        $adminId = $admin['id'] ?? 0;
        if ($adminId <= 0) {
            $_SESSION['error'] = '请先登录';
            redirect('/login.php');
        }
        $jobId = $this->service->processFolder($folderPath, $adminId);

        $_SESSION['success'] = '导入任务已启动，请在待审核页面查看结果';
        redirect('/import.php');
    }

    /**
     * 待审核列表页
     */
    public function reviewList(): void
    {
        global $pageTitle, $activeNav;
        $pageTitle = '待审核合同';
        $activeNav = 'import';

        require_permission('import.review');

        $page = max(1, (int) ($_GET['page'] ?? 1));
        $perPage = 20;

        $contracts = $this->getPendingContracts($page, $perPage);
        $total = $this->getPendingContractsCount();
        $totalPages = ceil($total / $perPage);

        ob_start();
        ?>
        <div class="mf-panel">
            <div class="mf-panel__header">
                <h3>待审核合同列表</h3>
                <div class="mf-panel__actions">
                    <a href="<?= url('import.php') ?>" class="mf-btn mf-btn--default">
                        <i class="bi bi-arrow-left"></i> 返回导入
                    </a>
                </div>
            </div>
            <div class="mf-panel__body">
                <?php if (empty($contracts)): ?>
                <div class="mf-empty-state">
                    <i class="bi bi-inbox" style="font-size:48px;color:#c0c4cc;"></i>
                    <p>暂无待审核合同</p>
                </div>
                <?php else: ?>
                <form id="batchForm" method="post" action="<?= url('import/batch-approve.php') ?>">
                    <div class="mf-table-wrap">
                        <table class="mf-table">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="selectAll"></th>
                                    <th>合同编号</th>
                                    <th>合同名称</th>
                                    <th>客户名称</th>
                                    <th>置信度</th>
                                    <th>创建时间</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contracts as $c): ?>
                                <tr>
                                    <td><input type="checkbox" name="ids[]" value="<?= e($c['id']) ?>"></td>
                                    <td><?= e($c['contract_no'] ?? '-') ?></td>
                                    <td><?= e($c['contract_name'] ?? '-') ?></td>
                                    <td><?= e($c['customer_name'] ?? '-') ?></td>
                                    <td>
                                        <?php
                                        $conf = (float) ($c['import_confidence'] ?? 0);
                                        $badgeClass = $conf >= 85 ? 'mf-badge--success' : ($conf >= 60 ? 'mf-badge--warning' : 'mf-badge--danger');
                                        ?>
                                        <span class="mf-badge <?= $badgeClass ?>"><?= number_format($conf, 0) ?>%</span>
                                    </td>
                                    <td><?= e($c['created_at'] ?? '-') ?></td>
                                    <td>
                                        <a href="<?= url('import/review/detail.php?id=' . $c['id']) ?>" class="mf-btn mf-btn--sm mf-btn--primary">审核</a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mf-flex mf-items-center mf-gap-2 mf-mt-3">
                        <button type="button" class="mf-btn mf-btn--success" onclick="batchApprove()">批量通过</button>
                        <button type="button" class="mf-btn mf-btn--danger" onclick="batchReject()">批量驳回</button>
                    </div>
                </form>

                <?php if ($totalPages > 1): ?>
                <div class="mf-pagination mf-mt-3">
                    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="mf-pagination__item active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?page=<?= $i ?>" class="mf-pagination__item"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <script>
        document.getElementById('selectAll')?.addEventListener('change', function() {
            document.querySelectorAll('input[name="ids[]"]').forEach(cb => cb.checked = this.checked);
        });
        function batchApprove() {
            document.getElementById('batchForm').action = '<?= url('import/batch-approve.php') ?>';
            document.getElementById('batchForm').submit();
        }
        function batchReject() {
            if (confirm('确定要批量驳回选中的合同吗？')) {
                document.getElementById('batchForm').action = '<?= url('import/batch-reject.php') ?>';
                document.getElementById('batchForm').submit();
            }
        }
        </script>
        <?php
        $content = ob_get_clean();
        require dirname(__DIR__, 2) . '/includes/layout.php';
    }

    /**
     * 审核详情页
     */
    public function reviewDetail(int $id): void
    {
        global $pageTitle, $activeNav;
        $pageTitle = '审核合同';
        $activeNav = 'import';

        require_permission('import.review');

        $contract = $this->getContractForReview($id);

        if (!$contract) {
            $_SESSION['error'] = '合同不存在';
            redirect('/import/review.php');
        }

        // 获取识别字段及其置信度
        $fields = json_decode($contract['import_fields'] ?? '', true) ?? [];
        $ocrText = $contract['ocr_raw_text'] ?? '';

        // 获取附件
        $files = $this->getContractFiles($id);

        // 获取合同类型列表
        $types = $this->getContractTypes();

        ob_start();
        ?>
        <div class="mf-panel">
            <div class="mf-panel__header">
                <h3>合同审核详情</h3>
                <div class="mf-panel__actions">
                    <a href="<?= url('import/review.php') ?>" class="mf-btn mf-btn--default">
                        <i class="bi bi-arrow-left"></i> 返回列表
                    </a>
                </div>
            </div>
            <div class="mf-panel__body">
                <div class="mf-row">
                    <div class="mf-col-md-6">
                        <h4>合同信息（可编辑）</h4>
                        <form id="editForm" method="post" action="<?= url('import/update.do.php') ?>">
                            <input type="hidden" name="id" value="<?= $id ?>">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

                            <div class="mf-form-item">
                                <label class="mf-label">合同编号</label>
                                <input type="text" name="contract_no" class="mf-input" value="<?= e($contract['contract_no'] ?? '') ?>">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">合同名称 <span class="mf-text-danger">*</span></label>
                                <input type="text" name="contract_name" class="mf-input" value="<?= e($contract['contract_name'] ?? '') ?>" required>
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">客户名称 <span class="mf-text-danger">*</span></label>
                                <input type="text" name="customer_name" class="mf-input" value="<?= e($contract['customer_name'] ?? '') ?>" required>
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">签约方</label>
                                <input type="text" name="signer_party" class="mf-input" value="<?= e($contract['signer_party'] ?? '') ?>">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">签约人</label>
                                <input type="text" name="signer_name" class="mf-input" value="<?= e($contract['signer_name'] ?? '') ?>">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">联系电话</label>
                                <input type="text" name="phone" class="mf-input" value="<?= e($contract['phone'] ?? '') ?>">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">合同金额</label>
                                <input type="number" name="amount" class="mf-input" value="<?= e($contract['amount'] ?? 0) ?>" step="0.01">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">签订日期</label>
                                <input type="date" name="signed_date" class="mf-input" value="<?= e($contract['signed_date'] ?? '') ?>">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">生效日期</label>
                                <input type="date" name="effective_date" class="mf-input" value="<?= e($contract['effective_date'] ?? '') ?>">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">截止日期</label>
                                <input type="date" name="expiry_date" class="mf-input" value="<?= e($contract['expiry_date'] ?? '') ?>">
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">合同类型</label>
                                <select name="type_id" class="mf-input">
                                    <option value="">-- 请选择 --</option>
                                    <?php foreach ($types as $t): ?>
                                    <option value="<?= e($t['id']) ?>" <?= ($contract['type_id'] ?? '') == $t['id'] ? 'selected' : '' ?>>
                                        <?= e($t['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mf-form-item">
                                <label class="mf-label">款项类型</label>
                                <select name="payment_type" class="mf-input">
                                    <option value="receipt" <?= ($contract['payment_type'] ?? 'receipt') === 'receipt' ? 'selected' : '' ?>>收款</option>
                                    <option value="payment" <?= ($contract['payment_type'] ?? '') === 'payment' ? 'selected' : '' ?>>付款</option>
                                </select>
                            </div>

                            <div class="mf-flex mf-gap-2 mf-mt-3">
                                <button type="submit" class="mf-btn mf-btn--primary">
                                    <i class="bi bi-save"></i> 保存修改
                                </button>
                                <a href="<?= url('import/approve/do.php?id=' . $id) ?>" class="mf-btn mf-btn--success" onclick="return confirm('保存并审核通过？')">
                                    <i class="bi bi-check-lg"></i> 保存并通过
                                </a>
                            </div>
                        </form>
                    </div>
                    <div class="mf-col-md-6">
                        <h4>OCR 识别结果</h4>
                        <?php if (!empty($fields)): ?>
                        <table class="mf-table mf-table--sm">
                            <thead>
                                <tr>
                                    <th>字段</th>
                                    <th>置信度</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fields as $name => $conf): ?>
                                <?php if (is_numeric($conf)): ?>
                                <tr>
                                    <td><?= e($name) ?></td>
                                    <td>
                                        <?php
                                        $confVal = (float) $conf;
                                        $badgeClass = $confVal >= 85 ? 'mf-badge--success' : ($confVal >= 60 ? 'mf-badge--warning' : 'mf-badge--danger');
                                        ?>
                                        <span class="mf-badge <?= $badgeClass ?>"><?= number_format($confVal, 0) ?>%</span>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p class="mf-text-muted">无置信度数据</p>
                        <?php endif; ?>

                        <h4 class="mf-mt-3">原始OCR文本</h4>
                        <div style="background:#f5f5f5;padding:12px;border-radius:4px;max-height:250px;overflow:auto;font-size:12px;">
                            <pre style="white-space:pre-wrap;word-break:break-all;margin:0;"><?= e($ocrText) ?></pre>
                        </div>

                        <h4 class="mf-mt-3">附件文件</h4>
                        <?php if (empty($files)): ?>
                        <p class="mf-text-muted">无附件</p>
                        <?php else: ?>
                        <ul class="mf-list">
                            <?php foreach ($files as $file): ?>
                            <li>
                                <i class="bi bi-file-earmark-pdf"></i>
                                <?= e($file['origin_name'] ?? $file['file_name']) ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>

                        <div class="mf-mt-3">
                            <a href="<?= url('import/reject/do.php?id=' . $id) ?>" class="mf-btn mf-btn--danger" onclick="return confirm('确定要驳回删除此合同吗？');">
                                <i class="bi bi-x-lg"></i> 驳回删除
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        // 保存并通过：先保存再通过
        document.querySelector('a[href*="approve/do"]').addEventListener('click', function(e) {
            e.preventDefault();
            var form = document.getElementById('editForm');
            var href = this.href;
            form.action = '<?= url('import/update-approve.do.php') ?>';
            form.submit();
        });
        </script>
        <?php
        $content = ob_get_clean();
        require dirname(__DIR__, 2) . '/includes/layout.php';
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
        redirect('/import/review.php');
    }

    /**
     * 更新合同信息
     */
    public function update(): void
    {
        require_permission('import.review.edit');

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = '无效的合同ID';
            redirect('/import/review.php');
        }

        if (!csrf_verify($_POST['csrf'] ?? '')) {
            $_SESSION['error'] = '会话已过期';
            redirect('/import/review/detail.php?id=' . $id);
        }

        $stmt = db()->prepare(
            "UPDATE contracts SET
                contract_no = ?, contract_name = ?, customer_name = ?, signer_party = ?,
                signer_name = ?, phone = ?, amount = ?, signed_date = ?, effective_date = ?,
                expiry_date = ?, type_id = ?, payment_type = ?
            WHERE id = ? AND status = 'pending_review'"
        );

        $stmt->execute([
            trim($_POST['contract_no'] ?? ''),
            trim($_POST['contract_name'] ?? ''),
            trim($_POST['customer_name'] ?? ''),
            trim($_POST['signer_party'] ?? ''),
            trim($_POST['signer_name'] ?? ''),
            trim($_POST['phone'] ?? ''),
            (float) ($_POST['amount'] ?? 0),
            $_POST['signed_date'] ?? null,
            $_POST['effective_date'] ?? null,
            $_POST['expiry_date'] ?? null,
            (int) ($_POST['type_id'] ?? 0) ?: null,
            $_POST['payment_type'] ?? 'receipt',
            $id,
        ]);

        $_SESSION['success'] = '合同信息已更新';
        redirect('/import/review/detail.php?id=' . $id);
    }

    /**
     * 更新并审核通过
     */
    public function updateAndApprove(): void
    {
        require_permission('import.review.edit');

        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $_SESSION['error'] = '无效的合同ID';
            redirect('/import/review.php');
        }

        if (!csrf_verify($_POST['csrf'] ?? '')) {
            $_SESSION['error'] = '会话已过期';
            redirect('/import/review/detail.php?id=' . $id);
        }

        // 先更新
        $stmt = db()->prepare(
            "UPDATE contracts SET
                contract_no = ?, contract_name = ?, customer_name = ?, signer_party = ?,
                signer_name = ?, phone = ?, amount = ?, signed_date = ?, effective_date = ?,
                expiry_date = ?, type_id = ?, payment_type = ?, status = 'ongoing'
            WHERE id = ? AND status = 'pending_review'"
        );

        $stmt->execute([
            trim($_POST['contract_no'] ?? ''),
            trim($_POST['contract_name'] ?? ''),
            trim($_POST['customer_name'] ?? ''),
            trim($_POST['signer_party'] ?? ''),
            trim($_POST['signer_name'] ?? ''),
            trim($_POST['phone'] ?? ''),
            (float) ($_POST['amount'] ?? 0),
            $_POST['signed_date'] ?? null,
            $_POST['effective_date'] ?? null,
            $_POST['expiry_date'] ?? null,
            (int) ($_POST['type_id'] ?? 0) ?: null,
            $_POST['payment_type'] ?? 'receipt',
            $id,
        ]);

        $_SESSION['success'] = '合同已更新并审核通过';
        redirect('/import/review.php');
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
        redirect('/import/review.php');
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
        redirect('/import/review.php');
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
        redirect('/import/review.php');
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
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private function getContractFiles(int $contractId): array
    {
        $stmt = db()->prepare("SELECT * FROM contract_files WHERE contract_id = ?");
        $stmt->execute([$contractId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function getContractTypes(): array
    {
        $stmt = db()->query("SELECT id, name FROM contract_types WHERE status = 'active' ORDER BY name");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
}
