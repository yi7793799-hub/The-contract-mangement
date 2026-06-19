-- 添加项目号字段到contracts表
ALTER TABLE `contracts` ADD COLUMN `project_no` varchar(100) DEFAULT NULL COMMENT '项目号（合同唯一标识）' AFTER `contract_no`;

-- 为项目号创建索引以便快速查找
CREATE INDEX `idx_project_no` ON `contracts` (`project_no`);
