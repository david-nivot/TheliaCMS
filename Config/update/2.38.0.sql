-- 2.38.0: a hidden page (`model = 2`) says what kind of content it is, so a
-- module can declare its own (ADR 20). The header zone, the only hidden page
-- there was, becomes the kind `header`: same row, same id, same texts.
--
-- Written to be replayable: a fresh install runs it after TheliaMain.sql, which
-- already has the column and the index. `ADD COLUMN IF NOT EXISTS` is not
-- used: MariaDB understands it and MySQL does not.

SET @cms_page_content_kind_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_page' AND column_name = 'content_kind'
);

SET @cms_page_content_kind_add := IF(
    0 = @cms_page_content_kind_exists,
    'ALTER TABLE `cms_page` ADD COLUMN `content_kind` VARCHAR(32) NULL AFTER `model`',
    'DO 0'
);

PREPARE cms_page_content_kind_statement FROM @cms_page_content_kind_add;

EXECUTE cms_page_content_kind_statement;

DEALLOCATE PREPARE cms_page_content_kind_statement;

SET @cms_page_content_kind_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'cms_page' AND index_name = 'idx_cms_page_content_kind'
);

SET @cms_page_content_kind_index_add := IF(
    0 = @cms_page_content_kind_index_exists,
    'ALTER TABLE `cms_page` ADD INDEX `idx_cms_page_content_kind` (`content_kind`)',
    'DO 0'
);

PREPARE cms_page_content_kind_index_statement FROM @cms_page_content_kind_index_add;

EXECUTE cms_page_content_kind_index_statement;

DEALLOCATE PREPARE cms_page_content_kind_index_statement;

UPDATE `cms_page` SET `content_kind` = 'header' WHERE `model` = 2 AND `content_kind` IS NULL;
