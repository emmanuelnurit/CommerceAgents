SET FOREIGN_KEY_CHECKS = 0;

-- MYO-484: read-state table backing the notification center topbar
-- (MYO-481/483). No `read_at`/`acknowledged_at` existed anywhere before this
-- -- an ack is per-admin, keyed by the source row it acknowledges
-- (`agent_staged_change` or `agent_outbound_message`), never a 4th
-- notification-producing table of its own (see BriefUrgencyClassifier reuse
-- in NotificationCenterService). The unique index makes acking idempotent:
-- replaying the same ack twice does not duplicate a row nor error.
CREATE TABLE IF NOT EXISTS `agent_notification_ack`
(
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `admin_id` INTEGER NOT NULL,
    `source_type` VARCHAR(20) NOT NULL,
    `source_id` INTEGER NOT NULL,
    `acked_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_agent_notification_ack_admin_source` (`admin_id`, `source_type`, `source_id`)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
