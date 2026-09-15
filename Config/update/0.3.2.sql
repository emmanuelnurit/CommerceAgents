SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `agent_staged_change`
    ADD COLUMN IF NOT EXISTS `agent_definition_id` INTEGER NULL AFTER `conversation_id`,
    ADD INDEX IF NOT EXISTS `idx_agent_staged_change_definition` (`agent_definition_id`);

-- MYO-378: MariaDB/MySQL have no `ADD CONSTRAINT IF NOT EXISTS` for foreign
-- keys, unlike ADD COLUMN/ADD INDEX above, so the guard is done by hand via
-- information_schema inside a throwaway procedure.
DELIMITER $$
CREATE PROCEDURE `_commerceagents_0_3_2_add_fk`()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM `information_schema`.`TABLE_CONSTRAINTS`
        WHERE `CONSTRAINT_SCHEMA` = DATABASE()
          AND `TABLE_NAME` = 'agent_staged_change'
          AND `CONSTRAINT_NAME` = 'fk_agent_staged_change_definition'
    ) THEN
        ALTER TABLE `agent_staged_change`
            ADD CONSTRAINT `fk_agent_staged_change_definition`
                FOREIGN KEY (`agent_definition_id`)
                REFERENCES `agent_definition` (`id`)
                ON DELETE SET NULL;
    END IF;
END$$
DELIMITER ;
CALL `_commerceagents_0_3_2_add_fk`();
DROP PROCEDURE `_commerceagents_0_3_2_add_fk`;

SET FOREIGN_KEY_CHECKS = 1;
