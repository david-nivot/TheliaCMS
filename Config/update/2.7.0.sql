-- 2.7.0: the color of a tag is free. `cms_tag.color` holds the `#rrggbb` code
-- of its background, and the new `cms_tag.text_color` the code of its text,
-- NULL to leave it to the contrast (TheliaCMS\Page\PageTagColor). A tag
-- colored with a name of the palette of 2.5 and 2.6 takes the codes of the
-- badge it gave in the back office, so it looks the same.
--
-- Written to be replayable: a fresh install runs it after TheliaMain.sql, which
-- already has the column, and a code is never a name of the palette.
-- `ADD COLUMN IF NOT EXISTS` is not used: MariaDB understands it and MySQL
-- does not.

SET @cms_tag_text_color_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_tag' AND column_name = 'text_color'
);

SET @cms_tag_text_color_add := IF(
    0 = @cms_tag_text_color_exists,
    'ALTER TABLE `cms_tag` ADD COLUMN `text_color` VARCHAR(16) DEFAULT NULL AFTER `color`',
    'DO 0'
);

PREPARE cms_tag_text_color_statement FROM @cms_tag_text_color_add;

EXECUTE cms_tag_text_color_statement;

DEALLOCATE PREPARE cms_tag_text_color_statement;

UPDATE `cms_tag` SET `text_color` = '#ffffff', `color` = '#dc3545' WHERE `color` = 'red';
UPDATE `cms_tag` SET `text_color` = '#000000', `color` = '#f26041' WHERE `color` = 'orange';
UPDATE `cms_tag` SET `text_color` = '#000000', `color` = '#ffc107' WHERE `color` = 'yellow';
UPDATE `cms_tag` SET `text_color` = '#ffffff', `color` = '#198754' WHERE `color` = 'green';
UPDATE `cms_tag` SET `text_color` = '#000000', `color` = '#0dcaf0' WHERE `color` = 'cyan';
UPDATE `cms_tag` SET `text_color` = '#ffffff', `color` = '#212529' WHERE `color` = 'dark';
