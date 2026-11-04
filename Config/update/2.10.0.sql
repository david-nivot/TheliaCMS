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

-- The default output limit of the generator goes from 16000 to 48000 tokens
-- (docs/adr/0008). The settings screen writes the limit on every save, so a
-- site that saved it once still holds the former default and would never see
-- the new one: that exact value is raised, any other is the choice of the site
-- and is kept.
UPDATE `module_config_i18n` AS `value`
    INNER JOIN `module_config` AS `setting` ON `setting`.`id` = `value`.`id`
    INNER JOIN `module` ON `module`.`id` = `setting`.`module_id`
SET `value`.`value` = '48000'
WHERE `module`.`code` = 'TheliaCMS'
  AND `setting`.`name` = 'generator_max_tokens'
  AND `value`.`value` = '16000';

-- The ledger of the generator keeps what a call needed beyond its outcome: the
-- effort asked of the model, the corrections and the cut answers asked again
-- (`retries`), and the tools it called with their counts (a JSON object). A
-- call is now written from its start, with the status `running`, and a call
-- not touched for 30 minutes is read as `interrupted`: both are values of the
-- existing `status` column, nothing to alter.
--
-- Written to be replayable: a fresh install runs it after TheliaMain.sql, which
-- already has the columns. `ADD COLUMN IF NOT EXISTS` is not used: MariaDB
-- understands it and MySQL does not.

SET @cms_page_generation_effort_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_page_generation' AND column_name = 'effort'
);

SET @cms_page_generation_effort_add := IF(
    0 = @cms_page_generation_effort_exists,
    'ALTER TABLE `cms_page_generation` ADD COLUMN `effort` VARCHAR(20) DEFAULT NULL AFTER `left_out`',
    'DO 0'
);

PREPARE cms_page_generation_effort_statement FROM @cms_page_generation_effort_add;

EXECUTE cms_page_generation_effort_statement;

DEALLOCATE PREPARE cms_page_generation_effort_statement;

SET @cms_page_generation_corrections_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_page_generation' AND column_name = 'corrections'
);

SET @cms_page_generation_corrections_add := IF(
    0 = @cms_page_generation_corrections_exists,
    'ALTER TABLE `cms_page_generation` ADD COLUMN `corrections` INTEGER DEFAULT 0 NOT NULL AFTER `effort`',
    'DO 0'
);

PREPARE cms_page_generation_corrections_statement FROM @cms_page_generation_corrections_add;

EXECUTE cms_page_generation_corrections_statement;

DEALLOCATE PREPARE cms_page_generation_corrections_statement;

SET @cms_page_generation_retries_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_page_generation' AND column_name = 'retries'
);

SET @cms_page_generation_retries_add := IF(
    0 = @cms_page_generation_retries_exists,
    'ALTER TABLE `cms_page_generation` ADD COLUMN `retries` INTEGER DEFAULT 0 NOT NULL AFTER `corrections`',
    'DO 0'
);

PREPARE cms_page_generation_retries_statement FROM @cms_page_generation_retries_add;

EXECUTE cms_page_generation_retries_statement;

DEALLOCATE PREPARE cms_page_generation_retries_statement;

SET @cms_page_generation_tools_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_page_generation' AND column_name = 'tools'
);

SET @cms_page_generation_tools_add := IF(
    0 = @cms_page_generation_tools_exists,
    'ALTER TABLE `cms_page_generation` ADD COLUMN `tools` LONGTEXT DEFAULT NULL AFTER `retries`',
    'DO 0'
);

PREPARE cms_page_generation_tools_statement FROM @cms_page_generation_tools_add;

EXECUTE cms_page_generation_tools_statement;

DEALLOCATE PREPARE cms_page_generation_tools_statement;
