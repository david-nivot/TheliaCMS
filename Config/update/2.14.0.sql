-- 2.14.0: a page can open on its own sections, without the heading band of
-- the theme. `title_band` is 1 for a page that opens on the band (title,
-- summary, breadcrumb), which every page there is did when this update runs,
-- and 0 for one that does not: its title is then drawn as a level 1 heading
-- hidden on screen (ADR 0010).
--
-- Written to be replayable: a fresh install runs it after TheliaMain.sql, which
-- already has the column. `ADD COLUMN IF NOT EXISTS` is not used: MariaDB
-- understands it and MySQL does not.

SET @cms_page_title_band_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_page' AND column_name = 'title_band'
);

SET @cms_page_title_band_add := IF(
    0 = @cms_page_title_band_exists,
    'ALTER TABLE `cms_page` ADD COLUMN `title_band` TINYINT(4) DEFAULT 1 NOT NULL AFTER `model`',
    'DO 0'
);

PREPARE cms_page_title_band_statement FROM @cms_page_title_band_add;

EXECUTE cms_page_title_band_statement;

DEALLOCATE PREPARE cms_page_title_band_statement;
