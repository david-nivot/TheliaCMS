-- 2.40.0: a component of the site says which call its version came from,
-- written when a version is kept, so the history marks the one in place. A
-- comfort only: a call deleted leaves the column empty, and the components
-- kept before this update have none until their next version is kept.
--
-- Written to be replayable: a fresh install runs it after TheliaMain.sql, which
-- already has the column, the index and the key. `ADD COLUMN IF NOT EXISTS` is
-- not used: MariaDB understands it and MySQL does not.

SET @cms_component_generation_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_component' AND column_name = 'generation_id'
);

SET @cms_component_generation_add := IF(
    0 = @cms_component_generation_exists,
    'ALTER TABLE `cms_component` ADD COLUMN `generation_id` INTEGER NULL AFTER `status`',
    'DO 0'
);

PREPARE cms_component_generation_statement FROM @cms_component_generation_add;

EXECUTE cms_component_generation_statement;

DEALLOCATE PREPARE cms_component_generation_statement;

SET @cms_component_generation_index_exists := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'cms_component' AND index_name = 'idx_cms_component_generation'
);

SET @cms_component_generation_index_add := IF(
    0 = @cms_component_generation_index_exists,
    'ALTER TABLE `cms_component` ADD INDEX `idx_cms_component_generation` (`generation_id`)',
    'DO 0'
);

PREPARE cms_component_generation_index_statement FROM @cms_component_generation_index_add;

EXECUTE cms_component_generation_index_statement;

DEALLOCATE PREPARE cms_component_generation_index_statement;

SET @cms_component_generation_key_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = DATABASE() AND table_name = 'cms_component' AND constraint_name = 'fk_cms_component_generation' AND constraint_type = 'FOREIGN KEY'
);

SET @cms_component_generation_key_add := IF(
    0 = @cms_component_generation_key_exists,
    'ALTER TABLE `cms_component` ADD CONSTRAINT `fk_cms_component_generation` FOREIGN KEY (`generation_id`) REFERENCES `cms_page_generation` (`id`) ON UPDATE RESTRICT ON DELETE SET NULL',
    'DO 0'
);

PREPARE cms_component_generation_key_statement FROM @cms_component_generation_key_add;

EXECUTE cms_component_generation_key_statement;

DEALLOCATE PREPARE cms_component_generation_key_statement;
