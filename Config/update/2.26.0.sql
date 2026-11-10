-- 2.26.0: the SVGs of the media library can be named as icons. `cms_icon` holds
-- the name (without the prefix: `document-valide` is drawn by
-- `cms:document-valide`), one per image and one image per name.
--
-- No foreign key to `library_image`: that table belongs to TheliaLibrary and
-- nothing guarantees it exists when this update runs. A row whose image is gone
-- is ignored when read.
--
-- Written to be replayable: a fresh install runs it after TheliaMain.sql, which
-- already has the table.

CREATE TABLE IF NOT EXISTS `cms_icon`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(64) NOT NULL,
    `image_id` INTEGER NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `unq_cms_icon_name` (`name`),
    UNIQUE INDEX `unq_cms_icon_image` (`image_id`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;
