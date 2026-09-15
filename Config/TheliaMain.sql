
# This is a fix for InnoDB in MySQL >= 4.1.x
# It "suspends judgement" for fkey relationships until are tables are set.
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- agent_conversation
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `agent_conversation`;

CREATE TABLE `agent_conversation`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `type` VARCHAR(20) NOT NULL,
    `customer_id` INTEGER,
    `admin_id` INTEGER,
    `session_ref` VARCHAR(255),
    `locale` VARCHAR(10) DEFAULT 'fr_FR',
    `proactive_prompt_count` INTEGER DEFAULT 0 NOT NULL,
    `proactive_last_prompted_at` TIMESTAMP NULL,
    `proactive_dismissed` TINYINT DEFAULT 0 NOT NULL,
    `proactive_scenarios` LONGTEXT,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_agent_conversation_session_ref` (`session_ref`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- agent_message
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `agent_message`;

CREATE TABLE `agent_message`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `conversation_id` INTEGER NOT NULL,
    `role` VARCHAR(20) NOT NULL,
    `content` LONGTEXT,
    `tool_calls` LONGTEXT,
    `tokens_in` INTEGER,
    `tokens_out` INTEGER,
    `model` VARCHAR(120),
    `cost` DECIMAL(14,8),
    `created_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `fi_agent_message_conversation` (`conversation_id`),
    CONSTRAINT `fk_agent_message_conversation`
        FOREIGN KEY (`conversation_id`)
        REFERENCES `agent_conversation` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- agent_staged_change
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `agent_staged_change`;

CREATE TABLE `agent_staged_change`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `conversation_id` INTEGER NOT NULL,
    `agent_definition_id` INTEGER,
    `admin_id` INTEGER NOT NULL,
    `target_type` VARCHAR(20) NOT NULL,
    `target_id` INTEGER NOT NULL,
    `payload_before` LONGTEXT,
    `payload_after` LONGTEXT,
    `status` VARCHAR(20) DEFAULT 'pending' NOT NULL,
    `approved_by` INTEGER,
    `approved_at` TIMESTAMP NULL,
    `applied_at` TIMESTAMP NULL,
    `error` LONGTEXT,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_agent_staged_change_status` (`status`),
    INDEX `idx_agent_staged_change_definition` (`agent_definition_id`),
    INDEX `fi_agent_staged_change_conversation` (`conversation_id`),
    CONSTRAINT `fk_agent_staged_change_conversation`
        FOREIGN KEY (`conversation_id`)
        REFERENCES `agent_conversation` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_agent_staged_change_definition`
        FOREIGN KEY (`agent_definition_id`)
        REFERENCES `agent_definition` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- agent_definition
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `agent_definition`;

CREATE TABLE `agent_definition`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(80) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` LONGTEXT,
    `enabled` TINYINT DEFAULT 0 NOT NULL,
    `role_prompt` LONGTEXT,
    `provider` VARCHAR(40),
    `model` VARCHAR(120),
    `locale` VARCHAR(10) DEFAULT 'fr_FR',
    `max_iterations` INTEGER DEFAULT 5 NOT NULL,
    `monthly_budget_usd` DECIMAL(12,4),
    `auto_apply` TINYINT DEFAULT 0 NOT NULL,
    `consecutive_failures` INTEGER DEFAULT 0 NOT NULL,
    `preset_code` VARCHAR(60),
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_agent_definition_code` (`code`)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- agent_memory
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `agent_memory`;

CREATE TABLE `agent_memory`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `agent_definition_id` INTEGER NOT NULL,
    `content` LONGTEXT NOT NULL,
    `source` VARCHAR(20) DEFAULT 'manual' NOT NULL,
    `enabled` TINYINT DEFAULT 1 NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_agent_memory_definition` (`agent_definition_id`),
    CONSTRAINT `fk_agent_memory_definition`
        FOREIGN KEY (`agent_definition_id`)
        REFERENCES `agent_definition` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- agent_capability
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `agent_capability`;

CREATE TABLE `agent_capability`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `agent_definition_id` INTEGER NOT NULL,
    `capability` VARCHAR(60) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_agent_capability_definition_capability` (`agent_definition_id`, `capability`),
    CONSTRAINT `fk_agent_capability_definition`
        FOREIGN KEY (`agent_definition_id`)
        REFERENCES `agent_definition` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- agent_trigger
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `agent_trigger`;

CREATE TABLE `agent_trigger`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `agent_definition_id` INTEGER NOT NULL,
    `type` VARCHAR(20) NOT NULL,
    `event_name` VARCHAR(255),
    `cron_expression` VARCHAR(120),
    `conditions` LONGTEXT,
    `enabled` TINYINT DEFAULT 1 NOT NULL,
    `last_run_at` TIMESTAMP NULL,
    `next_run_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `fi_agent_trigger_definition` (`agent_definition_id`),
    CONSTRAINT `fk_agent_trigger_definition`
        FOREIGN KEY (`agent_definition_id`)
        REFERENCES `agent_definition` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- agent_channel
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `agent_channel`;

CREATE TABLE `agent_channel`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `agent_definition_id` INTEGER NOT NULL,
    `connector_code` VARCHAR(40) NOT NULL,
    `settings` LONGTEXT,
    `mode` VARCHAR(10) DEFAULT 'draft' NOT NULL,
    `enabled` TINYINT DEFAULT 1 NOT NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `fi_agent_channel_definition` (`agent_definition_id`),
    CONSTRAINT `fk_agent_channel_definition`
        FOREIGN KEY (`agent_definition_id`)
        REFERENCES `agent_definition` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- agent_run
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `agent_run`;

CREATE TABLE `agent_run`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `agent_definition_id` INTEGER NOT NULL,
    `agent_trigger_id` INTEGER,
    `conversation_id` INTEGER,
    `status` VARCHAR(20) DEFAULT 'queued' NOT NULL,
    `dedup_key` VARCHAR(191),
    `context` LONGTEXT,
    `summary` LONGTEXT,
    `error` LONGTEXT,
    `started_at` TIMESTAMP NULL,
    `finished_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_agent_run_definition_dedup` (`agent_definition_id`, `dedup_key`),
    INDEX `idx_agent_run_status` (`status`),
    INDEX `fi_agent_run_trigger` (`agent_trigger_id`),
    INDEX `fi_agent_run_conversation` (`conversation_id`),
    CONSTRAINT `fk_agent_run_definition`
        FOREIGN KEY (`agent_definition_id`)
        REFERENCES `agent_definition` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_agent_run_trigger`
        FOREIGN KEY (`agent_trigger_id`)
        REFERENCES `agent_trigger` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `fk_agent_run_conversation`
        FOREIGN KEY (`conversation_id`)
        REFERENCES `agent_conversation` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- agent_model
-- ---------------------------------------------------------------------

DROP TABLE IF EXISTS `agent_model`;

CREATE TABLE `agent_model`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `provider` VARCHAR(40) NOT NULL,
    `model_id` VARCHAR(120) NOT NULL,
    `name` VARCHAR(150),
    `price_input` DECIMAL(12,6),
    `price_output` DECIMAL(12,6),
    `context_window` INTEGER,
    `tier` VARCHAR(20),
    `currency` VARCHAR(3),
    `enabled` TINYINT DEFAULT 1 NOT NULL,
    `source` VARCHAR(20) DEFAULT 'catalog' NOT NULL,
    `priced_at` DATE,
    `last_seen_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_agent_model_provider_model` (`provider`, `model_id`)
) ENGINE=InnoDB;

# This restores the fkey checks, after having unset them earlier
SET FOREIGN_KEY_CHECKS = 1;
