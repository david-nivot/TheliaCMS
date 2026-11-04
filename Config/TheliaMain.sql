
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- cms_page
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_page`;

CREATE TABLE `cms_page`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `parent` INTEGER DEFAULT 0 NOT NULL,
    `position` INTEGER DEFAULT 0 NOT NULL,
    `visible` TINYINT(4) DEFAULT 1 NOT NULL,
    `page_type` VARCHAR(50) DEFAULT 'default' NOT NULL,
    `model` TINYINT(4) DEFAULT 0 NOT NULL,
    `fields` LONGTEXT,
    `image_id` INTEGER,
    `tag_id` INTEGER,
    `publish_at` TIMESTAMP NULL,
    `unpublish_at` TIMESTAMP NULL,
    `deleted_at` TIMESTAMP NULL,
    `created_by` INTEGER,
    `updated_by` INTEGER,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_cms_page_parent` (`parent`),
    INDEX `idx_cms_page_parent_position` (`parent`, `position`),
    INDEX `idx_cms_page_deleted_at` (`deleted_at`),
    INDEX `idx_cms_page_tag` (`tag_id`),
    INDEX `idx_cms_page_page_type` (`page_type`),
    CONSTRAINT `fk_cms_page_tag`
        FOREIGN KEY (`tag_id`)
        REFERENCES `cms_tag` (`id`)
        ON UPDATE RESTRICT
        ON DELETE SET NULL
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_page_content
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_page_content`;

CREATE TABLE `cms_page_content`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `page_id` INTEGER NOT NULL,
    `locale` VARCHAR(5) NOT NULL,
    `draft_document` LONGTEXT,
    `draft_html` LONGTEXT,
    `published_document` LONGTEXT,
    `published_html` LONGTEXT,
    `published_at` TIMESTAMP NULL,
    `draft_updated_by` INTEGER,
    `draft_provenance` LONGTEXT,
    `published_provenance` LONGTEXT,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `unq_cms_page_content_page_locale` (`page_id`, `locale`),
    CONSTRAINT `fk_cms_page_content_page`
        FOREIGN KEY (`page_id`)
        REFERENCES `cms_page` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_page_revision
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_page_revision`;

CREATE TABLE `cms_page_revision`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `page_id` INTEGER NOT NULL,
    `locale` VARCHAR(5) NOT NULL,
    `document` LONGTEXT,
    `html` LONGTEXT,
    `provenance` LONGTEXT,
    `label` VARCHAR(60),
    `created_by` INTEGER,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_cms_page_revision_page_locale` (`page_id`, `locale`),
    CONSTRAINT `fk_cms_page_revision_page`
        FOREIGN KEY (`page_id`)
        REFERENCES `cms_page` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_page_search
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_page_search`;

CREATE TABLE `cms_page_search`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `page_id` INTEGER NOT NULL,
    `locale` VARCHAR(5) NOT NULL,
    `content` LONGTEXT,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `unq_cms_page_search_page_locale` (`page_id`, `locale`),
    CONSTRAINT `fk_cms_page_search_page`
        FOREIGN KEY (`page_id`)
        REFERENCES `cms_page` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_item_content
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_item_content`;

CREATE TABLE `cms_item_content`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `item_type` VARCHAR(16) NOT NULL,
    `item_id` INTEGER NOT NULL,
    `locale` VARCHAR(5) NOT NULL,
    `draft_document` LONGTEXT,
    `draft_html` LONGTEXT,
    `published_document` LONGTEXT,
    `published_html` LONGTEXT,
    `published_at` TIMESTAMP NULL,
    `draft_updated_by` INTEGER,
    `draft_provenance` LONGTEXT,
    `published_provenance` LONGTEXT,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `unq_cms_item_content_item_locale` (`item_type`, `item_id`, `locale`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_item_revision
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_item_revision`;

CREATE TABLE `cms_item_revision`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `item_type` VARCHAR(16) NOT NULL,
    `item_id` INTEGER NOT NULL,
    `locale` VARCHAR(5) NOT NULL,
    `document` LONGTEXT,
    `html` LONGTEXT,
    `provenance` LONGTEXT,
    `label` VARCHAR(60),
    `created_by` INTEGER,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_cms_item_revision_item_locale` (`item_type`, `item_id`, `locale`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;


-- ---------------------------------------------------------------------
-- cms_menu
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_menu`;

