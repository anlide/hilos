-- Migration: Key the analytics sessions and remember the journal files loaded (HIL-1154)
-- Created: 2026-10-01
-- Index: 071
--
-- Workers stop writing analytics themselves: each process hands its events to the
-- journal agent of its node, which appends them to files, and one writer per
-- cluster loads whole files into these tables. The process that opens a worker or
-- an agent session cannot know the row number the writer will give it, so it names
-- the session by a key it draws in memory; session_key is that key, and the writer
-- finds the row by it. Rows written before this migration keep NULL.
--
-- hilos_analytics_journal_file remembers every file the writer loaded, in the same
-- transaction as the file's rows: its unique key is what keeps a file from being
-- written twice when the confirmation to the journal agent is lost. node_id is the
-- cluster node the file came from, '' outside a cluster.

ALTER TABLE `hilos_analytics_worker_session`
    ADD COLUMN `session_key` BINARY(16) DEFAULT NULL AFTER `id`,
    ADD UNIQUE KEY `uk_ha_worker_session_key` (`session_key`);

ALTER TABLE `hilos_analytics_agent_session`
    ADD COLUMN `session_key` BINARY(16) DEFAULT NULL AFTER `id`,
    ADD UNIQUE KEY `uk_ha_agent_session_key` (`session_key`);

CREATE TABLE `hilos_analytics_journal_file` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `node_id` VARCHAR(64) NOT NULL,
    `file_name` VARCHAR(100) NOT NULL,
    `record_count` INT UNSIGNED NOT NULL,
    `loaded_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_ha_journal_file` (`node_id`, `file_name`),
    KEY `idx_ha_journal_file_loaded_ts` (`loaded_ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
