SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `agent_definition`
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
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_agent_definition_code` (`code`)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `agent_capability`
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

CREATE TABLE IF NOT EXISTS `agent_trigger`
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

CREATE TABLE IF NOT EXISTS `agent_channel`
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

CREATE TABLE IF NOT EXISTS `agent_run`
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

SET FOREIGN_KEY_CHECKS = 1;
