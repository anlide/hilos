-- Migration: Create hilos_registration_wait
-- Created: 2026-08-16
-- Index: 007
-- Description:
--   Part of the session activation this demo took on in HIL-407, not a feature
--   of its own. The framework builds every handshake response through
--   HilosSessionHost::handshakeResponse(), which asks whether the session left a
--   registration unfinished (HIL-486) — an unconditional read of this table on
--   every handshake and every session bind. A project that hosts sessions
--   therefore carries the table whether or not it has a registration flow to
--   fill it; this demo has none, so the table simply stays empty and every
--   handshake answers "no step pending".
--
--   Copied verbatim from framework/backend/Database/Migration/Stub/. The
--   companion `hilos_registration_reservation` is deliberately NOT created: it
--   is only read once a wait row has been found, which cannot happen while
--   nothing writes one.

CREATE TABLE `hilos_registration_wait` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_token` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `identifier` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_registration_wait_session` (`session_token`),
    KEY `idx_registration_wait_identifier` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
