-- Migration Rollback: Key the analytics sessions and remember the journal files loaded (HIL-1154)
-- Created: 2026-10-01
-- Index: 071

DROP TABLE IF EXISTS `hilos_analytics_journal_file`;

ALTER TABLE `hilos_analytics_agent_session`
    DROP INDEX `uk_ha_agent_session_key`,
    DROP COLUMN `session_key`;

ALTER TABLE `hilos_analytics_worker_session`
    DROP INDEX `uk_ha_worker_session_key`,
    DROP COLUMN `session_key`;
