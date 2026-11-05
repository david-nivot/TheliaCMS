-- 2.13.0: a language written from another one keeps which one and what its
-- words were then. `translated_from` is the locale of the source, and
-- `source_fingerprint` a JSON object, `{"sections": {"<section id>": "<sha256
-- of the words of the section>"}}` (TheliaCMS\Content\TranslatableWords), on
-- the content of a page and on the one of a catalogue item alike. Both are NULL
-- for a content that follows nothing, which is every row there is when this
-- update runs: the translations written before are attached with
-- `thelia_cms:translations:follow`, or by hand from the content screen.
--
-- Written to be replayable: a fresh install runs it after TheliaMain.sql, which
-- already has the columns. `ADD COLUMN IF NOT EXISTS` is not used: MariaDB
-- understands it and MySQL does not.

SET @cms_page_content_translated_from_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_page_content' AND column_name = 'translated_from'
);

SET @cms_page_content_translated_from_add := IF(
    0 = @cms_page_content_translated_from_exists,
    'ALTER TABLE `cms_page_content` ADD COLUMN `translated_from` VARCHAR(5) DEFAULT NULL AFTER `published_provenance`',
    'DO 0'
);

PREPARE cms_page_content_translated_from_statement FROM @cms_page_content_translated_from_add;

EXECUTE cms_page_content_translated_from_statement;

DEALLOCATE PREPARE cms_page_content_translated_from_statement;

SET @cms_page_content_source_fingerprint_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_page_content' AND column_name = 'source_fingerprint'
);

SET @cms_page_content_source_fingerprint_add := IF(
    0 = @cms_page_content_source_fingerprint_exists,
    'ALTER TABLE `cms_page_content` ADD COLUMN `source_fingerprint` LONGTEXT DEFAULT NULL AFTER `translated_from`',
    'DO 0'
);

PREPARE cms_page_content_source_fingerprint_statement FROM @cms_page_content_source_fingerprint_add;

EXECUTE cms_page_content_source_fingerprint_statement;

DEALLOCATE PREPARE cms_page_content_source_fingerprint_statement;

SET @cms_item_content_translated_from_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_item_content' AND column_name = 'translated_from'
);

SET @cms_item_content_translated_from_add := IF(
    0 = @cms_item_content_translated_from_exists,
    'ALTER TABLE `cms_item_content` ADD COLUMN `translated_from` VARCHAR(5) DEFAULT NULL AFTER `published_provenance`',
    'DO 0'
);

PREPARE cms_item_content_translated_from_statement FROM @cms_item_content_translated_from_add;

EXECUTE cms_item_content_translated_from_statement;

DEALLOCATE PREPARE cms_item_content_translated_from_statement;

SET @cms_item_content_source_fingerprint_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_item_content' AND column_name = 'source_fingerprint'
);

SET @cms_item_content_source_fingerprint_add := IF(
    0 = @cms_item_content_source_fingerprint_exists,
    'ALTER TABLE `cms_item_content` ADD COLUMN `source_fingerprint` LONGTEXT DEFAULT NULL AFTER `translated_from`',
    'DO 0'
);

PREPARE cms_item_content_source_fingerprint_statement FROM @cms_item_content_source_fingerprint_add;

EXECUTE cms_item_content_source_fingerprint_statement;

DEALLOCATE PREPARE cms_item_content_source_fingerprint_statement;
