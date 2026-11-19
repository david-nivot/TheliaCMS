-- 2.36.0: a draft carries a version, moved by every write of its content. A
-- screen posts the version it read, and a save made on a version that moved
-- since is refused instead of overwriting what someone else wrote. The date of
-- the draft cannot tell two saves of the same second apart, and MySQL rounds
-- a fraction of a second where MariaDB cuts it.
--
-- Every draft there is when this update runs starts at 0.
--
-- Written to be replayable: a fresh install runs it after TheliaMain.sql, which
-- already has the columns. `ADD COLUMN IF NOT EXISTS` is not used: MariaDB
-- understands it and MySQL does not.

SET @cms_page_content_version_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_page_content' AND column_name = 'draft_version'
);

SET @cms_page_content_version_add := IF(
    0 = @cms_page_content_version_exists,
    'ALTER TABLE `cms_page_content` ADD COLUMN `draft_version` INTEGER DEFAULT 0 NOT NULL AFTER `draft_updated_by`',
    'DO 0'
);

PREPARE cms_page_content_version_statement FROM @cms_page_content_version_add;

EXECUTE cms_page_content_version_statement;

DEALLOCATE PREPARE cms_page_content_version_statement;

SET @cms_item_content_version_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_item_content' AND column_name = 'draft_version'
);

SET @cms_item_content_version_add := IF(
    0 = @cms_item_content_version_exists,
    'ALTER TABLE `cms_item_content` ADD COLUMN `draft_version` INTEGER DEFAULT 0 NOT NULL AFTER `draft_updated_by`',
    'DO 0'
);

PREPARE cms_item_content_version_statement FROM @cms_item_content_version_add;

EXECUTE cms_item_content_version_statement;

DEALLOCATE PREPARE cms_item_content_version_statement;
