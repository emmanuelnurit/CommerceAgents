SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `agent_staged_change`
    ADD COLUMN `agent_definition_id` INTEGER NULL AFTER `conversation_id`,
    ADD INDEX `idx_agent_staged_change_definition` (`agent_definition_id`),
    ADD CONSTRAINT `fk_agent_staged_change_definition`
        FOREIGN KEY (`agent_definition_id`)
        REFERENCES `agent_definition` (`id`)
        ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS = 1;
