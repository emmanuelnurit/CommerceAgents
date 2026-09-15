SET FOREIGN_KEY_CHECKS = 0;

-- MYO-340: automatic runs of cart_abandoned_relaunch / welcome_new_customer
-- never carry an admin_id (AgentRunQueue::enqueue() populates none for
-- trigger-fired runs), so stageCustomerEmail() must be able to persist a
-- staged change without one.
ALTER TABLE `agent_staged_change` MODIFY `admin_id` INTEGER NULL;

-- MYO-340: "Messages envoyés" panes need a content excerpt per message,
-- which agent_outbound_message did not carry until now (MYO-327/328).
ALTER TABLE `agent_outbound_message` ADD COLUMN `body_excerpt` VARCHAR(500) NULL AFTER `business_reference`;

SET FOREIGN_KEY_CHECKS = 1;
