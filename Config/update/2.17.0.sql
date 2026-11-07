-- 2.17.0: the content screen undoes and redoes its changes. Each change made
-- from the screen keeps the draft before and after it in `cms_content_step`.
--
-- Written to be replayable: the same file is applied by a fresh install (after
-- TheliaMain.sql, which already contains this table) and by an update of an
-- existing site, so every statement tolerates being run twice.

CREATE TABLE IF NOT EXISTS `cms_content_step`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `content_kind` VARCHAR(16) NOT NULL,
    `content_id` INTEGER NOT NULL,
    `locale` VARCHAR(5) NOT NULL,
    `action` VARCHAR(20) NOT NULL,
    `section` VARCHAR(120),
    `document_before` LONGTEXT,
    `provenance_before` LONGTEXT,
    `document_after` LONGTEXT,
    `provenance_after` LONGTEXT,
    `undone` TINYINT DEFAULT 0 NOT NULL,
    `created_by` INTEGER,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_cms_content_step_content` (`content_kind`, `content_id`, `locale`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;
