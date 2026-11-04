-- 2.10.0: the counters of the public forms. Messages per sender and form,
-- copies per destination address and stamps already spent, each counted in one
-- statement against a primary key (TheliaCMS\Form\Throttle), which the core
-- `form_firewall` table, with no unique key, could not do.
--
-- The rows the module kept in `form_firewall`, under the `cms_form_` prefix,
-- are no longer read: they go.
--
-- Written to be replayable: a fresh install runs it after TheliaMain.sql,
-- which already declares the table.

CREATE TABLE IF NOT EXISTS `cms_form_throttle`
(
    `scope` VARCHAR(16) NOT NULL,
    `subject` CHAR(64) NOT NULL,
    `hits` INTEGER DEFAULT 0 NOT NULL,
    `expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`scope`,`subject`),
    INDEX `idx_cms_form_throttle_expires_at` (`expires_at`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

DELETE FROM `form_firewall` WHERE `form_name` LIKE 'cms\_form\_%';
