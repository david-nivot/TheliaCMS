-- 2.42.1: plays again the carry-over of 2.42.0 (the reasoning effort of the
-- generator handed to the AI settings of Thelia) on a site it never went
-- through. Up to 2.42.0 the values of the module were compared with a utf8mb4
-- collation, which a base that came from Thelia 2 refuses (its
-- `module_config_i18n.value` is utf8mb3, error 1253). A site coming from before
-- 2.41.0 still recorded 2.42.0: the ALTER of 2.41.0 commits on its own, the
-- version of the module with it, so the update to 2.42.0 is never played again.
--
-- The carry-over is pending when a setting of the module is still there, or
-- when none of its usages has an effort yet: 2.42.0 always leaves an effort on
-- each of them. A site it went through is left alone, and what the merchant
-- changed in the core since (an effort emptied, a row removed) is kept.
--
-- Written to be replayable: a fresh install runs it after 2.42.0.sql, which has
-- just done the carry-over, and it changes nothing.

SET @cms_effort_pending := (
    SELECT COUNT(*) FROM `module_config` AS `setting`
        INNER JOIN `module` ON `module`.`id` = `setting`.`module_id`
    WHERE `module`.`code` = 'TheliaCMS' AND `setting`.`name` IN ('generator_effort', 'generator_text_effort')
);

SET @cms_effort_ready_before := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'ai_usage_setting' AND column_name = 'effort'
);

-- Read only when the column exists: the statement is prepared, so that a core
-- without it (before 3.4) is left alone rather than failing.
SET @cms_effort_set_count := IF(
    @cms_effort_ready_before > 0,
    'SELECT COUNT(*) INTO @cms_effort_set FROM `ai_usage_setting` WHERE `usage_id` IN (''theliacms.page_generation'', ''theliacms.page_translation'', ''theliacms.component'', ''theliacms.text'') AND `effort` IS NOT NULL AND `effort` <> ''''',
    'SELECT 1 INTO @cms_effort_set'
);

PREPARE cms_effort_set_count_statement FROM @cms_effort_set_count;

EXECUTE cms_effort_set_count_statement;

DEALLOCATE PREPARE cms_effort_set_count_statement;

SET @cms_effort_pending := IF(@cms_effort_pending > 0 OR @cms_effort_set = 0, 1, 0);

SET @cms_effort_ready := IF(@cms_effort_ready_before > 0 AND @cms_effort_pending > 0, 1, 0);

-- The value read by the module is the one of the default locale of the row,
-- and only an exact level counts (a plain comparison is not case sensitive).
-- The column is converted before it is compared: a base that came from
-- Thelia 2 keeps it in utf8mb3, which refuses a utf8mb4 collation (1253).
SET @cms_effort_page := COALESCE((
    SELECT `value`.`value` FROM `module_config_i18n` AS `value`
        INNER JOIN `module_config` AS `setting` ON `setting`.`id` = `value`.`id`
        INNER JOIN `module` ON `module`.`id` = `setting`.`module_id`
    WHERE `module`.`code` = 'TheliaCMS' AND `setting`.`name` = 'generator_effort' AND `value`.`locale` = 'en_US'
      AND CONVERT(`value`.`value` USING utf8mb4) COLLATE utf8mb4_bin IN ('low', 'medium', 'high', 'xhigh', 'max')
    LIMIT 1
), 'high');

SET @cms_effort_text := COALESCE((
    SELECT `value`.`value` FROM `module_config_i18n` AS `value`
        INNER JOIN `module_config` AS `setting` ON `setting`.`id` = `value`.`id`
        INNER JOIN `module` ON `module`.`id` = `setting`.`module_id`
    WHERE `module`.`code` = 'TheliaCMS' AND `setting`.`name` = 'generator_text_effort' AND `value`.`locale` = 'en_US'
      AND CONVERT(`value`.`value` USING utf8mb4) COLLATE utf8mb4_bin IN ('low', 'medium', 'high', 'xhigh', 'max')
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
