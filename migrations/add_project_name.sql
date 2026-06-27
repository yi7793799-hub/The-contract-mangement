-- 添加项目名称字段到contracts表
ALTER TABLE `contracts` ADD COLUMN `project_name` VARCHAR(200) DEFAULT NULL COMMENT '项目名称' AFTER `project_no`;

-- 为项目名称创建索引以便快速查找
CREATE INDEX `idx_project_name` ON `contracts` (`project_name`);