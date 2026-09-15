SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `agent_conversation`
    ADD COLUMN `proactive_prompt_count` INTEGER DEFAULT 0 NOT NULL AFTER `locale`,
    ADD COLUMN `proactive_last_prompted_at` TIMESTAMP NULL AFTER `proactive_prompt_count`,
    ADD COLUMN `proactive_dismissed` TINYINT DEFAULT 0 NOT NULL AFTER `proactive_last_prompted_at`,
    ADD COLUMN `proactive_scenarios` LONGTEXT NULL AFTER `proactive_dismissed`;

SET FOREIGN_KEY_CHECKS = 1;
