-- Activates the framework hilos_auth_block table (HIL-420) for demo/chat.
-- Copied from framework/backend/Database/Migration/Stub/create_hilos_auth_block.sql;
-- records the blocks the anti-abuse layer actually consummated, one row per
-- (scope, identity, action). The per-window attempt counters behind them are runtime
-- only and never reach here - a count in flight is worth less than the write it would
-- cost, while a block has to outlive a restart and be visible to every worker, which
-- is exactly what a shared table is for. UNIQUE on the triple makes a re-block an
-- update of the row already there rather than a second one beside it.

CREATE TABLE `hilos_auth_block` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scope` ENUM('ip', 'session') NOT NULL,
    `identity` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `action` VARCHAR(64) NOT NULL,
    `level` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `blocked_until` TIMESTAMP NULL DEFAULT NULL,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_auth_block_scope_identity_action` (`scope`, `identity`, `action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