CREATE TABLE `cms_menu`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `menu_locale` VARCHAR(5) DEFAULT '' NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `unq_cms_menu_code_locale` (`code`, `menu_locale`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_menu_item
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_menu_item`;

CREATE TABLE `cms_menu_item`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `menu_id` INTEGER NOT NULL,
    `parent` INTEGER DEFAULT 0 NOT NULL,
    `position` INTEGER DEFAULT 0 NOT NULL,
    `target_type` VARCHAR(20) DEFAULT 'page' NOT NULL,
    `target_id` INTEGER,
    `url` VARCHAR(2048),
    `open_new_tab` TINYINT(4) DEFAULT 0 NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_cms_menu_item_menu_parent_position` (`menu_id`, `parent`, `position`),
    INDEX `idx_cms_menu_item_target` (`target_type`, `target_id`),
    CONSTRAINT `fk_cms_menu_item_menu`
        FOREIGN KEY (`menu_id`)
        REFERENCES `cms_menu` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_form
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_form`;

CREATE TABLE `cms_form`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `active` TINYINT(4) DEFAULT 1 NOT NULL,
    `recipients` VARCHAR(1024),
    `store_submissions` TINYINT(4) DEFAULT 1 NOT NULL,
    `retention_days` INTEGER DEFAULT 365 NOT NULL,
    `send_receipt` TINYINT(4) DEFAULT 0 NOT NULL,
    `privacy_policy_page_id` INTEGER,
    `lead_event` TINYINT(4) DEFAULT 1 NOT NULL,
    `deleted_at` TIMESTAMP NULL,
    `created_by` INTEGER,
    `updated_by` INTEGER,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `unq_cms_form_code` (`code`),
    INDEX `idx_cms_form_deleted_at` (`deleted_at`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_form_field
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_form_field`;

CREATE TABLE `cms_form_field`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `form_id` INTEGER NOT NULL,
    `position` INTEGER DEFAULT 0 NOT NULL,
    `type` VARCHAR(20) DEFAULT 'text' NOT NULL,
    `name` VARCHAR(50) NOT NULL,
    `required` TINYINT(4) DEFAULT 0 NOT NULL,
    `options` LONGTEXT,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `unq_cms_form_field_form_name` (`form_id`, `name`),
    INDEX `idx_cms_form_field_form_position` (`form_id`, `position`),
    CONSTRAINT `fk_cms_form_field_form`
        FOREIGN KEY (`form_id`)
        REFERENCES `cms_form` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_form_submission
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_form_submission`;

CREATE TABLE `cms_form_submission`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `form_id` INTEGER NOT NULL,
    `locale` VARCHAR(5) NOT NULL,
    `email` VARCHAR(255),
    `data` LONGTEXT,
    `ip_hash` VARCHAR(64),
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_cms_form_submission_form_created` (`form_id`, `created_at`),
    INDEX `idx_cms_form_submission_email` (`email`),
    CONSTRAINT `fk_cms_form_submission_form`
        FOREIGN KEY (`form_id`)
        REFERENCES `cms_form` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_form_throttle
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_form_throttle`;

CREATE TABLE `cms_form_throttle`
(
    `scope` VARCHAR(16) NOT NULL,
    `subject` CHAR(64) NOT NULL,
    `hits` INTEGER DEFAULT 0 NOT NULL,
    `expires_at` DATETIME NOT NULL,
    PRIMARY KEY (`scope`,`subject`),
    INDEX `idx_cms_form_throttle_expires_at` (`expires_at`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_script
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_script`;

CREATE TABLE `cms_script`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(100) NOT NULL,
    `placement` VARCHAR(20) DEFAULT 'head' NOT NULL,
    `consent_category` VARCHAR(50),
    `active` TINYINT(4) DEFAULT 0 NOT NULL,
    `content` LONGTEXT,
    `note` VARCHAR(500),
    `created_by` INTEGER,
    `updated_by` INTEGER,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_cms_script_active_placement` (`active`, `placement`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_page_document
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_page_document`;

CREATE TABLE `cms_page_document`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `page_id` INTEGER NOT NULL,
    `file` VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(127) NOT NULL,
    `size` INTEGER NOT NULL,
    `position` INTEGER DEFAULT 0 NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `unq_cms_page_document_file` (`file`),
    INDEX `idx_cms_page_document_page_position` (`page_id`, `position`),
    CONSTRAINT `fk_cms_page_document_page`
        FOREIGN KEY (`page_id`)
        REFERENCES `cms_page` (`id`)
        ON UPDATE RESTRICT
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_page_i18n
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_page_i18n`;

CREATE TABLE `cms_page_i18n`
(
    `id` INTEGER NOT NULL,
    `locale` VARCHAR(5) DEFAULT 'en_US' NOT NULL,
    `title` VARCHAR(255),
    `slug` VARCHAR(255),
    `fixed_url` VARCHAR(255),
    `chapo` TEXT,
    `description` LONGTEXT,
    `meta_title` VARCHAR(255),
    `meta_description` TEXT,
    `og_title` VARCHAR(255),
    `og_description` TEXT,
    `og_image_id` INTEGER,
    `twitter_card` VARCHAR(20),
    `canonical` VARCHAR(2048),
    `noindex` TINYINT(4) DEFAULT 0 NOT NULL,
    `nofollow` TINYINT(4) DEFAULT 0 NOT NULL,
    `translated_fields` LONGTEXT,
    PRIMARY KEY (`id`,`locale`),
    CONSTRAINT `cms_page_i18n_fk_058391`
        FOREIGN KEY (`id`)
        REFERENCES `cms_page` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_menu_i18n
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_menu_i18n`;

CREATE TABLE `cms_menu_i18n`
(
    `id` INTEGER NOT NULL,
    `locale` VARCHAR(5) DEFAULT 'en_US' NOT NULL,
    `title` VARCHAR(255),
    PRIMARY KEY (`id`,`locale`),
    CONSTRAINT `cms_menu_i18n_fk_05b691`
        FOREIGN KEY (`id`)
        REFERENCES `cms_menu` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_menu_item_i18n
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_menu_item_i18n`;

CREATE TABLE `cms_menu_item_i18n`
(
    `id` INTEGER NOT NULL,
    `locale` VARCHAR(5) DEFAULT 'en_US' NOT NULL,
    `label` VARCHAR(255),
    PRIMARY KEY (`id`,`locale`),
    CONSTRAINT `cms_menu_item_i18n_fk_ea832f`
        FOREIGN KEY (`id`)
        REFERENCES `cms_menu_item` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_form_i18n
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_form_i18n`;

CREATE TABLE `cms_form_i18n`
(
    `id` INTEGER NOT NULL,
    `locale` VARCHAR(5) DEFAULT 'en_US' NOT NULL,
    `title` VARCHAR(255),
    `description` TEXT,
    `submit_label` VARCHAR(80),
    `success_message` TEXT,
    `legal_notice` TEXT,
    `receipt_subject` VARCHAR(255),
    `receipt_body` TEXT,
    PRIMARY KEY (`id`,`locale`),
    CONSTRAINT `cms_form_i18n_fk_42eeb1`
        FOREIGN KEY (`id`)
        REFERENCES `cms_form` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_form_field_i18n
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_form_field_i18n`;

CREATE TABLE `cms_form_field_i18n`
(
    `id` INTEGER NOT NULL,
    `locale` VARCHAR(5) DEFAULT 'en_US' NOT NULL,
    `label` VARCHAR(255),
    `placeholder` VARCHAR(255),
    `help` TEXT,
    `choices` TEXT,
    PRIMARY KEY (`id`,`locale`),
    CONSTRAINT `cms_form_field_i18n_fk_0aecbb`
        FOREIGN KEY (`id`)
        REFERENCES `cms_form_field` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_page_type
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_page_type`;

CREATE TABLE `cms_page_type`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `brief` LONGTEXT,
    `brief_tone` VARCHAR(200),
    `brief_audience` VARCHAR(200),
    `instructions` LONGTEXT,
    `fields` LONGTEXT,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `unq_cms_page_type_code` (`code`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_tag
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_tag`;

CREATE TABLE `cms_tag`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(100) NOT NULL,
    `color` VARCHAR(16),
    `text_color` VARCHAR(16),
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `unq_cms_tag_name` (`name`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_component
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_component`;

CREATE TABLE `cms_component`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(40) NOT NULL,
    `label` VARCHAR(120) NOT NULL,
    `description` VARCHAR(500) NOT NULL,
    `props` LONGTEXT,
    `sample` LONGTEXT,
    `template` LONGTEXT,
    `css` LONGTEXT,
    `provenance` LONGTEXT,
    `status` VARCHAR(20) DEFAULT 'draft' NOT NULL,
    `created_by` INTEGER,
    `updated_by` INTEGER,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `unq_cms_component_code` (`code`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_skill
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_skill`;

CREATE TABLE `cms_skill`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(50) NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `description` VARCHAR(500),
    `body` LONGTEXT,
    `shipped_body` LONGTEXT,
    `origin` VARCHAR(20) DEFAULT 'module' NOT NULL,
    `position` INTEGER DEFAULT 0 NOT NULL,
    `enabled` TINYINT DEFAULT 1 NOT NULL,
    `always` TINYINT DEFAULT 0 NOT NULL,
    `updated_by` INTEGER,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `unq_cms_skill_code` (`code`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_page_generation
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_page_generation`;

CREATE TABLE `cms_page_generation`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `target_type` VARCHAR(20) DEFAULT 'page' NOT NULL,
    `target_id` INTEGER NOT NULL,
    `locale` VARCHAR(5) NOT NULL,
    `kind` VARCHAR(20) DEFAULT 'brief' NOT NULL,
    `prompt` LONGTEXT,
    `brief` LONGTEXT,
    `model` VARCHAR(60),
    `request_id` VARCHAR(80),
    `log_id` INTEGER,
    `status` VARCHAR(20) DEFAULT 'done' NOT NULL,
    `stop_reason` VARCHAR(30),
    `stop_category` VARCHAR(60),
    `input_tokens` INTEGER DEFAULT 0 NOT NULL,
    `output_tokens` INTEGER DEFAULT 0 NOT NULL,
    `cache_read_tokens` INTEGER DEFAULT 0 NOT NULL,
    `cache_write_tokens` INTEGER DEFAULT 0 NOT NULL,
    `turns` INTEGER DEFAULT 0 NOT NULL,
    `cost_usd` DECIMAL(12,6),
    `duration_ms` INTEGER DEFAULT 0 NOT NULL,
    `error` TEXT,
    `document` LONGTEXT,
    `provenance` LONGTEXT,
    `web_pages` LONGTEXT,
    `left_out` LONGTEXT,
    `created_by` INTEGER,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_cms_page_generation_target` (`target_type`, `target_id`, `locale`),
    INDEX `idx_cms_page_generation_log` (`log_id`)
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_page_type_i18n
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_page_type_i18n`;

CREATE TABLE `cms_page_type_i18n`
(
    `id` INTEGER NOT NULL,
    `locale` VARCHAR(5) DEFAULT 'en_US' NOT NULL,
    `title` VARCHAR(255),
    `description` TEXT,
    PRIMARY KEY (`id`,`locale`),
    CONSTRAINT `cms_page_type_i18n_fk_7b1d2a`
        FOREIGN KEY (`id`)
        REFERENCES `cms_page_type` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

-- ---------------------------------------------------------------------
-- cms_page_document_i18n
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `cms_page_document_i18n`;

CREATE TABLE `cms_page_document_i18n`
(
    `id` INTEGER NOT NULL,
    `locale` VARCHAR(5) DEFAULT 'en_US' NOT NULL,
    `title` VARCHAR(255),
    `description` TEXT,
    PRIMARY KEY (`id`,`locale`),
    CONSTRAINT `cms_page_document_i18n_fk_44bfb8`
        FOREIGN KEY (`id`)
        REFERENCES `cms_page_document` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB CHARACTER SET='utf8mb4' COLLATE='utf8mb4_general_ci' ROW_FORMAT=DYNAMIC;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
