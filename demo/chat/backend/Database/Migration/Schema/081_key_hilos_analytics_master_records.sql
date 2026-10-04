-- Migration: Key analytics records written by the master through the node journal (HIL-1156)
-- Created: 2026-10-04
-- Index: 081
--
-- The master no longer writes its facts directly. A process names each fact with a
-- random key; the second of a cause and its agent response to arrive completes
-- their link. A token alias joins a visit whichever node's file loads first.
-- Rows written before this migration retain NULL keys.

CREATE TABLE `hilos_analytics_browser_session_alias` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `old_token` VARCHAR(100) NOT NULL,
    `new_token` VARCHAR(100) NOT NULL,
    `created_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ha_browser_alias_old_token` (`old_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE `hilos_analytics_page_session`
    ADD COLUMN `session_key` BINARY(16) DEFAULT NULL AFTER `id`,
    ADD UNIQUE KEY `uk_ha_page_session_key` (`session_key`);

ALTER TABLE `hilos_analytics_user_action`
    ADD COLUMN `action_key` BINARY(16) DEFAULT NULL AFTER `id`,
    ADD UNIQUE KEY `uk_ha_user_action_key` (`action_key`);

ALTER TABLE `hilos_analytics_agent_user_action`
    ADD COLUMN `user_action_key` BINARY(16) DEFAULT NULL AFTER `user_action_id`,
    ADD KEY `idx_ha_agent_user_action_cause_key` (`user_action_key`);

ALTER TABLE `hilos_analytics_api_request`
    ADD COLUMN `request_key` BINARY(16) DEFAULT NULL AFTER `id`,
    ADD UNIQUE KEY `uk_ha_api_request_key` (`request_key`);

ALTER TABLE `hilos_analytics_api_agent_action`
    MODIFY COLUMN `api_request_id` BIGINT UNSIGNED DEFAULT NULL,
    ADD COLUMN `api_request_key` BINARY(16) DEFAULT NULL AFTER `api_request_id`,
    ADD KEY `idx_ha_api_agent_action_cause_key` (`api_request_key`);
