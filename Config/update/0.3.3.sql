SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `agent_conversation`
    ADD COLUMN IF NOT EXISTS `proactive_prompt_count` INTEGER DEFAULT 0 NOT NULL AFTER `locale`,
    ADD COLUMN IF NOT EXISTS `proactive_last_prompted_at` TIMESTAMP NULL AFTER `proactive_prompt_count`,
    ADD COLUMN IF NOT EXISTS `proactive_dismissed` TINYINT DEFAULT 0 NOT NULL AFTER `proactive_last_prompted_at`,
    ADD COLUMN IF NOT EXISTS `proactive_scenarios` LONGTEXT NULL AFTER `proactive_dismissed`;

SET FOREIGN_KEY_CHECKS = 1;
