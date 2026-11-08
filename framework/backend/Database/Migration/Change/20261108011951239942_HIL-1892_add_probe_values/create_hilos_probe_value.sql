-- Migration: Create hilos_probe_value table (stub/reference)
--
-- Synthetic cluster DB probe values belong to the writing node's set. They live
-- outside hilos_setting because the settings library owns that table whole;
-- a per-node probe must not become another changing writer of its settings.
-- node_id is a soft root: a cluster node has no persisted parent row.
CREATE TABLE `hilos_probe_value` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `node_id` VARCHAR(64) NOT NULL,
    `key` VARCHAR(100) NOT NULL,
    `value` TEXT NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
