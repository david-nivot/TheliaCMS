-- 2.16.0: the list of pages filters on who changed a page last
-- (`cms_page.updated_by`), which the module now writes on every change of a
-- page or of its content. Before, a content save left it empty: a page whose
-- column is empty takes the author of its latest draft, in whatever language
-- (`cms_page_content.draft_updated_by` of the content changed last, the
-- highest id between two changed at the same second). `created_by` stays as
-- it is: nothing tells who created a page it does not name.
--
-- Written to be replayable: it only fills what is empty, and a fresh install,
-- which runs it after TheliaMain.sql, has no page yet.

UPDATE `cms_page`
JOIN (
    SELECT `content`.`page_id`, `content`.`draft_updated_by`
    FROM `cms_page_content` `content`
    WHERE `content`.`draft_updated_by` IS NOT NULL
      AND NOT EXISTS (
          SELECT 1 FROM `cms_page_content` `later`
          WHERE `later`.`page_id` = `content`.`page_id`
            AND `later`.`draft_updated_by` IS NOT NULL
            AND (
                COALESCE(`later`.`updated_at`, '1000-01-01') > COALESCE(`content`.`updated_at`, '1000-01-01')
                OR (COALESCE(`later`.`updated_at`, '1000-01-01') = COALESCE(`content`.`updated_at`, '1000-01-01') AND `later`.`id` > `content`.`id`)
            )
      )
) `latest` ON `latest`.`page_id` = `cms_page`.`id`
SET `cms_page`.`updated_by` = `latest`.`draft_updated_by`
WHERE `cms_page`.`updated_by` IS NULL;
