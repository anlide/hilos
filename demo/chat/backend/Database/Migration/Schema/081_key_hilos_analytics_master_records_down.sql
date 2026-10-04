-- Migration Rollback: Remove master journal keys and token aliases (HIL-1156)

DELETE FROM `hilos_analytics_api_agent_action` WHERE `api_request_id` IS NULL;

ALTER TABLE `hilos_analytics_api_agent_action`
    DROP KEY `idx_ha_api_agent_action_cause_key`,
    DROP COLUMN `api_request_key`,
    MODIFY COLUMN `api_request_id` BIGINT UNSIGNED NOT NULL;

ALTER TABLE `hilos_analytics_api_request`
    DROP KEY `uk_ha_api_request_key`,
    DROP COLUMN `request_key`;

ALTER TABLE `hilos_analytics_agent_user_action`
    DROP KEY `idx_ha_agent_user_action_cause_key`,
    DROP COLUMN `user_action_key`;

ALTER TABLE `hilos_analytics_user_action`
    DROP KEY `uk_ha_user_action_key`,
    DROP COLUMN `action_key`;

ALTER TABLE `hilos_analytics_page_session`
    DROP KEY `uk_ha_page_session_key`,
    DROP COLUMN `session_key`;

DROP TABLE `hilos_analytics_browser_session_alias`;
