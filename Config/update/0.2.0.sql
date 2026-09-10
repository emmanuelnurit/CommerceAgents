SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `agent_message`
    ADD COLUMN `model` VARCHAR(120) NULL AFTER `tokens_out`,
    ADD COLUMN `cost` DECIMAL(14,8) NULL AFTER `model`;

CREATE TABLE IF NOT EXISTS `agent_model`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `provider` VARCHAR(40) NOT NULL,
    `model_id` VARCHAR(120) NOT NULL,
    `name` VARCHAR(150),
    `price_input` DECIMAL(12,6),
    `price_output` DECIMAL(12,6),
    `context_window` INTEGER,
    `enabled` TINYINT DEFAULT 1 NOT NULL,
    `source` VARCHAR(20) DEFAULT 'catalog' NOT NULL,
    `priced_at` DATE,
    `last_seen_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL,
    `updated_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_agent_model_provider_model` (`provider`, `model_id`)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
