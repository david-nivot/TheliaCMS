-- 2.42.0: the reasoning effort of the generator is no longer a setting of the
-- module: Thelia 3.4 holds it, per range of model and per usage (AI settings of
-- Thelia). So that no site changes cost or behaviour, the value the module
-- sent is carried to the effort of each usage it called:
--
--   theliacms.page_generation, theliacms.page_translation, theliacms.component
--       the `generator_effort` setting, `high` (the former default) when it
--       is missing or not a level
--   theliacms.text
--       the `generator_text_effort` setting, `low` (the former default) when
--       it is missing or not a level
--
-- A usage that already has an effort keeps it: the one a merchant set in the
-- core wins. A usage with no row of settings gets a row holding that effort and
-- nothing else, which the core reads as it reads no row (every other column
-- is at its default). Then the two settings of the module go.
--
-- Written to be replayable: a fresh install runs it after TheliaMain.sql, with
-- no setting of the module, and writes the two defaults. The statements on
-- `ai_usage_setting` are prepared so that a site whose core has no `effort`
-- column yet (before 3.4) is left alone rather than failing.

SET @cms_effort_ready := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ai_usage_setting' AND column_name = 'effort'
);

-- The value read by the module is the one of the default locale of the row,
-- and only an exact level counts (a plain comparison is not case sensitive).
SET @cms_effort_page := COALESCE((
    SELECT `value`.`value` FROM `module_config_i18n` AS `value`
        INNER JOIN `module_config` AS `setting` ON `setting`.`id` = `value`.`id`
        INNER JOIN `module` ON `module`.`id` = `setting`.`module_id`
    WHERE `module`.`code` = 'TheliaCMS' AND `setting`.`name` = 'generator_effort' AND `value`.`locale` = 'en_US'
      AND `value`.`value` COLLATE utf8mb4_bin IN ('low', 'medium', 'high', 'xhigh', 'max')
    LIMIT 1
), 'high');

SET @cms_effort_text := COALESCE((
    SELECT `value`.`value` FROM `module_config_i18n` AS `value`
        INNER JOIN `module_config` AS `setting` ON `setting`.`id` = `value`.`id`
        INNER JOIN `module` ON `module`.`id` = `setting`.`module_id`
    WHERE `module`.`code` = 'TheliaCMS' AND `setting`.`name` = 'generator_text_effort' AND `value`.`locale` = 'en_US'
      AND `value`.`value` COLLATE utf8mb4_bin IN ('low', 'medium', 'high', 'xhigh', 'max')
    LIMIT 1
), 'low');

-- Usages that have a row of settings and no effort yet.
SET @cms_effort_fill := IF(
    @cms_effort_ready > 0,
    'UPDATE `ai_usage_setting` SET `effort` = IF(`usage_id` = ''theliacms.text'', @cms_effort_text, @cms_effort_page), `updated_at` = NOW() WHERE `usage_id` IN (''theliacms.page_generation'', ''theliacms.page_translation'', ''theliacms.component'', ''theliacms.text'') AND (`effort` IS NULL OR `effort` = '''')',
    'DO 0'
);

PREPARE cms_effort_fill_statement FROM @cms_effort_fill;

EXECUTE cms_effort_fill_statement;

DEALLOCATE PREPARE cms_effort_fill_statement;

-- Usages with no row of settings, one statement each: a literal and a user
-- variable of the session do not mix in a UNION (collations).
SET @cms_effort_insert_page_generation := IF(
    @cms_effort_ready > 0,
    'INSERT INTO `ai_usage_setting` (`usage_id`, `effort`, `created_at`, `updated_at`) SELECT ''theliacms.page_generation'', @cms_effort_page, NOW(), NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `ai_usage_setting` WHERE `usage_id` = ''theliacms.page_generation'')',
    'DO 0'
);

PREPARE cms_effort_insert_page_generation_statement FROM @cms_effort_insert_page_generation;

EXECUTE cms_effort_insert_page_generation_statement;

DEALLOCATE PREPARE cms_effort_insert_page_generation_statement;

SET @cms_effort_insert_page_translation := IF(
    @cms_effort_ready > 0,
    'INSERT INTO `ai_usage_setting` (`usage_id`, `effort`, `created_at`, `updated_at`) SELECT ''theliacms.page_translation'', @cms_effort_page, NOW(), NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `ai_usage_setting` WHERE `usage_id` = ''theliacms.page_translation'')',
    'DO 0'
);

PREPARE cms_effort_insert_page_translation_statement FROM @cms_effort_insert_page_translation;

EXECUTE cms_effort_insert_page_translation_statement;

DEALLOCATE PREPARE cms_effort_insert_page_translation_statement;

SET @cms_effort_insert_component := IF(
    @cms_effort_ready > 0,
    'INSERT INTO `ai_usage_setting` (`usage_id`, `effort`, `created_at`, `updated_at`) SELECT ''theliacms.component'', @cms_effort_page, NOW(), NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `ai_usage_setting` WHERE `usage_id` = ''theliacms.component'')',
    'DO 0'
);

PREPARE cms_effort_insert_component_statement FROM @cms_effort_insert_component;

EXECUTE cms_effort_insert_component_statement;

DEALLOCATE PREPARE cms_effort_insert_component_statement;

SET @cms_effort_insert_text := IF(
    @cms_effort_ready > 0,
    'INSERT INTO `ai_usage_setting` (`usage_id`, `effort`, `created_at`, `updated_at`) SELECT ''theliacms.text'', @cms_effort_text, NOW(), NOW() FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `ai_usage_setting` WHERE `usage_id` = ''theliacms.text'')',
    'DO 0'
);

PREPARE cms_effort_insert_text_statement FROM @cms_effort_insert_text;

EXECUTE cms_effort_insert_text_statement;

DEALLOCATE PREPARE cms_effort_insert_text_statement;

-- The values of a setting live in `module_config_i18n`, which goes with its row.
-- Kept while the core cannot hold them yet, so that they are not lost.
SET @cms_effort_forget := IF(
    @cms_effort_ready > 0,
    'DELETE FROM `module_config` WHERE `name` IN (''generator_effort'', ''generator_text_effort'') AND `module_id` IN (SELECT `id` FROM `module` WHERE `code` = ''TheliaCMS'')',
    'DO 0'
);

PREPARE cms_effort_forget_statement FROM @cms_effort_forget;

EXECUTE cms_effort_forget_statement;

DEALLOCATE PREPARE cms_effort_forget_statement;
