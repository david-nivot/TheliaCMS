-- 2.26.0: a video sent to the media library is converted in the background
-- for the web and put back under the same id. `cms_video_conversion` keeps,
-- per video, whether it is processing, ready or failed, why it failed, and
-- the poster image taken from it. The videos there are when this update runs
-- have no row: they were already web files, and read as ready.
--
-- Written to be replayable: the same file is applied by a fresh install (after
-- TheliaMain.sql, which already contains this table) and by an update of an
-- existing site, so every statement tolerates being run twice.

CREATE TABLE IF NOT EXISTS `cms_video_conversion`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `library_image_id` INTEGER NOT NULL,
    `status` VARCHAR(16) DEFAULT 'processing' NOT NULL,
    `poster_image_id` INTEGER,
    `failure` VARCHAR(32),
    `detail` VARCHAR(255),
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `unq_cms_video_conversion_image` (`library_image_id`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;
