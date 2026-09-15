SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `agent_definition`
    ADD COLUMN `preset_code` VARCHAR(60) NULL AFTER `consecutive_failures`;

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

SET FOREIGN_KEY_CHECKS = 1;
