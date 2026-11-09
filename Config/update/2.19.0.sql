-- 2.19.0: an answer to a form carries a status, new, handled or archived, so
-- the team sees what is still to answer. Every answer there is when this
-- update runs starts as new.
--
-- Written to be replayable: a fresh install runs it after TheliaMain.sql, which
-- already has the column. `ADD COLUMN IF NOT EXISTS` is not used: MariaDB
-- understands it and MySQL does not.

SET @cms_form_submission_status_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'cms_form_submission' AND column_name = 'status'
);

SET @cms_form_submission_status_add := IF(
    0 = @cms_form_submission_status_exists,
    'ALTER TABLE `cms_form_submission` ADD COLUMN `status` VARCHAR(16) DEFAULT ''new'' NOT NULL AFTER `ip_hash`',
    'DO 0'
);

PREPARE cms_form_submission_status_statement FROM @cms_form_submission_status_add;

EXECUTE cms_form_submission_status_statement;

DEALLOCATE PREPARE cms_form_submission_status_statement;
