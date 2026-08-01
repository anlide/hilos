-- Migration: Create hilos_auth_block table (stub/reference)
-- Copy this SQL to project migration (e.g. 0NN_create_hilos_auth_block.sql)
--
-- Framework anti-abuse durable block table (HIL-420). One row records a
-- consummated throttle block for a (scope, identity, action) triple. The hot
-- per-window attempt counters live in runtime (RT AuthAttempts) and never touch
-- the DB; only a block that actually fired is persisted here so it survives a
-- restart and is visible to every worker.
--
-- `scope` is {ip, session}. `identity` is the throttle key: a client IP or the
-- sha256 hex of a session token (utf8mb4_bin for exact / case-sensitive match).
-- `action` is the page action name that was throttled. `level` is the ladder
-- step (0-based); `blocked_until` is when the block lifts (NULL = not currently
-- blocked, only the cooled-down level is retained). Uniqueness is per triple so
-- a re-block is an upsert, never a duplicate.

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
