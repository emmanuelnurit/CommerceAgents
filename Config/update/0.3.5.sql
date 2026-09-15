SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `agent_action_log`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `tool_name` VARCHAR(120) NOT NULL,
    `capability` VARCHAR(60),
    `channel` VARCHAR(20) DEFAULT 'chat' NOT NULL,
    `status` VARCHAR(20) NOT NULL,
    `conversation_id` INTEGER,
    `agent_definition_id` INTEGER,
    `admin_id` INTEGER,
    `customer_id` INTEGER,
    `arguments` LONGTEXT,
    `error` LONGTEXT,
    `created_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_agent_action_log_tool_name` (`tool_name`),
    INDEX `idx_agent_action_log_definition` (`agent_definition_id`),
    INDEX `fi_agent_action_log_conversation` (`conversation_id`),
    CONSTRAINT `fk_agent_action_log_conversation`
        FOREIGN KEY (`conversation_id`)
        REFERENCES `agent_conversation` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `fk_agent_action_log_definition`
        FOREIGN KEY (`agent_definition_id`)
        REFERENCES `agent_definition` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
