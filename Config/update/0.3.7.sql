SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `agent_outbound_message`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `agent_run_id` INTEGER NOT NULL,
    `agent_definition_id` INTEGER NOT NULL,
    `channel` VARCHAR(40) NOT NULL,
    `recipient` VARCHAR(255),
    `status` VARCHAR(20) NOT NULL,
    `business_reference` VARCHAR(255),
    `error` LONGTEXT,
    `sent_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_agent_outbound_message_definition` (`agent_definition_id`),
    INDEX `idx_agent_outbound_message_run` (`agent_run_id`),
    CONSTRAINT `fk_agent_outbound_message_run`
        FOREIGN KEY (`agent_run_id`)
        REFERENCES `agent_run` (`id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_agent_outbound_message_definition`
        FOREIGN KEY (`agent_definition_id`)
        REFERENCES `agent_definition` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
