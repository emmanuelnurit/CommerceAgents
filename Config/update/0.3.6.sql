SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `agent_review_reply`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `comment_id` INTEGER NOT NULL,
    `staged_change_id` INTEGER,
    `agent_definition_id` INTEGER,
    `admin_id` INTEGER,
    `content` LONGTEXT NOT NULL,
    `created_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_agent_review_reply_comment` (`comment_id`),
    INDEX `idx_agent_review_reply_definition` (`agent_definition_id`),
    INDEX `fi_agent_review_reply_staged_change` (`staged_change_id`),
    CONSTRAINT `fk_agent_review_reply_staged_change`
        FOREIGN KEY (`staged_change_id`)
        REFERENCES `agent_staged_change` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `fk_agent_review_reply_definition`
        FOREIGN KEY (`agent_definition_id`)
        REFERENCES `agent_definition` (`id`)
        ON DELETE SET NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
