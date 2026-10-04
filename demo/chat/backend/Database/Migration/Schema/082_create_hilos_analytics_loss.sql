-- Migration: Count analytics events lost on a node (HIL-1157)
-- Created: 2026-10-04
-- Index: 082
--
-- A journal ceiling, an unwritable disk, restore, the record-size ceiling, and
-- loader skips each lose events. The writer loads loss rows in the transaction
-- of their journal file so the file mark keeps every count from repeating.

CREATE TABLE `hilos_analytics_loss` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `node_id` VARCHAR(64) NOT NULL,
    `reason` VARCHAR(32) NOT NULL,
    `event_count` INT UNSIGNED NOT NULL,
    `from_ts` BIGINT UNSIGNED NOT NULL,
    `to_ts` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ha_loss_from_ts` (`from_ts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
