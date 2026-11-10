-- 2.24.0: a menu and each of its entries can be hidden from the site without
-- being deleted. A menu that is not visible renders nothing, an entry that is
-- not visible is left out with everything under it. Every menu and every
-- entry there is when this update runs stays visible.
--
-- Written to be replayable: a fresh install runs it after TheliaMain.sql, which
-- already has the columns. `ADD COLUMN IF NOT EXISTS` is not used: MariaDB
-- understands it and MySQL does not.

SET @cms_menu_visible_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_menu' AND column_name = 'visible'
);

SET @cms_menu_visible_add := IF(
    0 = @cms_menu_visible_exists,
    'ALTER TABLE `cms_menu` ADD COLUMN `visible` TINYINT(4) DEFAULT 1 NOT NULL AFTER `menu_locale`',
    'DO 0'
);

PREPARE cms_menu_visible_statement FROM @cms_menu_visible_add;

EXECUTE cms_menu_visible_statement;

DEALLOCATE PREPARE cms_menu_visible_statement;

SET @cms_menu_item_visible_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_menu_item' AND column_name = 'visible'
);

SET @cms_menu_item_visible_add := IF(
    0 = @cms_menu_item_visible_exists,
    'ALTER TABLE `cms_menu_item` ADD COLUMN `visible` TINYINT(4) DEFAULT 1 NOT NULL AFTER `open_new_tab`',
    'DO 0'
);

PREPARE cms_menu_item_visible_statement FROM @cms_menu_item_visible_add;

EXECUTE cms_menu_item_visible_statement;

DEALLOCATE PREPARE cms_menu_item_visible_statement;
