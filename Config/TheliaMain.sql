
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
    INDEX `fi_agent_staged_change_conversation` (`conversation_id`),
    CONSTRAINT `fk_agent_staged_change_conversation`
        FOREIGN KEY (`conversation_id`)
        REFERENCES `agent_conversation` (`id`)
        ON DELETE CASCADE
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
